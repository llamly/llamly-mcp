<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Auth\PermitStore;
use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Redactor;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;
use Llamly\Free\Core\ToolRegistry;

/**
 * Journaled, reversible writes for every domain adapter.
 *
 * One write: durable `pending` row (idempotency) → object mutex → `applying` marker committed with the object generation
 * (T1) → effect transaction (T2): settings gate, CAS on the locked state, permit, effect, read-back verification, journal
 * result, generation bump and audit committed together. A failure rolls T2 back; a state that escaped the transaction
 * becomes `recovery_required` with proof, never a silent success.
 *
 * Undo and redo are the same write: restore the object to a journaled state (`before` for undo, the original `after`
 * for redo), guarded by the hash the object had when the change it reverses was committed.
 */
final class Engine
{
	/** Every credential key family a journal row can carry. */
	public const CREDENTIAL_KEY_PATTERN = '/^(?:key:\d+|oauth:[a-f0-9-]{36}|admin:\d+|ability:\d+)$/';
	public const ABSENT = '0000000000000000000000000000000000000000000000000000000000000000';
	/** Width of the journal hash columns (before_hash, after_hash char(68)): "<prefix>_" + 64 hex fits with a prefix of up to 3. */
	public const HASH_COLUMN = 68;
	private const RETRYABLE = ['lock_timeout', 'journal_unavailable', 'interrupted', 'confirm_required', 'policy_changed', 'file_restore_failed', 'isolation_unavailable'];
	public const APPROVAL_TTL = 1800;

	/** How long a request waits in the human queue before it leaves the list (and its approval is no longer offered). */
	public const REQUEST_TTL = 7 * DAY_IN_SECONDS;

	/** @var array<string, Adapter> */
	private static array $adapters = [];
	private static bool $modulesMerged = false;

	/** @var array<string, array{0:Adapter,1:int}> objects touched in this request, invalidated again at shutdown */
	private static array $flushPairs = [];
	private static bool $shutdownRegistered = false;
	/** Cap of the pairs kept for the shutdown pass; reaching it flushes them early and starts a new set. */
	private const MAX_FLUSH_PAIRS = 1000;

	/** Only Llamly Free classes may provide an adapter: adapters are part of the trusted write base. */
	public static function registerAdapter(Adapter $adapter): bool
	{
		$type = $adapter->type();
		if (!preg_match('/^[a-z][a-z0-9_]{1,47}$/', $type) || isset(self::$adapters[$type]) || !self::isOwnCode($adapter)) {
			return false;
		}
		self::$adapters[$type] = $adapter;
		return true;
	}

	public static function adapter(string $type): ?Adapter
	{
		self::bootAdapters();
		return self::$adapters[$type] ?? null;
	}

	/** @return array<string, Adapter> */
	public static function adapters(): array
	{
		self::bootAdapters();
		return self::$adapters;
	}

	private static function bootAdapters(): void
	{
		if (!self::$adapters) {
			foreach (\Llamly\Free\Tools\Catalog::adapters() as $adapter) {
				self::registerAdapter($adapter);
			}
			if (!isset(self::$adapters['post'])) {
				self::registerAdapter(new Adapters\PostObject());
			}
		}
		// Add-on modules join once, after the core, when they were collected (never before plugins_loaded). Their types
		// carry the module id as prefix, so they cannot replace a core domain; ModuleRegistry already checked them.
		if (!self::$modulesMerged && \Llamly\Free\Modules\ModuleRegistry::ready()) {
			self::$modulesMerged = true;
			self::$adapters += \Llamly\Free\Modules\ModuleRegistry::adapters();
		}
	}

	/** Error for an operation whose adapter is missing: the add-on that made it is not active, or the type is unknown. */
	private static function missingAdapter(string $objectType, string $code, string $message, int $status): \WP_Error
	{
		$owner = \Llamly\Free\Modules\ModuleRegistry::ownerOfType($objectType);
		return $owner !== null && !$owner['active'] ? \Llamly\Free\Modules\ModuleRegistry::unavailableError($owner) : self::error($code, $message, $status);
	}

	private static function isOwnCode(object $adapter): bool
	{
		$class = get_class($adapter);
		if (strpos($class, 'Llamly\\Free\\') !== 0) {
			return false;
		}
		$file = (new \ReflectionClass($adapter))->getFileName();
		$root = trailingslashit(wp_normalize_path(LLAMLY_FREE_ROOT)) . 'src/Free/';
		return is_string($file) && strpos(wp_normalize_path($file), $root) === 0;
	}

	/**
	 * Restore generation. A database restore (Llamly 1.0.0, or a module through Modules\Restore) bumps it; every journal
	 * row records the generation it was written in, and changes from an earlier generation can no longer be undone even if
	 * IDs and hashes match again.
	 */
	public static function currentGeneration(): int
	{
		$row = ObjectVersionStore::get('restore_generation', 0);
		return $row ? (int) $row['version'] : 0;
	}

	/**
	 * Raise the restore generation (Modules\Restore::bumpGeneration(), module API 2). Runs right after a module replaced
	 * site data, before writes reopen; the row lives in Llamly's own table, which restores never overwrite.
	 */
	public static function bumpGeneration(): int|false
	{
		global $wpdb;
		if (!ObjectVersionStore::ensure('restore_generation', 0)) {
			return false;
		}
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET version = version + 1, updated_at = %s WHERE object_type = 'restore_generation' AND object_id = 0",
			Schema::tableNames()['object_versions'],
			gmdate('Y-m-d H:i:s')
		));
		return $updated === 1 ? self::currentGeneration() : false;
	}

	public static function absentHash(Adapter $adapter): string
	{
		return $adapter->prefix() . '_' . self::ABSENT;
	}

	/**
	 * Run one forward write.
	 *
	 * @param array{tool:string,object_id:int,expected_hash:?string,intent:array<string,mixed>,auth:array<string,mixed>,idempotency_key:string,request_hash:string,event:string,batch_id?:?string,dry_run?:bool} $request
	 */
	public static function write(Adapter $adapter, array $request): mixed
	{
		if (!MutationRunner::writeEnabled()) {
			return self::notReady();
		}
		$preflight = MutationRunner::preflight($adapter->tables());
		if (is_wp_error($preflight)) {
			return $preflight;
		}
		if (!empty($request['dry_run'])) {
			return self::dryRun($adapter, $request);
		}
		return self::run($adapter, [
			'tool' => $request['tool'],
			'object_id' => (int) $request['object_id'],
			'expected' => $request['expected_hash'] ?? self::absentHash($adapter),
			'intent' => $request['intent'],
			'target' => null,
			'inverse' => false,
			'auth' => $request['auth'],
			'key' => $request['idempotency_key'],
			'request_hash' => $request['request_hash'],
			'event' => $request['event'],
			'undo_of' => null,
			'redo_of' => null,
			'batch_id' => $request['batch_id'] ?? null,
			'confirm' => ($request['confirm'] ?? false) === true,
		]);
	}

	/** @return array<string, mixed> */
	private static function dryRun(Adapter $adapter, array $request): array
	{
		$id = (int) $request['object_id'];
		$before = $id > 0 ? $adapter->capture($id) : null;
		$current = $id > 0 ? $adapter->fingerprint($before) : self::absentHash($adapter);
		$expected = $request['expected_hash'] ?? self::absentHash($adapter);
		$risk = $adapter->impact($before, null, $request['intent']);
		$policy = self::policy($risk, $request['auth'] ?? [], $request['intent'], Settings::fresh());
		$preview = self::preview($adapter, $before, $request['intent']);
		$failure = null;
		if ($id > 0 && $before === null) {
			$failure = 'object_not_found';
		} elseif ($current === null || !hash_equals((string) $expected, (string) $current)) {
			$failure = 'stale_revision';
		}
		return [
			'dry_run' => true,
			'tool' => $request['tool'],
			'object_type' => $adapter->type(),
			'object_id' => $id,
			'current_hash' => $current,
			'plan' => self::describeIntent($request['intent']),
			'risk' => $risk,
			'changes' => $preview['changes'],
			'requires_human_approval' => $policy === 'human',
			'requires_confirmation' => $policy === 'confirm',
			'would_fail' => $failure,
		];
	}

	/** @return array<string, mixed> */
	private static function describeIntent(array $intent): array
	{
		$plan = ['operation' => (string) ($intent['op'] ?? 'update')];
		foreach (['fields', 'meta', 'terms'] as $part) {
			if (isset($intent[$part]) && is_array($intent[$part])) {
				$plan['changes'][$part] = array_keys($intent[$part]);
			}
		}
		return $plan;
	}

	/**
	 * Undo or redo one journaled change.
	 *
	 * @param array<string, mixed> $auth Admin UI context (kind=admin) or an MCP credential context.
	 */
	public static function inverse(string $operationId, string $action, array $auth, string $permit = '', bool $confirm = false): mixed
	{
		$plan = self::planInverse($operationId, $action, $auth);
		if (is_wp_error($plan)) {
			return $plan;
		}
		if (!MutationRunner::writeEnabled()) {
			return self::notReady();
		}
		$preflight = MutationRunner::preflight($plan['adapter']->tables());
		if (is_wp_error($preflight)) {
			return $preflight;
		}
		$admin = ($auth['kind'] ?? '') === 'admin';
		$keySeed = $plan['link_id'] . $plan['expected'] . ($admin ? ($permit !== '' ? $permit : wp_generate_uuid4()) : (string) ($auth['idempotency_key'] ?? wp_generate_uuid4()));
		$tool = $action . '_' . $plan['adapter']->type();
		return self::run($plan['adapter'], [
			'tool' => $tool,
			'object_id' => $plan['object_id'],
			'expected' => $plan['expected'],
			'intent' => ['op' => $action, 'of' => $plan['link_id']],
			'target' => $plan['target'],
			'inverse' => true,
			'auth' => $auth + ['permit' => $permit, 'permit_link' => $plan['link_id']],
			'key' => $action . '-' . substr(hash('sha256', $keySeed), 0, 48),
			'request_hash' => self::requestHash($tool, ['operation_id' => $plan['link_id'], 'expected_hash' => $plan['expected']]),
			'event' => $plan['adapter']->type() . ($action === 'undo' ? '_undone' : '_redone'),
			'undo_of' => $action === 'undo' ? $plan['link_id'] : null,
			'redo_of' => $action === 'redo' ? $plan['link_id'] : null,
			'batch_id' => null,
			'confirm' => $confirm,
		]);
	}

	/**
	 * What an MCP undo/redo of this plan needs under the current policy: 'none', 'confirm' or 'human'.
	 *
	 * @param array{adapter:Adapter,current:?array,target:?array} $plan
	 */
	public static function inversePolicy(array $plan, string $action, array $auth): string
	{
		$intent = ['op' => $action];
		return self::policy($plan['adapter']->impact($plan['current'], $plan['target'], $intent), $auth, $intent, Settings::fresh());
	}

	/**
	 * Preview of an undo/redo plan, in the same shape as a forward preview.
	 *
	 * @param array{adapter:Adapter,current:?array,target:?array,object_id:int} $plan
	 * @return array<string, mixed>
	 */
	public static function previewInverse(array $plan, string $action): array
	{
		return self::preview($plan['adapter'], $plan['current'], ['op' => $action], $plan['target']) + ['object_type' => $plan['adapter']->type(), 'object_id' => $plan['object_id']];
	}

	/**
	 * @return array{adapter:Adapter,object_id:int,link_id:string,expected:string,target:?array,current:?array,original:array}|\WP_Error
	 */
	public static function planInverse(string $operationId, string $action, array $auth): array|\WP_Error
	{
		$userId = (int) ($auth['user_id'] ?? 0);
		$original = self::operation($operationId);
		if ($action === 'undo') {
			if (!$original || $original['status'] !== 'committed' || $original['undo_of'] !== null || $original['redo_of'] !== null) {
				return self::error('operation_not_found', __('This completed activity item is no longer available to undo.', 'llamly'), 404);
			}
		} elseif (!$original || $original['status'] !== 'committed' || $original['undo_of'] !== null || $original['redo_of'] !== null) {
			return self::error('nothing_to_redo', __('Undo this activity item before redoing it.', 'llamly'), 409);
		}
		$adapter = self::adapter((string) $original['object_type']);
		if (!$adapter) {
			return self::missingAdapter((string) $original['object_type'], 'forbidden', __('This activity item cannot be undone through Llamly.', 'llamly'), 403);
		}
		if (self::snapshotExpired($original)) {
			return self::error('undo_expired', __('The activity snapshots have expired and this change can no longer be undone.', 'llamly'), 409);
		}
		if ((int) ($original['generation'] ?? 0) !== self::currentGeneration()) {
			return self::error('restore_generation_changed', __('The site database was restored after this change, so it can no longer be undone or redone.', 'llamly'), 409);
		}
		$before = self::decodeState((string) $original['before_data'], (string) $original['before_hash'], $adapter);
		$after = self::decodeState((string) ($original['after_data'] ?? ''), (string) ($original['after_hash'] ?? ''), $adapter);
		if ($before === false || $after === false) {
			return self::error('journal_unavailable', __('The stored activity snapshot is incomplete.', 'llamly'), 503);
		}
		$objectId = (int) $original['object_id'];
		$allowed = self::mayInverse($adapter, $original, $auth, $objectId, $before, $after);
		if (is_wp_error($allowed)) {
			return $allowed;
		}
		$undo = self::latestLinkedOperation($operationId, 'undo_of');
		if ($action === 'undo') {
			if ($undo) {
				$redo = self::latestLinkedOperation((string) $undo['id'], 'redo_of');
				if (!$redo) {
					return self::error('already_undone', __('This activity item has already been undone.', 'llamly'), 409);
				}
				return self::error('undo_cycle_closed', __('This activity item has already been redone and cannot start another undo cycle.', 'llamly'), 409);
			}
			$expected = self::currentFormHash($adapter, (string) $original['after_hash'], $after);
			$target = $before;
			$link = (string) $original['id'];
		} else {
			if (!$undo) {
				return self::error('nothing_to_redo', __('Undo this activity item before redoing it.', 'llamly'), 409);
			}
			if (self::snapshotExpired($undo)) {
				return self::error('undo_expired', __('The activity snapshots have expired and this change can no longer be redone.', 'llamly'), 409);
			}
			if (self::latestLinkedOperation((string) $undo['id'], 'redo_of')) {
				return self::error('already_redone', __('This activity item has already been redone.', 'llamly'), 409);
			}
			$undoAfter = self::decodeState((string) ($undo['after_data'] ?? ''), (string) ($undo['after_hash'] ?? ''), $adapter);
			$expected = self::currentFormHash($adapter, (string) $undo['after_hash'], $undoAfter === false ? null : $undoAfter);
			$target = $after;
			$link = (string) $undo['id'];
		}
		if (!preg_match('/^[a-z0-9]{1,4}_[a-f0-9]{64}$/', $expected)) {
			return self::error('journal_unavailable', __('The stored activity fingerprint is invalid.', 'llamly'), 503);
		}
		$current = $objectId > 0 ? $adapter->capture($objectId) : null;
		return ['adapter' => $adapter, 'object_id' => $objectId, 'link_id' => $link, 'expected' => $expected, 'target' => $target, 'current' => $current, 'original' => $original];
	}

	/** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
	private static function mayInverse(Adapter $adapter, array $original, array $auth, int $objectId, ?array $before, ?array $after): bool|\WP_Error
	{
		$userId = (int) ($auth['user_id'] ?? 0);
		if (($auth['kind'] ?? '') === 'admin') {
			if (!user_can($userId, 'manage_options') || !$adapter->canInverse($userId, $objectId, $before, $after)) {
				return self::error('forbidden', __('Your WordPress user cannot undo this change.', 'llamly'), 403);
			}
			return true;
		}
		// An MCP credential reuses the policy of the original change: its tool scope, the object capability, and either
		// ownership of the change or administrator rights. Knowing a change ID grants nothing.
		$definition = ToolRegistry::get((string) $original['tool']);
		$owner = hash_equals((string) $original['credential_key'], (string) ($auth['credential_key'] ?? ''));
		if (!$definition || !Authorization::canUseTool($auth, $definition) || (!$owner && !user_can($userId, 'manage_options'))
			|| !$adapter->canInverse($userId, $objectId, $before, $after)
		) {
			return self::error('forbidden', __('This connection cannot undo that change.', 'llamly'), 403);
		}
		return true;
	}

	/**
	 * ACT-12 force undo, administrator UI only: put the item back to the state before $operationId even though it changed
	 * since. Recorded as a new change whose "before" is the replaced state, so the force itself can be undone. Never offered
	 * over MCP and never for Gutenberg block batches, whose hash contract is not overridable.
	 *
	 * @return array{adapter:Adapter,object_id:int,link_id:string,expected:string,target:?array,current:?array,original:array}|\WP_Error
	 */
	public static function planForce(string $operationId, int $actorUserId): array|\WP_Error
	{
		$auth = self::adminContext($actorUserId);
		$original = self::operation($operationId);
		if (!$original || $original['status'] !== 'committed' || $original['undo_of'] !== null || $original['redo_of'] !== null) {
			return self::error('operation_not_found', __('This completed activity item is no longer available to undo.', 'llamly'), 404);
		}
		if ((string) $original['tool'] === 'wp_edit_blocks') {
			return self::error('force_not_allowed', __('Block batches cannot be forced back; undo the newer changes first.', 'llamly'), 409);
		}
		if (self::latestLinkedOperation($operationId, 'undo_of')) {
			return self::error('already_undone', __('This activity item has already been undone.', 'llamly'), 409);
		}
		$plan = self::planInverse($operationId, 'undo', $auth);
		if (is_wp_error($plan)) {
			return $plan;
		}
		$current = $plan['current'];
		$plan['expected'] = (string) $plan['adapter']->fingerprint($current);
		return $plan;
	}

	public static function approveForce(string $operationId, int $actorUserId): array|\WP_Error
	{
		$plan = self::planForce($operationId, $actorUserId);
		if (is_wp_error($plan)) {
			return $plan;
		}
		// Forcing always overwrites someone's newer work, so it always takes a fresh human permit.
		$permit = PermitStore::issue($actorUserId, 'force_undo_' . $plan['adapter']->type(), $plan['adapter']->type(), max(1, $plan['object_id']), $plan['link_id'], $plan['expected']);
		if (is_wp_error($permit)) {
			return $permit;
		}
		return $permit + ['required' => true, 'risk' => $plan['adapter']->impact($plan['current'], $plan['target'], ['op' => 'undo']) + ['overwrites_newer_changes' => true]];
	}

	public static function forceUndo(string $operationId, int $actorUserId, string $permit): mixed
	{
		$plan = self::planForce($operationId, $actorUserId);
		if (is_wp_error($plan)) {
			return $plan;
		}
		if ($permit === '') {
			return self::error('confirmation_required', __('Confirm the forced undo in Activity first.', 'llamly'), 409);
		}
		if (!MutationRunner::writeEnabled()) {
			return self::notReady();
		}
		$preflight = MutationRunner::preflight($plan['adapter']->tables());
		if (is_wp_error($preflight)) {
			return $preflight;
		}
		$adapter = $plan['adapter'];
		$tool = 'force_undo_' . $adapter->type();
		return self::run($adapter, [
			'tool' => $tool,
			'object_id' => $plan['object_id'],
			'expected' => $plan['expected'],
			'intent' => ['op' => 'undo', 'of' => $plan['link_id'], 'force' => true],
			'target' => $plan['target'],
			'inverse' => true,
			'auth' => self::adminContext($actorUserId) + ['permit' => $permit, 'permit_link' => $plan['link_id']],
			'key' => 'force-' . substr(hash('sha256', $plan['link_id'] . $plan['expected'] . $permit), 0, 48),
			'request_hash' => self::requestHash($tool, ['operation_id' => $plan['link_id'], 'expected_hash' => $plan['expected']]),
			'event' => $adapter->type() . '_force_undone',
			'undo_of' => null,
			'redo_of' => null,
			'force_of' => $plan['link_id'],
			'batch_id' => null,
			'force' => true,
		]);
	}

	/** Issue a single-use administrator approval for an Activity undo/redo, bound to the current expected state. */
	public static function approveInverse(string $operationId, string $action, int $actorUserId): array|\WP_Error
	{
		$auth = self::adminContext($actorUserId);
		$plan = self::planInverse($operationId, $action, $auth);
		if (is_wp_error($plan)) {
			return $plan;
		}
		$risk = $plan['adapter']->impact($plan['current'], $plan['target'], ['op' => $action]);
		if (!self::needsAdminConfirmation($risk)) {
			return ['required' => false, 'permit' => '', 'expires_at' => null, 'risk' => $risk];
		}
		$permit = PermitStore::issue($actorUserId, $action . '_' . $plan['adapter']->type(), $plan['adapter']->type(), max(1, $plan['object_id']), $plan['link_id'], $plan['expected']);
		if (is_wp_error($permit)) {
			return $permit;
		}
		return $permit + ['required' => true, 'risk' => $risk];
	}

	/** An administrator approves a change an agent requested; the agent retries with the same idempotency key. */
	public static function approveOperation(string $operationId, int $actorUserId): array|\WP_Error
	{
		$operation = self::operation($operationId);
		if (!$operation || $operation['status'] !== 'aborted' || $operation['error_code'] !== 'awaiting_approval') {
			return self::error('operation_not_found', __('This request is not waiting for approval.', 'llamly'), 404);
		}
		if (self::requestExpired($operation)) {
			self::expireRequest($operation);
			return self::expiredError();
		}
		$adapter = self::adapter((string) $operation['object_type']);
		$objectId = (int) $operation['object_id'];
		if (!$adapter) {
			return self::missingAdapter((string) $operation['object_type'], 'forbidden', __('Only an administrator who can change this item can approve the request.', 'llamly'), 403);
		}
		if (!user_can($actorUserId, 'manage_options') || ($objectId > 0 && !$adapter->canInverse($actorUserId, $objectId, null, null))) {
			return self::error('forbidden', __('Only an administrator who can change this item can approve the request.', 'llamly'), 403);
		}
		$approval = PermitStore::approveOperation($actorUserId, $operation, self::APPROVAL_TTL);
		if (is_wp_error($approval)) {
			return $approval;
		}
		return ['operation_id' => $operationId, 'approved' => true, 'expires_at' => $approval['expires_at']];
	}

	public static function rejectOperation(string $operationId, int $actorUserId): array|\WP_Error
	{
		global $wpdb;
		if (!user_can($actorUserId, 'manage_options')) {
			return self::error('forbidden', __('Only an administrator can reject this request.', 'llamly'), 403);
		}
		$updated = $wpdb->update(Schema::tableNames()['operations'], ['error_code' => 'approval_rejected', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $operationId, 'status' => 'aborted', 'error_code' => 'awaiting_approval'], ['%s', '%s'], ['%s', '%s', '%s']);
		if ($updated !== 1) {
			return self::error('operation_not_found', __('This request is not waiting for approval.', 'llamly'), 404);
		}
		AuditLog::append('approval_rejected', ['operation_id' => $operationId], $actorUserId, null, 'admin:' . $actorUserId);
		return ['operation_id' => $operationId, 'rejected' => true];
	}

	/**
	 * Administrator UI rule (unchanged by D1): the Activity tab always asks its human for a permit on public and
	 * sensitive changes, and on destructive ones under safe mode.
	 *
	 * @param array{destructive:bool,public:bool,sensitive:bool} $risk
	 */
	public static function needsAdminConfirmation(array $risk): bool
	{
		if (!empty($risk['public']) || !empty($risk['sensitive'])) {
			return true;
		}
		$settings = Settings::fresh();
		return !empty($risk['destructive']) && (empty($settings['_valid']) || !empty($settings['safe_mode']));
	}

	/**
	 * Approval policy of one write (decision 28). 'none' runs, 'confirm' needs the agent's confirm: true (a UX brake the
	 * agent controls, never a security boundary) and 'human' needs an administrator permit. confirm never replaces 'human'.
	 * Scope, capabilities and the journal apply in every case and are checked elsewhere.
	 *
	 * @param array{destructive?:bool,public?:bool,sensitive?:bool,irreversible?:bool} $risk
	 * @param array<string, mixed> $auth
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $settings Settings::fresh() result.
	 * @return 'none'|'confirm'|'human'
	 */
	public static function policy(array $risk, array $auth, array $intent, array $settings): string
	{
		// 0. Administrator UI: modal and permit as before ('human' here means the permit of that modal).
		if (($auth['kind'] ?? '') === 'admin') {
			return self::needsAdminConfirmation($risk) ? 'human' : 'none';
		}
		// 1. Unreadable or invalid settings fail closed.
		if (empty($settings['_valid'])) {
			return 'human';
		}
		// 2. Optional human queue for publishing, users and settings.
		if (!empty($settings['require_approval']) && (!empty($risk['public']) || !empty($risk['sensitive']))) {
			return 'human';
		}
		// 3. Safe mode: destructive, irreversible and MCP undo need the agent's explicit confirmation.
		if (!empty($settings['safe_mode']) && (!empty($risk['destructive']) || !empty($risk['irreversible']) || ($intent['op'] ?? '') === 'undo')) {
			return 'confirm';
		}
		return 'none';
	}

	private const PREVIEW_VALUE_LIMIT = 2000;
	private const PREVIEW_MAX_CHANGES = 30;

	/**
	 * What a change would do, for the confirmation error, the dry run and the approval card. Values are redacted and
	 * cut at 2,000 characters; fields whose name looks like a secret are never shown.
	 *
	 * @param array<string, mixed>|null $before Current state (null for a create).
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed>|null $target Target state of an undo/redo (null removes the item).
	 * @return array{plan:array<string,mixed>,risk:array<string,mixed>,external_effects:list<string>,current_hash:?string,changes:list<array{field:string,before:string,after:string,truncated?:bool,after_kind?:string}>,changes_truncated?:bool}
	 */
	public static function preview(Adapter $adapter, ?array $before, array $intent, ?array $target = null): array
	{
		$risk = $adapter->impact($before, $target, $intent);
		$op = (string) ($intent['op'] ?? '');
		$inverse = in_array($op, ['undo', 'redo'], true);
		$changes = [];
		$sensitiveItem = Redactor::isSensitiveKey((string) ($intent['name'] ?? ''));
		if ($inverse) {
			if ($target === null) {
				$changes[] = ['field' => 'item', 'before' => self::previewText($before === null ? '' : $adapter->label($before), false), 'after' => $op === 'undo' ? '(removed; wp_redo_change restores it)' : '(removed)', 'after_kind' => 'removed'];
			} else {
				$left = self::flattenState($before ?? []);
				$right = self::flattenState($target);
				foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $field) {
					$was = $left[$field] ?? null;
					$will = $right[$field] ?? null;
					if ($was !== $will) {
						$changes[] = self::previewChange((string) $field, $was, $will, false);
					}
				}
			}
		} elseif ($op === 'delete' || $op === 'trash') {
			$changes[] = ['field' => 'item', 'before' => self::previewText($before === null ? '' : $adapter->label($before), false), 'after' => $op === 'trash' ? '(moved to the trash)' : '(deleted)', 'after_kind' => $op === 'trash' ? 'trashed' : 'deleted'];
		} else {
			foreach ($intent as $key => $value) {
				if (in_array($key, ['op', 'of', 'args_hash', 'warnings'], true)) {
					continue;
				}
				if (is_array($value) && self::isMap($value) && in_array($key, ['fields', 'meta', 'terms'], true)) {
					foreach ($value as $name => $new) {
						$was = is_array($before[$key] ?? null) ? ($before[$key][$name] ?? null) : null;
						if ($key === 'meta' && is_array($was) && count($was) === 1 && is_scalar(reset($was))) {
							// A captured meta key holds a list of values; one value is shown as itself.
							$was = reset($was);
						}
						if (!(is_scalar($was) && is_scalar($new) && (string) $was === (string) $new)) {
							$changes[] = self::previewChange($key . '.' . $name, $was, $new, false);
						}
					}
				} else {
					$was = $before[$key] ?? ($key === 'value' ? ($before['raw_value'] ?? null) : null);
					if (!(is_scalar($was) && is_scalar($value) && (string) $was === (string) $value)) {
						$changes[] = self::previewChange((string) $key, $was, $value, $sensitiveItem && $key === 'value');
					}
				}
			}
		}
		$out = [
			'plan' => self::describeIntent($intent),
			'risk' => $risk,
			'external_effects' => array_values(array_map('strval', (array) ($risk['external_effects'] ?? []))),
			'current_hash' => $before === null ? self::absentHash($adapter) : $adapter->fingerprint($before),
			'changes' => array_slice($changes, 0, self::PREVIEW_MAX_CHANGES),
		];
		if (count($changes) > self::PREVIEW_MAX_CHANGES) {
			$out['changes_truncated'] = true;
		}
		return $out;
	}

	/**
	 * Preview of a request that is waiting for approval, built from what the object is now and what the agent asked for.
	 * Null when the object type is unknown or an undo/redo plan can no longer be built.
	 *
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>|null
	 */
	public static function previewPending(string $objectType, int $objectId, array $intent): ?array
	{
		$adapter = self::adapter($objectType);
		if (!$adapter) {
			return null;
		}
		$op = (string) ($intent['op'] ?? '');
		if ($op === 'undo' || $op === 'redo') {
			$of = is_string($intent['of'] ?? null) ? $intent['of'] : '';
			$original = $of !== '' && $op === 'redo' ? self::operation($of) : null;
			$source = $op === 'redo' ? (string) ($original['undo_of'] ?? '') : $of;
			if ($source === '') {
				return null;
			}
			$plan = self::planInverse($source, $op, self::adminContext(get_current_user_id()));
			return is_wp_error($plan) ? null : self::previewInverse($plan, $op);
		}
		$before = $objectId > 0 ? $adapter->capture($objectId) : null;
		return self::preview($adapter, $before, $intent);
	}

	/** @return array{field:string,before:string,after:string} */
	private static function previewChange(string $field, mixed $before, mixed $after, bool $hide): array
	{
		$leaf = (string) (strrchr('.' . $field, '.') ?: $field);
		$hide = $hide || Redactor::isSensitiveKey(ltrim($leaf, '.'));
		$cut = false;
		$change = ['field' => $field, 'before' => self::previewText($before, $hide, $cut), 'after' => self::previewText($after, $hide, $cut)];
		// The shown text is only the start of the value: the reader must be told the diff is incomplete.
		return $cut ? $change + ['truncated' => true] : $change;
	}

	private static function previewText(mixed $value, bool $hide, bool &$cut = false): string
	{
		if ($hide) {
			return '[redacted]';
		}
		if ($value === null) {
			return '';
		}
		$text = is_scalar($value) ? (string) $value : (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		if (strlen($text) > self::PREVIEW_VALUE_LIMIT * 4) {
			$cut = true;
			$text = substr($text, 0, self::PREVIEW_VALUE_LIMIT * 4);
		}
		$text = Redactor::text($text);
		if (mb_strlen($text) > self::PREVIEW_VALUE_LIMIT) {
			$cut = true;
			return mb_substr($text, 0, self::PREVIEW_VALUE_LIMIT) . '…';
		}
		return $text;
	}

	/** A non-empty array with string keys (the list check needs PHP 8.1). @param array<mixed> $value */
	private static function isMap(array $value): bool
	{
		return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
	}

	/**
	 * Two levels of a captured state as "section.key" => value, so an undo preview can list what differs.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function flattenState(array $state): array
	{
		$flat = [];
		foreach ($state as $section => $value) {
			if (is_array($value) && self::isMap($value)) {
				foreach ($value as $key => $inner) {
					$flat[$section . '.' . $key] = $inner;
				}
			} else {
				$flat[(string) $section] = $value;
			}
		}
		return $flat;
	}

	/** The error an agent gets when safe mode needs its confirmation; nothing was changed. @param array<string, mixed> $preview */
	public static function confirmationError(array $preview): \WP_Error
	{
		return new \WP_Error('confirmation_required', __('Safe mode is on and this change needs explicit confirmation. Nothing was changed. Ask the user whether to go ahead; only if they agree, repeat the same call with the same idempotency_key and confirm: true.', 'llamly'), [
			'status' => 409,
			'requires' => 'confirm',
			'requires_confirmation' => true,
			'risk' => $preview['risk'] ?? [],
			'preview' => $preview,
		]);
	}

	/** The error an agent gets while a human has to approve; repeating the call returns it again until approved. @param array<string, mixed> $risk */
	private static function awaitingApprovalError(string $operationId, array $risk, array $preview): \WP_Error
	{
		return new \WP_Error('confirmation_required', __('A site administrator must approve this change in Llamly › Activity. After approval, repeat the same call with the same idempotency_key.', 'llamly'), [
			'status' => 409,
			'requires' => 'human',
			'approval_id' => $operationId,
			'risk' => $risk,
			'preview' => $preview,
		]);
	}

	/** @param array<string, mixed> $operation */
	private static function requestExpired(array $operation): bool
	{
		$updated = strtotime((string) ($operation['updated_at'] ?? '') . ' UTC');
		return $updated !== false && $updated < time() - self::REQUEST_TTL;
	}

	/** An expired request ends for good; it is never reopened. @param array<string, mixed> $operation */
	private static function expireRequest(array $operation): void
	{
		global $wpdb;
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET error_code = 'approval_expired' WHERE id = %s AND status = 'aborted' AND error_code = 'awaiting_approval'",
			Schema::tableNames()['operations'],
			(string) $operation['id']
		));
		if ($updated === 1) {
			AuditLog::append('approval_expired', ['operation_id' => (string) $operation['id'], 'tool' => (string) ($operation['tool'] ?? '')], null, null, null);
		}
	}

	private static function expiredError(): \WP_Error
	{
		return self::error('approval_expired', __('This request waited too long for approval and expired. Send it again with a new idempotency_key.', 'llamly'), 409);
	}

	private static function supersededError(): \WP_Error
	{
		return self::error('approval_superseded', __('The approval policy changed; send the request again with a new idempotency_key.', 'llamly'), 409);
	}

	/**
	 * A request that stopped for human approval is only resumed while the policy still asks a human and its permit is
	 * valid. Under any other policy it is cancelled for good: a pending request never runs under rules it did not ask for.
	 *
	 * @param array<string, mixed> $operation
	 * @param array<string, mixed> $w
	 * @return \WP_Error|null WP_Error to answer with now; null once the request was reopened for the normal flow.
	 */
	private static function resumeAwaitingApproval(Adapter $adapter, array $operation, array $w, int $objectId): ?\WP_Error
	{
		global $wpdb;
		$operationId = (string) $operation['id'];
		if (self::requestExpired($operation)) {
			self::expireRequest($operation);
			return self::expiredError();
		}
		$type = $adapter->type();
		$before = $objectId < 1 ? null : $adapter->capture($objectId);
		$hash = $objectId < 1 ? self::absentHash($adapter) : $adapter->fingerprint($before);
		$risk = $adapter->impact($before, $w['inverse'] ? $w['target'] : null, $w['intent']);
		$policy = self::policy($risk, $w['auth'], $w['intent'], Settings::fresh());
		$table = Schema::tableNames()['operations'];
		if ($policy !== 'human') {
			$cancelled = $wpdb->query($wpdb->prepare(
				"UPDATE %i SET error_code = 'approval_superseded', updated_at = %s WHERE id = %s AND status = 'aborted' AND error_code = 'awaiting_approval'",
				$table,
				gmdate('Y-m-d H:i:s'),
				$operationId
			));
			if ($cancelled === 1) {
				AuditLog::append('approval_superseded', ['operation_id' => $operationId, 'tool' => $w['tool'], 'object_type' => $type, 'object_id' => $objectId, 'reason' => 'policy_changed'], (int) $w['auth']['user_id'], isset($w['auth']['credential_id']) ? (int) $w['auth']['credential_id'] : null, (string) $w['auth']['credential_key']);
			}
			return self::supersededError();
		}
		// A changed object is answered with stale_revision by the normal flow; an unchanged one needs its permit first.
		if ($hash !== null && hash_equals((string) $w['expected'], (string) $hash) && !PermitStore::hasApproval($operationId, (string) $w['auth']['credential_key'], $hash)) {
			return self::awaitingApprovalError($operationId, $risk, self::preview($adapter, $before, $w['intent'], $w['inverse'] ? $w['target'] : null));
		}
		$reopened = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET status = 'pending', error_code = NULL, updated_at = %s WHERE id = %s AND status = 'aborted' AND error_code = 'awaiting_approval'",
			$table,
			gmdate('Y-m-d H:i:s'),
			$operationId
		));
		if ($reopened !== 1) {
			return self::error('operation_in_progress', __('This operation changed while it was being retried.', 'llamly'), 409);
		}
		return null;
	}

	/**
	 * The shared write path.
	 *
	 * @param array{tool:string,object_id:int,expected:string,intent:array,target:?array,inverse:bool,auth:array,key:string,request_hash:string,event:string,undo_of:?string,redo_of:?string,batch_id:?string} $w
	 */
	private static function run(Adapter $adapter, array $w): mixed
	{
		global $wpdb;
		$type = $adapter->type();
		$objectId = $w['object_id'];
		$isCreate = $objectId < 1;
		$op = self::insertOperation($adapter, $w);
		if (is_wp_error($op)) {
			return $op;
		}
		if (isset($op['result'])) {
			return $op['result'];
		}
		$operationId = (string) $op['id'];
		$lockName = $isCreate
			? 'llamly:' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix . '|create|' . $operationId), 0, 40)
			: ObjectMutex::name($type, $objectId);
		if (!ObjectMutex::acquireName($lockName, 5)) {
			self::setAborted($operationId, 'lock_timeout', $type, $objectId);
			return self::error('operation_in_progress', __('Another Llamly or editor save is in progress. Retry with the same idempotency key.', 'llamly'), 409);
		}
		$connId = ObjectMutex::connectionId();
		try {
			$operation = self::operation($operationId);
			if (!$operation) {
				return self::error('journal_unavailable', __('The durable operation row could not be read.', 'llamly'), 503);
			}
			if ($operation['status'] === 'committed') {
				return self::operationResult($adapter, $operation);
			}
			if ($operation['status'] === 'recovery_required') {
				return self::error('recovery_required', __('An administrator must review the pending recovery before another Llamly change.', 'llamly'), 409);
			}
			if ($operation['status'] === 'applying') {
				$recovered = $isCreate ? self::reconcileCreate($adapter, $operation, $connId) : self::reconcileExisting($adapter, $operation, $connId);
				if (is_wp_error($recovered)) {
					return $recovered;
				}
				$operation = self::operation($operationId) ?: $operation;
				if ($operation['status'] === 'committed') {
					return self::operationResult($adapter, $operation);
				}
			}
			if (!in_array($operation['status'], ['pending', 'aborted'], true)) {
				return self::error('operation_in_progress', __('This operation is still being recovered. Retry with the same idempotency key.', 'llamly'), 409);
			}
			if ($operation['status'] === 'aborted' && (string) $operation['error_code'] === 'awaiting_approval') {
				$resumed = self::resumeAwaitingApproval($adapter, $operation, $w, $objectId);
				if ($resumed !== null) {
					return $resumed;
				}
			} elseif ($operation['status'] === 'aborted') {
				if (!in_array((string) $operation['error_code'], self::RETRYABLE, true)) {
					return (string) $operation['error_code'] === 'approval_superseded'
						? self::supersededError()
						: ((string) $operation['error_code'] === 'approval_expired' ? self::expiredError() : self::error((string) ($operation['error_code'] ?: 'mutation_failed'), __('The same request was already rejected and will not be repeated.', 'llamly'), 409));
				}
				$reopened = $wpdb->query($wpdb->prepare(
					"UPDATE %i SET status = 'pending', error_code = NULL, updated_at = %s WHERE id = %s AND status = 'aborted' AND error_code IN ('lock_timeout','journal_unavailable','interrupted','confirm_required','policy_changed','file_restore_failed','isolation_unavailable')",
					Schema::tableNames()['operations'],
					gmdate('Y-m-d H:i:s'),
					$operationId
				));
				if ($reopened !== 1) {
					return self::error('operation_in_progress', __('This operation changed while it was being retried.', 'llamly'), 409);
				}
			}

			// Capability is re-checked under the object lock: a role or scope change after the request was planned wins.
			$authorized = self::authorizeUnderLock($adapter, $w, $objectId);
			if (is_wp_error($authorized)) {
				self::setAborted($operationId, $authorized->get_error_code(), $type, $objectId);
				return $authorized;
			}

			if (!$isCreate) {
				$blocked = self::clearPendingMarker($adapter, $operationId, $objectId, $connId);
				if (is_wp_error($blocked)) {
					return $blocked;
				}
			}

			$before = $isCreate ? null : $adapter->capture($objectId);
			$currentHash = $isCreate ? self::absentHash($adapter) : $adapter->fingerprint($before);
			if (!$isCreate && $currentHash === null) {
				self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
				return self::error('journal_unavailable', __('The current state could not be read from the database.', 'llamly'), 503);
			}
			if (!$isCreate && $before === null && !$w['inverse']) {
				self::setAborted($operationId, 'object_not_found', $type, $objectId);
				return self::error('object_not_found', __('The requested item was not found.', 'llamly'), 404);
			}
			if (!hash_equals($w['expected'], (string) $currentHash)) {
				self::setAborted($operationId, 'stale_revision', $type, $objectId);
				return self::error('stale_revision', $w['inverse']
					? __('The item changed after this activity entry. Undo the more recent changes first.', 'llamly')
					: __('The item changed after the caller read it. Read it again before updating.', 'llamly'), 409);
			}
			$risk = $adapter->impact($before, $w['inverse'] ? $w['target'] : null, $w['intent']);
			self::phase('before_policy', $operationId);
			$policySettings = Settings::fresh();
			$policy = self::policy($risk, $w['auth'], $w['intent'], $policySettings);
			// The settings the policy was read from; T2 re-reads them under a share lock and refuses to run on a different basis.
			$w['policy_basis'] = [(int) ($policySettings['revision'] ?? 0), !empty($policySettings['safe_mode']), !empty($policySettings['require_approval'])];
			self::phase('after_policy', $operationId);
			$approval = !empty($w['force']) || $policy === 'human';
			$admin = ($w['auth']['kind'] ?? '') === 'admin';
			if ($policy === 'confirm' && empty($w['confirm'])) {
				self::setAborted($operationId, 'confirm_required', $type, $objectId);
				return self::confirmationError(self::preview($adapter, $before, $w['intent'], $w['inverse'] ? $w['target'] : null));
			}
			if ($approval && !$admin && !PermitStore::hasApproval($operationId, (string) $w['auth']['credential_key'], $currentHash)) {
				self::setAborted($operationId, 'awaiting_approval', $type, $objectId);
				self::storeLabel($operationId, self::sourceLabel($adapter, $before));
				AuditLog::append('approval_requested', ['operation_id' => $operationId, 'tool' => $w['tool'], 'object_type' => $type, 'object_id' => $objectId, 'risk' => $risk], (int) $w['auth']['user_id'], isset($w['auth']['credential_id']) ? (int) $w['auth']['credential_id'] : null, (string) $w['auth']['credential_key']);
				return self::awaitingApprovalError($operationId, $risk, self::preview($adapter, $before, $w['intent'], $w['inverse'] ? $w['target'] : null));
			}

			return self::applyT1T2($adapter, $operationId, $objectId, $currentHash, $before, $w, $risk, $approval, $connId, $lockName);
		} finally {
			ObjectMutex::releaseName($lockName);
		}
	}

	private static function authorizeUnderLock(Adapter $adapter, array $w, int $objectId): bool|\WP_Error
	{
		$auth = $w['auth'];
		$userId = (int) ($auth['user_id'] ?? 0);
		if ($w['inverse']) {
			$original = self::operation((string) ($w['undo_of'] ?? $w['redo_of'] ?? $w['force_of'] ?? ''));
			if (($w['redo_of'] ?? null) !== null) {
				$undo = $original;
				$original = $undo ? self::operation((string) $undo['undo_of']) : null;
			}
			if (!$original) {
				return self::error('operation_not_found', __('The activity item being reversed was not found.', 'llamly'), 404);
			}
			$adapterOriginal = self::adapter((string) $original['object_type']) ?? $adapter;
			$before = self::decodeState((string) $original['before_data'], (string) $original['before_hash'], $adapterOriginal);
			$after = self::decodeState((string) ($original['after_data'] ?? ''), (string) ($original['after_hash'] ?? ''), $adapterOriginal);
			return self::mayInverse($adapter, $original, $auth, $objectId, $before === false ? null : $before, $after === false ? null : $after);
		}
		$definition = ToolRegistry::get($w['tool']);
		if (!$definition) {
			return self::error('tool_not_found', __('The requested tool is not registered.', 'llamly'), 404);
		}
		return Authorization::requireTool($auth, $definition, $objectId > 0 ? $objectId : null);
	}

	/** A pending marker left by another operation is reconciled first; anything unprovable keeps the object blocked. */
	private static function clearPendingMarker(Adapter $adapter, string $operationId, int $objectId, ?int $connId): bool|\WP_Error
	{
		$type = $adapter->type();
		if (ObjectVersionStore::get($type, $objectId) === null && !ObjectVersionStore::ensure($type, $objectId)) {
			self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
			return self::error('journal_unavailable', __('The object generation row could not be created.', 'llamly'), 503);
		}
		$version = ObjectVersionStore::get($type, $objectId);
		if (!$version || !$version['pending_op'] || $version['pending_op'] === $operationId) {
			return true;
		}
		$pending = self::operation($version['pending_op']);
		if (!$pending || $pending['status'] !== 'applying') {
			if ($pending && $pending['status'] === 'recovery_required') {
				self::setAborted($operationId, 'recovery_required', $type, $objectId);
			} else {
				self::markRecoveryRequired(['id' => $operationId], ['reason' => 'orphan_pending_operation', 'pending_op' => $version['pending_op'], 'pending_status' => $pending['status'] ?? null]);
			}
			return self::error('recovery_required', __('An incomplete operation blocks this item until an administrator reviews it.', 'llamly'), 409);
		}
		$pendingAdapter = self::adapter((string) $pending['object_type']) ?? $adapter;
		$recovered = self::reconcileExisting($pendingAdapter, $pending, $connId);
		if (is_wp_error($recovered)) {
			self::setAborted($operationId, $recovered->get_error_code(), $type, $objectId);
			return $recovered;
		}
		$version = ObjectVersionStore::get($type, $objectId);
		if ($version && $version['pending_op']) {
			self::setAborted($operationId, 'recovery_required', $type, $objectId);
			return self::error('recovery_required', __('An incomplete operation blocks this item until an administrator reviews it.', 'llamly'), 409);
		}
		return true;
	}

	/**
	 * Runs T1 and T2 with the session on REPEATABLE READ and restores the host's level on every exit (success, ROLLBACK,
	 * exception or early return).
	 *
	 * @param array<string, mixed>|null $before
	 */
	private static function applyT1T2(Adapter $adapter, string $operationId, int $objectId, string $beforeHash, ?array $before, array $w, array $risk, bool $approval, ?int $connId, string $lockName): mixed
	{
		$isolation = MutationRunner::enterRepeatableRead();
		if (is_wp_error($isolation)) {
			self::setAborted($operationId, $isolation->get_error_code(), $adapter->type(), $objectId);
			return $isolation;
		}
		try {
			return self::applyTransactions($adapter, $operationId, $objectId, $beforeHash, $before, $w, $risk, $approval, $connId, $lockName);
		} finally {
			MutationRunner::restoreIsolation($isolation);
		}
	}

	/** @param array<string, mixed>|null $before */
	private static function applyTransactions(Adapter $adapter, string $operationId, int $objectId, string $beforeHash, ?array $before, array $w, array $risk, bool $approval, ?int $connId, string $lockName): mixed
	{
		global $wpdb;
		$type = $adapter->type();
		$isCreate = $objectId < 1;
		$beforeJson = $before === null ? '{}' : wp_json_encode($before);
		$intentJson = wp_json_encode($w['inverse'] ? ['op' => $w['intent']['op'], 'of' => $w['intent']['of'], 'target' => $w['target']] : $w['intent']);
		if (!is_string($beforeJson) || !is_string($intentJson)) {
			self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
			return self::error('journal_unavailable', __('The snapshot could not be serialized to the journal.', 'llamly'), 503);
		}
		if (strlen($beforeJson) + strlen($intentJson) > 16 * MB_IN_BYTES) {
			self::setAborted($operationId, 'state_too_large', $type, $objectId);
			return self::error('state_too_large', __('This change is too large to keep a reversible snapshot, so it was not applied.', 'llamly'), 413);
		}
		$operationTable = Schema::tableNames()['operations'];

		// T1: durable applying marker (and object generation marker for an existing object).
		if (!self::beginTransaction()) {
			self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
			return self::error('journal_unavailable', __('The durable operation transaction could not start.', 'llamly'), 503);
		}
		if (!$isCreate) {
			$version = ObjectVersionStore::get($type, $objectId, true);
			if (!$version || ($version['pending_op'] && $version['pending_op'] !== $operationId)) {
				$wpdb->query('ROLLBACK');
				self::setAborted($operationId, 'operation_in_progress', $type, $objectId);
				return self::error('operation_in_progress', __('Another operation is pending for this item.', 'llamly'), 409);
			}
			$lockedHash = $adapter->fingerprint($adapter->capture($objectId, true));
			if ($lockedHash === null || !hash_equals($beforeHash, $lockedHash)) {
				$wpdb->query('ROLLBACK');
				self::setAborted($operationId, 'stale_revision', $type, $objectId);
				return self::error('stale_revision', __('The item changed while the operation was being prepared.', 'llamly'), 409);
			}
		}
		$updated = $wpdb->update($operationTable, [
			'status' => 'applying',
			'before_data' => $beforeJson,
			'before_hash' => $beforeHash,
			'intended_data' => $intentJson,
			'lock_name' => $lockName,
			'conn_id' => $connId,
			'error_code' => null,
			// An operation retried after a database restore belongs to the generation it is applied in.
			'generation' => self::currentGeneration(),
			'updated_at' => gmdate('Y-m-d H:i:s'),
		], ['id' => $operationId, 'status' => 'pending'], ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s'], ['%s', '%s']);
		$versionUpdated = $isCreate ? 1 : $wpdb->update(Schema::tableNames()['object_versions'], [
			'pending_op' => $operationId,
			'updated_at' => gmdate('Y-m-d H:i:s'),
		], ['object_type' => $type, 'object_id' => $objectId, 'pending_op' => null], ['%s', '%s'], ['%s', '%d', '%s']);
		if ($updated !== 1 || $versionUpdated !== 1 || !ObjectMutex::ownsName($lockName) || $connId === null || ObjectMutex::connectionId() !== $connId) {
			$wpdb->query('ROLLBACK');
			self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
			return self::error('journal_unavailable', __('The applying marker or object generation could not be committed.', 'llamly'), 503);
		}
		$wpdb->query('COMMIT');
		if ($wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			self::setAborted($operationId, 'journal_unavailable', $type, $objectId);
			return self::error('journal_unavailable', __('The applying marker was not durably committed.', 'llamly'), 503);
		}

		// T2: the effect, its verification, the journal result, the generation bump and the audit commit together.
		$previousCacheState = wp_suspend_cache_addition(true);
		$committed = false;
		$resultId = $objectId;
		// autocommit=0: if the database rolls T2 back on its own (deadlock, lock wait), later statements open a new
		// implicit transaction instead of committing one by one; the nonce written first proves T2 is still alive.
		$wpdb->query('SET autocommit = 0');
		$nonce = wp_json_encode(['txn' => wp_generate_uuid4()]);
		try {
			self::beginTransactionOrFail();
			$alive = $wpdb->update($operationTable, ['result_data' => $nonce], ['id' => $operationId, 'status' => 'applying'], ['%s'], ['%s', '%s']);
			if ($alive !== 1) {
				throw new MutationFailure('journal_unavailable', esc_html__('The operation transaction marker could not be written.', 'llamly'), 503);
			}
			$gate = self::lockedWriteGate($w['policy_basis'] ?? null);
			if (is_wp_error($gate)) {
				$wpdb->query('ROLLBACK');
				self::setAborted($operationId, $gate->get_error_code(), $type, $objectId);
				return $gate;
			}
			if (!$isCreate) {
				$actualHash = $adapter->fingerprint($adapter->capture($objectId, true));
				if ($actualHash === null || !hash_equals($beforeHash, $actualHash)) {
					$wpdb->query('ROLLBACK');
					self::setAborted($operationId, 'stale_revision', $type, $objectId);
					return self::error('stale_revision', __('The item changed before the Llamly update began. No Llamly changes were applied.', 'llamly'), 409);
				}
			}
			if ($approval) {
				$auth = $w['auth'];
				$consumed = ($auth['kind'] ?? '') === 'admin'
					? (string) ($auth['permit'] ?? '') !== '' && PermitStore::consume((string) $auth['permit'], (int) $auth['user_id'], $w['tool'], $type, max(1, $objectId), (string) ($auth['permit_link'] ?? ''), $beforeHash)
					: PermitStore::consumeApproval($operationId, (string) $auth['credential_key'], $beforeHash);
				if (!$consumed) {
					$wpdb->query('ROLLBACK');
					self::setAborted($operationId, 'confirmation_required', $type, $objectId);
					return self::error('confirmation_required', __('This change needs a fresh administrator approval. Confirm it again in Activity.', 'llamly'), 409);
				}
			}
			self::phase('before_effect', $operationId);
			// Meta that hooks derived in the original operation is neither restored nor compared by its undo/redo.
			$inheritedDerived = $w['inverse'] ? self::derivedMetaOf((string) ($w['intent']['of'] ?? '')) : [];
			$restoreTarget = $w['target'];
			if ($w['inverse'] && $inheritedDerived && $adapter instanceof DerivedMetaAware) {
				$restoreTarget = $adapter->inverseTarget($w['target'], $before, $inheritedDerived);
			}
			$derivedNew = [];
			$applied = $w['inverse']
				? $adapter->restore($objectId, $restoreTarget, $before, $operationId)
				: $adapter->apply($objectId, $w['intent'], $w['auth'], $operationId);
			if (is_wp_error($applied)) {
				throw new MutationFailure(esc_html($applied->get_error_code()), esc_html($applied->get_error_message()), (int) (($applied->get_error_data()['status'] ?? null) ?: 409));
			}
			$resultId = $isCreate ? (int) $applied : ($w['inverse'] && $w['target'] === null ? $objectId : (int) $applied);
			if ($resultId < 1) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not return the changed item.', 'llamly'), 500);
			}
			self::phase('after_effect', $operationId);
			self::assertAlive($operationTable, $operationId, $nonce);
			$after = $adapter->capture($resultId);
			$afterHash = $adapter->fingerprint($after);
			// A forward delete legitimately leaves the object absent; any other forward write must read back a state.
			$isDelete = !$w['inverse'] && ($w['intent']['op'] ?? '') === 'delete';
			if ($afterHash === null || (!$w['inverse'] && !$isDelete && $after === null)) {
				throw new MutationFailure('journal_unavailable', esc_html__('The changed item could not be read back from the database.', 'llamly'), 503);
			}
			if ($w['inverse']) {
				// Verify-after-restore: the object must be exactly the journaled target.
				if ($adapter instanceof DerivedMetaAware && !$adapter->guardedMetaIntact($resultId)) {
					throw new MutationFailure('restore_mismatch', esc_html__('WordPress changed the restored item while saving it, so the undo or redo was cancelled.', 'llamly'), 409);
				}
				if (!hash_equals((string) $adapter->fingerprint($restoreTarget), $afterHash)) {
					// A hook that wrote public meta again during the restore is derived, within the same ceilings; anything else is corruption.
					$compare = $w['target'] !== null && $after !== null && $adapter instanceof DerivedMetaAware
						? $adapter->derivedMetaInverse($w['target'], $after, $before, $inheritedDerived)
						: ['ok' => false, 'derived' => [], 'reason' => null];
					if (($compare['reason'] ?? null) === 'derived_meta_limit') {
						throw self::derivedLimitFailure();
					}
					if (!$compare['ok']) {
						throw new MutationFailure('restore_mismatch', esc_html__('WordPress changed the restored item while saving it, so the undo or redo was cancelled.', 'llamly'), 409);
					}
					$derivedNew = $compare['derived'];
				}
			} else {
				if (!$isDelete && $adapter instanceof DerivedMetaAware) {
					$compare = $adapter->derivedMeta($before, $after, $w['intent']);
					if (($compare['reason'] ?? null) === 'derived_meta_limit') {
						throw self::derivedLimitFailure();
					}
					if (!$compare['ok']) {
						// matchesIntent() below would refuse too; stopping here keeps the one-shot Llamly-meta check from being consumed twice.
						throw new MutationFailure('restore_mismatch', esc_html__('WordPress saved the item differently from the request, so the change was cancelled.', 'llamly'), 409);
					}
					$derivedNew = $compare['derived'];
				}
				if ($isDelete ? $after !== null : !$adapter->matchesIntent($before, $after, $w['intent'])) {
					throw new MutationFailure('restore_mismatch', esc_html__('WordPress saved the item differently from the request, so the change was cancelled.', 'llamly'), 409);
				}
			}
			if ($isCreate) {
				if (!ObjectVersionStore::ensure($type, $resultId)) {
					throw new MutationFailure('journal_unavailable', esc_html__('The object version row could not be stored.', 'llamly'), 503);
				}
				$versionUpdated = $wpdb->update(Schema::tableNames()['object_versions'], ['version' => 1, 'pending_op' => null, 'last_op' => $operationId, 'updated_at' => gmdate('Y-m-d H:i:s')], ['object_type' => $type, 'object_id' => $resultId], ['%d', '%s', '%s', '%s'], ['%s', '%d']);
			} else {
				$version = ObjectVersionStore::get($type, $objectId, true);
				if (!$version || $version['pending_op'] !== $operationId) {
					throw new MutationFailure('journal_unavailable', esc_html__('The object generation marker changed during the write.', 'llamly'), 503);
				}
				$versionUpdated = $wpdb->query($wpdb->prepare(
					"UPDATE %i SET version = version + 1, pending_op = NULL, last_op = %s, updated_at = %s WHERE object_type = %s AND object_id = %d AND pending_op = %s",
					Schema::tableNames()['object_versions'],
					$operationId,
					gmdate('Y-m-d H:i:s'),
					$type,
					$objectId,
					$operationId
				));
			}
			self::assertAlive($operationTable, $operationId, $nonce);
			$result = ['operation_id' => $operationId, 'object_type' => $type, 'object_id' => $resultId, 'status' => 'committed', 'revision_hash' => $afterHash]
				+ $adapter->result($resultId, $before, $after, $w['intent']);
			if ($risk['external_effects'] ?? []) {
				$result['external_effects'] = $risk['external_effects'];
			}
			$result = self::withDerivedMeta($result, $inheritedDerived, $derivedNew);
			$opUpdated = $wpdb->update($operationTable, [
				'object_id' => $resultId,
				'status' => 'committed',
				'after_data' => $after === null ? '{}' : wp_json_encode($after),
				'after_hash' => $afterHash,
				'result_data' => wp_json_encode($result),
				'updated_at' => gmdate('Y-m-d H:i:s'),
				'expires_at' => self::snapshotExpiryAt(),
			] + (($committedLabel = self::sourceLabel($adapter, $after ?? $before)) !== null ? ['label' => $committedLabel] : []), ['id' => $operationId, 'status' => 'applying'], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%s', '%s']);
			if ($opUpdated !== 1 || $versionUpdated !== 1) {
				throw new MutationFailure('journal_unavailable', esc_html__('The operation result or object generation could not be committed.', 'llamly'), 503);
			}
			$auth = $w['auth'];
			if (!AuditLog::append($w['event'], [
				'operation_id' => $operationId,
				'object_type' => $type,
				'object_id' => $resultId,
				'tool' => $w['tool'],
				'changes' => self::describeIntent($w['inverse'] ? ['op' => $w['intent']['op']] : $w['intent']),
				'before_hash' => $beforeHash,
				'after_hash' => $afterHash,
				'batch_id' => $w['batch_id'],
			], (int) $auth['user_id'], isset($auth['credential_id']) ? (int) $auth['credential_id'] : null, (string) $auth['credential_key'])) {
				throw new MutationFailure('journal_unavailable', esc_html__('The required audit event could not be committed.', 'llamly'), 503);
			}
			if (!ObjectMutex::ownsName($lockName) || $connId === null || ObjectMutex::connectionId() !== $connId) {
				throw new MutationFailure('journal_unavailable', esc_html__('The database connection or object lock changed before commit.', 'llamly'), 503);
			}
			$wpdb->query('COMMIT');
			if ($wpdb->last_error !== '') {
				throw new MutationFailure('journal_unavailable', esc_html__('The database did not confirm the change and journal commit.', 'llamly'), 503);
			}
			$committed = true;
			// Post-commit listeners settle effects that are already durable (e.g. media file queues); they run first and
			// a failing marker cleanup can never leave them pending for a later rollback in the same process.
			self::phase('after_commit', $operationId);
			if ($isCreate) {
				try {
					$adapter->clearMarker($resultId, $operationId);
				} catch (\Throwable $markerError) {
					// The marker only helps crash reconciliation; operationResult() clears it again on replay.
				}
			}
			return $result;
		} catch (\Throwable $error) {
			if ($committed) {
				return $result ?? self::operationResult($adapter, self::operation($operationId) ?: []);
			}
			$wpdb->query('ROLLBACK');
			$failure = $error instanceof MutationFailure ? $error : new MutationFailure('journal_unavailable', __('The change and journal transaction failed.', 'llamly'), 503);
			if ($isCreate) {
				$reconciled = self::reconcileCreate($adapter, self::operation($operationId) ?: [], $connId, $failure);
				if (is_wp_error($reconciled)) {
					return $reconciled;
				}
				return is_array($reconciled) ? $reconciled : self::asError($failure);
			}
			$observed = $adapter->fingerprint($adapter->capture($objectId));
			if ($observed !== null && hash_equals($beforeHash, $observed)) {
				self::setAborted($operationId, $failure->errorCode, $type, $objectId);
			} else {
				self::markRecoveryRequired(self::operation($operationId) ?: ['id' => $operationId], [
					'reason' => $failure->errorCode,
					'before_hash' => $beforeHash,
					'observed_hash' => $observed,
					'observed_at' => gmdate('c'),
					'conn_id' => $connId,
				]);
				$failure = new MutationFailure('recovery_required', __('The item changed outside the journal transaction. An administrator must review its saved state.', 'llamly'), 409);
			}
			return self::asError($failure);
		} finally {
			$wpdb->query('SET autocommit = 1');
			wp_suspend_cache_addition($previousCacheState);
			// Every exit of T2 (COMMIT, ROLLBACK, catch, early return): the database outcome is final here.
			self::flushAfterOutcome($adapter, $objectId, $resultId);
		}
	}

	/**
	 * The derived meta keys journaled by an operation (forward writes list their own; an undo carries the original's, so a redo skips them too).
	 *
	 * @return list<string>
	 */
	private static function derivedMetaOf(string $operationId): array
	{
		if ($operationId === '') {
			return [];
		}
		$operation = self::operation($operationId);
		$result = json_decode((string) ($operation['result_data'] ?? ''), true);
		$keys = is_array($result) && is_array($result['derived_meta'] ?? null) ? $result['derived_meta'] : [];
		return array_values(array_filter(array_map('strval', $keys), [PostAdapter::class, 'isDerivableMetaKey']));
	}

	/**
	 * Adds `derived_meta` (all keys this operation leaves alone, for the journal) and a `derived_meta` warning (keys a hook changed in this operation).
	 *
	 * @param array<string, mixed> $result
	 * @param list<string> $inherited
	 * @param list<string> $new
	 * @return array<string, mixed>
	 */
	private static function withDerivedMeta(array $result, array $inherited, array $new): array
	{
		$all = array_values(array_unique(array_merge($inherited, $new)));
		sort($all, SORT_STRING);
		if ($all) {
			$result['derived_meta'] = $all;
		}
		if ($new) {
			$result['warnings'] = array_merge((array) ($result['warnings'] ?? []), [['type' => 'derived_meta', 'keys' => array_values($new)]]);
		}
		return $result;
	}

	private static function derivedLimitFailure(): MutationFailure
	{
		return new MutationFailure('derived_meta_limit', esc_html__('A plugin changed too much extra metadata while saving, so the change was cancelled and restored.', 'llamly'), 409);
	}

	/** The nonce written first in T2 is gone if the database rolled the transaction back on its own (deadlock victim). */
	private static function assertAlive(string $operationTable, string $operationId, string $nonce): void
	{
		global $wpdb;
		if ((string) $wpdb->get_var($wpdb->prepare('SELECT result_data FROM %i WHERE id = %s', $operationTable, $operationId)) !== $nonce) {
			throw new MutationFailure('journal_unavailable', esc_html__('The database rolled back the change transaction (deadlock or lock timeout). Nothing was committed; retry with the same idempotency key.', 'llamly'), 503);
		}
	}

	private static function reconcileExisting(Adapter $adapter, array $operation, ?int $connId): bool|\WP_Error
	{
		// Recovery reads and commits under the same REPEATABLE READ as the write path; the host's level comes back on every exit.
		$isolation = MutationRunner::enterRepeatableRead();
		if (is_wp_error($isolation)) {
			return $isolation;
		}
		try {
			return self::reconcileExistingInIsolation($adapter, $operation, $connId);
		} finally {
			MutationRunner::restoreIsolation($isolation);
		}
	}

	private static function reconcileExistingInIsolation(Adapter $adapter, array $operation, ?int $connId): bool|\WP_Error
	{
		$objectId = (int) ($operation['object_id'] ?? 0);
		$id = (string) ($operation['id'] ?? '');
		if ($objectId < 1 || $id === '') {
			return self::error('recovery_required', __('The pending operation has no recoverable item identifier.', 'llamly'), 409);
		}
		$before = self::decodeState((string) ($operation['before_data'] ?? ''), (string) ($operation['before_hash'] ?? ''), $adapter);
		$intent = json_decode((string) ($operation['intended_data'] ?? ''), true);
		$beforeHash = (string) ($operation['before_hash'] ?? '');
		$current = $adapter->capture($objectId);
		$currentHash = $adapter->fingerprint($current);
		if ($before === false || !is_array($intent) || $currentHash === null) {
			self::markRecoveryRequired($operation, ['reason' => 'reconcile_read_failed', 'observed_hash' => $currentHash, 'observed_at' => gmdate('c')]);
			return self::error('recovery_required', __('The pending operation could not be verified.', 'llamly'), 409);
		}
		if (hash_equals($beforeHash, $currentHash)) {
			self::setAborted($id, 'interrupted', $adapter->type(), $objectId);
			return true;
		}
		$inverse = isset($intent['target']) || array_key_exists('target', $intent);
		$derived = [];
		$inherited = $inverse ? self::derivedMetaOf((string) ($intent['of'] ?? '')) : [];
		$matches = $inverse
			? hash_equals((string) $adapter->fingerprint(is_array($intent['target']) ? $intent['target'] : null), $currentHash)
			: (($intent['op'] ?? '') === 'delete' ? $current === null : ($current !== null && $adapter->matchesIntent($before, $current, $intent)));
		if ($matches && !$inverse && $current !== null && is_array($before) && $adapter instanceof DerivedMetaAware) {
			$derived = $adapter->derivedMeta($before, $current, $intent)['derived'];
		} elseif (!$matches && $inverse && is_array($intent['target']) && $current !== null && $adapter instanceof DerivedMetaAware) {
			// The interrupted undo/redo kept the derived keys of the original operation, as it should.
			$compare = $adapter->derivedMetaInverse($intent['target'], $current, is_array($before) ? $before : null, $inherited);
			$matches = $compare['ok'];
			$derived = $compare['derived'];
		}
		if ($matches && self::commitRecovered($adapter, $operation, $objectId, $current, $currentHash, false, $inherited, $derived)) {
			return true;
		}
		self::markRecoveryRequired($operation, [
			'reason' => 'observed_state_differs_from_before_and_intent',
			'before_hash' => $beforeHash,
			'observed_hash' => $currentHash,
			'observed_at' => gmdate('c'),
			'conn_id' => $connId,
		]);
		return self::error('recovery_required', __('The item does not match either the saved state or the recorded change. An administrator must review it.', 'llamly'), 409);
	}

	private static function reconcileCreate(Adapter $adapter, array $operation, ?int $connId, ?MutationFailure $failure = null): mixed
	{
		$id = (string) ($operation['id'] ?? '');
		if ($id === '') {
			return self::error('journal_unavailable', __('The creation journal row could not be loaded.', 'llamly'), 503);
		}
		$operation = self::operation($id) ?: $operation;
		if (($operation['status'] ?? '') === 'committed') {
			return self::operationResult($adapter, $operation);
		}
		$ids = $adapter->findMarked($id);
		if (count($ids) === 1) {
			$objectId = $ids[0];
			$intent = json_decode((string) ($operation['intended_data'] ?? ''), true);
			$current = $adapter->capture($objectId);
			$hash = $adapter->fingerprint($current);
			if (is_array($intent) && $current !== null && $hash !== null && $adapter->matchesIntent(null, $current, $intent)
				&& ObjectVersionStore::ensure($adapter->type(), $objectId)
				&& self::commitRecovered($adapter, $operation, $objectId, $current, $hash, true)
			) {
				$adapter->clearMarker($objectId, $id);
				return self::operationResult($adapter, self::operation($id) ?: []);
			}
			self::markRecoveryRequired($operation, ['reason' => 'created_object_does_not_match_intent', 'object_id' => $objectId, 'observed_hash' => $hash, 'observed_at' => gmdate('c'), 'conn_id' => $connId]);
			return self::error('recovery_required', __('An item created by the interrupted operation no longer matches its recorded intent.', 'llamly'), 409);
		}
		if (count($ids) > 1) {
			self::markRecoveryRequired($operation, ['reason' => 'multiple_create_markers', 'object_ids' => $ids, 'observed_at' => gmdate('c')]);
			return self::error('recovery_required', __('More than one item carries the interrupted creation marker.', 'llamly'), 409);
		}
		self::setAborted($id, $failure ? $failure->errorCode : 'interrupted', $adapter->type(), 0);
		return 'retry';
	}

	/** @param array<string, mixed>|null $current */
	private static function commitRecovered(Adapter $adapter, array $operation, int $objectId, ?array $current, string $hash, bool $create = false, array $inheritedDerived = [], array $derivedNew = []): bool
	{
		$isolation = MutationRunner::enterRepeatableRead();
		if (is_wp_error($isolation)) {
			return false; // the callers mark the operation recovery_required, which fails closed
		}
		try {
			return self::commitRecoveredInIsolation($adapter, $operation, $objectId, $current, $hash, $create, $inheritedDerived, $derivedNew);
		} finally {
			MutationRunner::restoreIsolation($isolation);
		}
	}

	/** @param array<string, mixed>|null $current */
	private static function commitRecoveredInIsolation(Adapter $adapter, array $operation, int $objectId, ?array $current, string $hash, bool $create, array $inheritedDerived, array $derivedNew): bool
	{
		global $wpdb;
		$id = (string) $operation['id'];
		$type = $adapter->type();
		if (!self::beginTransaction()) {
			return false;
		}
		if ($create) {
			$versionUpdated = $wpdb->update(Schema::tableNames()['object_versions'], ['version' => 1, 'pending_op' => null, 'last_op' => $id, 'updated_at' => gmdate('Y-m-d H:i:s')], ['object_type' => $type, 'object_id' => $objectId], ['%d', '%s', '%s', '%s'], ['%s', '%d']);
			$versionOk = $versionUpdated !== false;
		} else {
			$version = ObjectVersionStore::get($type, $objectId, true);
			if (!$version || $version['pending_op'] !== $id) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			$versionOk = $wpdb->query($wpdb->prepare("UPDATE %i SET version = version + 1, pending_op = NULL, last_op = %s, updated_at = %s WHERE object_type = %s AND object_id = %d AND pending_op = %s", Schema::tableNames()['object_versions'], $id, gmdate('Y-m-d H:i:s'), $type, $objectId, $id)) === 1;
		}
		$result = ['operation_id' => $id, 'object_type' => $type, 'object_id' => $objectId, 'status' => 'committed', 'revision_hash' => $hash, 'recovered' => true]
			+ $adapter->result($objectId, null, $current, []);
		$result = self::withDerivedMeta($result, $inheritedDerived, $derivedNew);
		$opUpdated = $wpdb->update(Schema::tableNames()['operations'], ['object_id' => $objectId, 'status' => 'committed', 'after_data' => $current === null ? '{}' : wp_json_encode($current), 'after_hash' => $hash, 'result_data' => wp_json_encode($result), 'updated_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => self::snapshotExpiryAt()] + (($recoveredLabel = self::sourceLabel($adapter, $current)) !== null ? ['label' => $recoveredLabel] : []), ['id' => $id, 'status' => 'applying'], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%s', '%s']);
		$audited = AuditLog::append($type . '_recovered', ['operation_id' => $id, 'object_type' => $type, 'object_id' => $objectId, 'after_hash' => $hash, 'tool' => (string) ($operation['tool'] ?? ''), 'recovered' => true], (int) ($operation['user_id'] ?? 0), isset($operation['credential_id']) ? (int) $operation['credential_id'] : null, (string) ($operation['credential_key'] ?? ''));
		if (!$versionOk || $opUpdated !== 1 || !$audited) {
			$wpdb->query('ROLLBACK');
			return false;
		}
		$wpdb->query('COMMIT');
		self::flushAfterOutcome($adapter, $objectId);
		return $wpdb->last_error === '';
	}

	/**
	 * Administrator accepts the current saved state after reviewing a recovery_required operation.
	 * Nothing is rewritten; the operation closes, the object unblocks, and stale editor tokens become invalid.
	 */
	public static function resolveRecovery(string $operationId, int $actorUserId): array|\WP_Error
	{
		global $wpdb;
		$operation = self::operation($operationId);
		if (!$operation || $operation['status'] !== 'recovery_required') {
			return self::error('operation_not_found', __('This activity item is not waiting for recovery.', 'llamly'), 404);
		}
		$adapter = self::adapter((string) $operation['object_type']);
		$objectId = (int) $operation['object_id'];
		$current = $adapter && $objectId > 0 ? $adapter->capture($objectId) : null;
		if (!$adapter || !user_can($actorUserId, 'manage_options') || ($current !== null && !$adapter->canInverse($actorUserId, $objectId, null, $current))) {
			return self::error('forbidden', __('Your WordPress user cannot resolve this recovery.', 'llamly'), 403);
		}
		$proof = json_decode((string) ($operation['proof'] ?? ''), true) ?: [];
		$proof['resolved_by'] = $actorUserId;
		$proof['resolved_at'] = gmdate('c');
		$proof['resolved_hash'] = $objectId > 0 ? $adapter->fingerprint($current) : null;
		if (!self::beginTransaction()) {
			return self::error('journal_unavailable', __('The recovery could not be recorded.', 'llamly'), 503);
		}
		$updated = $wpdb->update(Schema::tableNames()['operations'], [
			'status' => 'aborted',
			'error_code' => 'recovery_resolved',
			'proof' => wp_json_encode($proof),
			'updated_at' => gmdate('Y-m-d H:i:s'),
		], ['id' => $operationId, 'status' => 'recovery_required'], ['%s', '%s', '%s', '%s'], ['%s', '%s']);
		$orphan = is_string($proof['pending_op'] ?? null) ? (string) $proof['pending_op'] : $operationId;
		$released = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET version = version + 1, pending_op = NULL, updated_at = %s WHERE object_type = %s AND object_id = %d AND pending_op IN (%s, %s)",
			Schema::tableNames()['object_versions'],
			gmdate('Y-m-d H:i:s'),
			(string) $operation['object_type'],
			$objectId,
			$operationId,
			$orphan
		));
		$audited = AuditLog::append('recovery_resolved', ['operation_id' => $operationId, 'object_type' => $operation['object_type'], 'object_id' => $objectId, 'accepted_hash' => $proof['resolved_hash']], $actorUserId, null, 'admin:' . $actorUserId);
		if ($updated !== 1 || $released === false || !$audited) {
			$wpdb->query('ROLLBACK');
			return self::error('journal_unavailable', __('The recovery could not be recorded.', 'llamly'), 503);
		}
		$wpdb->query('COMMIT');
		if ($wpdb->last_error !== '') {
			return self::error('journal_unavailable', __('The recovery could not be recorded.', 'llamly'), 503);
		}
		return ['operation_id' => $operationId, 'status' => 'resolved'];
	}

	/**
	 * With `light => true` (the Activity tab) the filters, the state buckets, the order and the page are applied in SQL;
	 * no snapshot is read, no object is captured and the title comes from the stored label.
	 *
	 * @param array{object_type?:string,object_id?:int,status?:string,credential?:string,tool?:string,q?:string,batch_id?:string,user_id?:int,light?:bool,statuses?:list<string>,state?:string,updated_after?:string,intent?:string} $filters
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public static function listOperations(int $page = 1, int $perPage = 25, array $filters = []): array
	{
		global $wpdb;
		$table = Schema::tableNames()['operations'];
		$page = max(1, $page);
		$perPage = max(10, min(100, $perPage));
		$filterArgs = self::filterArgs($filters);
		if ($filterArgs === null) {
			return ['items' => [], 'total' => 0];
		}
		if (!empty($filters['light'])) {
			return self::listLight($table, $page, $perPage, $filters, $filterArgs);
		}
		// Every filter is in the SQL with an on/off flag (constant SQL; the database drops the filters that are off).
				// Constant SQL with 20 placeholders; the 20 values are passed one by one, in placeholder order.
		$args = array_merge([$table], $filterArgs);
		$total = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM %i o WHERE (%d = 0 OR o.object_type = %s) AND (%d = 0 OR o.object_id = %d) AND (%d = 0 OR o.status = %s)'
				. " AND (%d = 0 OR (o.status = 'aborted' AND o.error_code = 'awaiting_approval')) AND (%d = 0 OR o.credential_key = %s)"
				. ' AND (%d = 0 OR o.user_id = %d) AND (%d = 0 OR o.tool = %s) AND (%d = 0 OR o.batch_id = %s)'
				. ' AND (%d = 0 OR o.created_at >= %s) AND (%d = 0 OR o.created_at <= %s)',
			[$args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6], $args[7], $args[8], $args[9], $args[10], $args[11], $args[12], $args[13], $args[14], $args[15], $args[16], $args[17], $args[18], $args[19]]
		));
				// Constant SQL with 22 placeholders; the 22 values are passed one by one, in placeholder order.
		$args = array_merge([$table], $filterArgs, [$perPage, ($page - 1) * $perPage]);
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT o.id, o.tool, o.object_type, o.object_id, o.status, o.credential_key, o.user_id, o.before_data, o.after_data, o.before_hash, o.after_hash,
				o.undo_of, o.redo_of, o.batch_id, o.generation, o.seq, o.error_code, o.intended_data, o.created_at, o.updated_at, o.expires_at
			 FROM %i o WHERE (%d = 0 OR o.object_type = %s) AND (%d = 0 OR o.object_id = %d) AND (%d = 0 OR o.status = %s)'
				. " AND (%d = 0 OR (o.status = 'aborted' AND o.error_code = 'awaiting_approval')) AND (%d = 0 OR o.credential_key = %s)"
				. ' AND (%d = 0 OR o.user_id = %d) AND (%d = 0 OR o.tool = %s) AND (%d = 0 OR o.batch_id = %s)'
				. ' AND (%d = 0 OR o.created_at >= %s) AND (%d = 0 OR o.created_at <= %s)
			 ORDER BY o.created_at DESC, o.seq DESC, o.id DESC LIMIT %d OFFSET %d',
			[$args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6], $args[7], $args[8], $args[9], $args[10], $args[11], $args[12], $args[13], $args[14], $args[15], $args[16], $args[17], $args[18], $args[19], $args[20], $args[21]]
		), ARRAY_A);
		$current = [];
		$currentLabel = [];
		$items = [];
		$generation = self::currentGeneration();
		foreach (is_array($rows) ? $rows : [] as $row) {
			$adapter = self::adapter((string) $row['object_type']);
			$objectKey = $row['object_type'] . ':' . (int) $row['object_id'];
			if ($adapter && (int) $row['object_id'] > 0 && !array_key_exists($objectKey, $current)) {
				$captured = $adapter->capture((int) $row['object_id']);
				$current[$objectKey] = $adapter->fingerprint($captured);
				$currentLabel[$objectKey] = $captured === null ? null : $adapter->label($captured);
			}
			$before = $adapter ? self::decodeState((string) $row['before_data'], (string) $row['before_hash'], $adapter) : null;
			$after = $adapter ? self::decodeState((string) ($row['after_data'] ?? ''), (string) ($row['after_hash'] ?? ''), $adapter) : null;
			$state = is_array($after) ? $after : (is_array($before) ? $before : null);
			$expiresAt = (string) $row['status'] === 'committed' ? self::operationExpiry($row) : null;
			$expired = (string) $row['status'] === 'committed' && ($expiresAt === null || $expiresAt <= time());
			$staleGeneration = (int) $row['generation'] !== $generation;
			$intent = json_decode((string) ($row['intended_data'] ?? ''), true);
			$title = (string) $row['object_type'];
			if ($adapter) {
				// A request that never ran (awaiting approval, rejected, failed before capture) has no recorded state yet:
				// name it after what the agent asked for, then after the object as it is now, before the generic label.
				$title = $state !== null
					? $adapter->label($state)
					: (self::intentLabel(is_array($intent) ? $intent : []) ?? $currentLabel[$objectKey] ?? $adapter->label(null));
			}
			$items[] = [
				'id' => (string) $row['id'],
				'tool' => (string) $row['tool'],
				'object_type' => (string) $row['object_type'],
				'object_id' => (int) $row['object_id'],
				'title' => $title,
				'status' => (string) $row['status'],
				'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
				'credential' => (string) $row['credential_key'],
				'user_id' => (int) $row['user_id'],
				'before_hash' => $adapter ? self::currentFormHash($adapter, (string) $row['before_hash'], $before) : (string) $row['before_hash'],
				'after_hash' => $row['after_hash'] === null ? null : ($adapter ? self::currentFormHash($adapter, (string) $row['after_hash'], $after) : (string) $row['after_hash']),
				'object_current_hash' => $current[$objectKey] ?? ((int) $row['object_id'] > 0 && $adapter ? null : ($adapter ? self::absentHash($adapter) : null)),
				'changes' => is_array($intent) ? self::describeIntent($intent) : null,
				'undo_of' => $row['undo_of'] === null ? null : (string) $row['undo_of'],
				'redo_of' => $row['redo_of'] === null ? null : (string) $row['redo_of'],
				'batch_id' => $row['batch_id'] === null ? null : (string) $row['batch_id'],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
				'expires_at' => $expiresAt === null ? null : gmdate('Y-m-d H:i:s', $expiresAt),
				'undo_expired' => $expired || $staleGeneration,
				'undo_restored' => $staleGeneration,
				'undo_reason' => $staleGeneration
					? __('The site database was restored after this change.', 'llamly')
					: ($expired
						? ($expiresAt === null
							? __('The snapshot retention period could not be confirmed.', 'llamly')
							: __('The snapshot retention period has ended.', 'llamly'))
						: null),
			];
		}
		return ['items' => $items, 'total' => $total];
	}

	/**
	 * Stored English label to the admin language. Only the generic placeholders the adapters and Maintenance write are
	 * translated; a title typed by a person is shown as it is.
	 */
	public static function displayLabel(string $label): string
	{
		switch ($label) {
			case '(comment)':
				return __('(comment)', 'llamly');
			case 'Global styles':
				return __('Global styles', 'llamly');
			case '(menu)':
				return __('(menu)', 'llamly');
			case 'Site option':
				return __('Site option', 'llamly');
			case '(untitled pattern)':
				return __('(untitled pattern)', 'llamly');
			case '(untitled)':
				return __('(untitled)', 'llamly');
			case '(template)':
				return __('(template)', 'llamly');
			case '(unnamed term)':
				return __('(unnamed term)', 'llamly');
			case '(user)':
				return __('(user)', 'llamly');
		}
		return $label;
	}

	/** English source label of a state (what operations.label stores), scrubbed and limited to one line of 200 characters. */
	private static function sourceLabel(Adapter $adapter, ?array $state): ?string
	{
		$switched = function_exists('switch_to_locale') && function_exists('get_locale') && get_locale() !== 'en_US' && switch_to_locale('en_US');
		try {
			$text = $adapter->label($state);
		} finally {
			if ($switched) {
				restore_previous_locale();
			}
		}
		return self::cleanLabel($text);
	}

	private static function cleanLabel(string $text): ?string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
		if ($text === '') {
			return null;
		}
		$text = Redactor::text($text);
		return function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 200, '…') : substr($text, 0, 200);
	}

	/** Fills the label of a row that has none yet (a request that stopped before it captured anything to name). */
	private static function storeLabel(string $operationId, ?string $label): void
	{
		global $wpdb;
		if ($label === null || $label === '') {
			return;
		}
		$wpdb->query($wpdb->prepare('UPDATE %i SET label = %s WHERE id = %s AND label IS NULL', Schema::tableNames()['operations'], $label, $operationId));
	}

	/**
	 * Flag/value pairs, in order, for the filter conditions shared by the Activity queries (see listOperations()); null
	 * when a credential filter cannot be applied (it returns nothing rather than every connection's changes).
	 *
	 * @return list<int|string>|null
	 */
	private static function filterArgs(array $filters): ?array
	{
		$credential = null;
		if (array_key_exists('credential', $filters) && $filters['credential'] !== '' && $filters['credential'] !== null) {
			if (!is_string($filters['credential']) || !preg_match(self::CREDENTIAL_KEY_PATTERN, $filters['credential'])) {
				return null;
			}
			$credential = $filters['credential'];
		}
		$status = !empty($filters['status']) && in_array($filters['status'], ['committed', 'aborted', 'recovery_required', 'applying', 'pending'], true) ? (string) $filters['status'] : null;
		$batch = !empty($filters['batch_id']) && preg_match('/^[a-f0-9-]{36}$/', (string) $filters['batch_id']) ? (string) $filters['batch_id'] : null;
		$from = !empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_from']) ? $filters['date_from'] . ' 00:00:00' : null;
		$to = !empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_to']) ? $filters['date_to'] . ' 23:59:59' : null;
		return [
			empty($filters['object_type']) ? 0 : 1, empty($filters['object_type']) ? '' : sanitize_key((string) $filters['object_type']),
			empty($filters['object_id']) ? 0 : 1, (int) ($filters['object_id'] ?? 0),
			$status === null ? 0 : 1, (string) $status,
			empty($filters['awaiting_approval']) ? 0 : 1,
			$credential === null ? 0 : 1, (string) $credential,
			empty($filters['user_id']) ? 0 : 1, (int) ($filters['user_id'] ?? 0),
			empty($filters['tool']) ? 0 : 1, empty($filters['tool']) ? '' : sanitize_key((string) $filters['tool']),
			$batch === null ? 0 : 1, (string) $batch,
			$from === null ? 0 : 1, (string) $from,
			$to === null ? 0 : 1, (string) $to,
		];
	}

	/**
	 * SQL for the Activity tab: filters, state buckets, order and LIMIT/OFFSET in the database, a separate aggregate
	 * count, and none of the snapshot columns. `object_current_hash` is not computed here (that needs capture()).
	 *
	 * @param list<int|string> $filterArgs from filterArgs()
	 * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,counts:array<string,int>}
	 */
	private static function listLight(string $table, int $page, int $perPage, array $filters, array $filterArgs): array
	{
		global $wpdb;
		$statuses = array_values(array_unique(array_intersect(array_map('strval', (array) ($filters['statuses'] ?? [])), ['committed', 'aborted', 'recovery_required', 'applying', 'pending'])));
		$updatedAfter = !empty($filters['updated_after']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $filters['updated_after']) ? (string) $filters['updated_after'] : '';
		$q = trim((string) ($filters['q'] ?? ''));
		$like = '%' . $wpdb->esc_like($q) . '%';
		$idSearch = $q !== '' && preg_match('/^#?(\d{1,18})$/', $q, $m) ? (int) $m[1] : null;
		// Filters of the Activity tab: statuses (at most the five known ones), updated_after and the text search.
		$lightArgs = array_merge(
			[$statuses ? 1 : 0], array_pad($statuses, 5, ''),
			[$updatedAfter === '' ? 0 : 1, $updatedAfter],
			[$q === '' ? 0 : 1, $like, $like, $like, $idSearch === null ? 0 : 1, (int) $idSearch]
		);
		// State buckets, mirroring the states AdminController derives per row. Every bucket is written out in the SQL; its
		// values (table name, generation, retention cut-off) are bound below in the order they appear.
		$retentionDays = max(1, (int) (Settings::get()['journal_retention_days'] ?? 7));
		$expiredArgs = [self::currentGeneration(), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() - $retentionDays * DAY_IN_SECONDS)];
		$undoneArgs = [$table, $table, $table];
		$irreversibleArgs = array_merge([$table, $table, $table], $expiredArgs, [$table]);
		$activeArgs = array_merge($undoneArgs, $irreversibleArgs);

		$withCounts = ($filters['counts'] ?? true) !== false;
				// Constant SQL with 54 placeholders; the 54 values are passed one by one, in placeholder order.
		$args = array_merge($activeArgs, $undoneArgs, $irreversibleArgs, [$table], $filterArgs, $lightArgs);
		$countRow = !$withCounts ? [] : $wpdb->get_row($wpdb->prepare(
			'SELECT COUNT(*) AS n_all,'
				. ' COALESCE(SUM(CASE WHEN ' . "(NOT ((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND NOT EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) AND NOT ((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND ((EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) OR ((o.generation <> %d OR (o.expires_at IS NOT NULL AND o.expires_at <= %s) OR (o.expires_at IS NULL AND o.created_at <= %s)) AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed')))) AND NOT (o.status = 'committed' AND (o.undo_of IS NOT NULL OR o.redo_of IS NOT NULL)))" . ' THEN 1 ELSE 0 END), 0) AS n_active,'
				. ' COALESCE(SUM(CASE WHEN ' . "((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND NOT EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed'))" . ' THEN 1 ELSE 0 END), 0) AS n_undone,'
				. ' COALESCE(SUM(CASE WHEN ' . "((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND ((EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) OR ((o.generation <> %d OR (o.expires_at IS NOT NULL AND o.expires_at <= %s) OR (o.expires_at IS NULL AND o.created_at <= %s)) AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed'))))" . ' THEN 1 ELSE 0 END), 0) AS n_irreversible'
				. ' FROM %i o WHERE (%d = 0 OR o.object_type = %s) AND (%d = 0 OR o.object_id = %d) AND (%d = 0 OR o.status = %s)'
				. " AND (%d = 0 OR (o.status = 'aborted' AND o.error_code = 'awaiting_approval')) AND (%d = 0 OR o.credential_key = %s)"
				. ' AND (%d = 0 OR o.user_id = %d) AND (%d = 0 OR o.tool = %s) AND (%d = 0 OR o.batch_id = %s)'
				. ' AND (%d = 0 OR o.created_at >= %s) AND (%d = 0 OR o.created_at <= %s)'
				. ' AND (%d = 0 OR o.status IN (%s, %s, %s, %s, %s)) AND (%d = 0 OR o.updated_at > %s) AND (%d = 0 OR o.label LIKE %s OR o.tool LIKE %s OR o.object_type LIKE %s OR (%d = 1 AND o.object_id = %d))',
			[$args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6], $args[7], $args[8], $args[9], $args[10], $args[11], $args[12], $args[13], $args[14], $args[15], $args[16], $args[17], $args[18], $args[19], $args[20], $args[21], $args[22], $args[23], $args[24], $args[25], $args[26], $args[27], $args[28], $args[29], $args[30], $args[31], $args[32], $args[33], $args[34], $args[35], $args[36], $args[37], $args[38], $args[39], $args[40], $args[41], $args[42], $args[43], $args[44], $args[45], $args[46], $args[47], $args[48], $args[49], $args[50], $args[51], $args[52], $args[53]]
		), ARRAY_A);
		$counts = [
			'all' => (int) ($countRow['n_all'] ?? 0),
			'active' => (int) ($countRow['n_active'] ?? 0),
			'undone' => (int) ($countRow['n_undone'] ?? 0),
			'irreversible' => (int) ($countRow['n_irreversible'] ?? 0),
		];
		$state = (string) ($filters['state'] ?? 'all');
		if (!in_array($state, ['active', 'undone', 'irreversible'], true)) {
			$state = 'all';
		}
		// A caller that asked for no counts (the approval queue, at most one page) gets the page it asked for as the total.
		$total = $withCounts ? $counts[$state] : $perPage;
		$pages = max(1, (int) ceil($total / $perPage));
		$page = min($page, $pages);

		// The intent is read only where it is small (a content-sized intent would be a blob in a list query), unless
		// the caller asked for the full intent.
				// Constant SQL with 60 placeholders; the 60 values are passed one by one, in placeholder order.
		$args = array_merge(
				[($filters['intent'] ?? '') === 'full' ? 1 : 0, $table],
				$filterArgs,
				$lightArgs,
				[$state === 'active' ? 1 : 0], $activeArgs,
				[$state === 'undone' ? 1 : 0], $undoneArgs,
				[$state === 'irreversible' ? 1 : 0], $irreversibleArgs,
				[$perPage, ($page - 1) * $perPage]
			);
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT o.id, o.tool, o.object_type, o.object_id, o.status, o.credential_key, o.user_id, o.before_hash, o.after_hash, o.request_hash,
				o.undo_of, o.redo_of, o.batch_id, o.generation, o.seq, o.error_code, o.created_at, o.updated_at, o.expires_at, o.label,
				CASE WHEN %d = 1 OR CHAR_LENGTH(o.intended_data) <= 8000 THEN o.intended_data END AS intended_data'
				. ' FROM %i o WHERE (%d = 0 OR o.object_type = %s) AND (%d = 0 OR o.object_id = %d) AND (%d = 0 OR o.status = %s)'
				. " AND (%d = 0 OR (o.status = 'aborted' AND o.error_code = 'awaiting_approval')) AND (%d = 0 OR o.credential_key = %s)"
				. ' AND (%d = 0 OR o.user_id = %d) AND (%d = 0 OR o.tool = %s) AND (%d = 0 OR o.batch_id = %s)'
				. ' AND (%d = 0 OR o.created_at >= %s) AND (%d = 0 OR o.created_at <= %s)'
				. ' AND (%d = 0 OR o.status IN (%s, %s, %s, %s, %s)) AND (%d = 0 OR o.updated_at > %s) AND (%d = 0 OR o.label LIKE %s OR o.tool LIKE %s OR o.object_type LIKE %s OR (%d = 1 AND o.object_id = %d))'
				. ' AND (%d = 0 OR ' . "(NOT ((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND NOT EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) AND NOT ((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND ((EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) OR ((o.generation <> %d OR (o.expires_at IS NOT NULL AND o.expires_at <= %s) OR (o.expires_at IS NULL AND o.created_at <= %s)) AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed')))) AND NOT (o.status = 'committed' AND (o.undo_of IS NOT NULL OR o.redo_of IS NOT NULL)))" . ')'
				. ' AND (%d = 0 OR ' . "((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND NOT EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed'))" . ')'
				. ' AND (%d = 0 OR ' . "((o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL) AND ((EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed') AND EXISTS (SELECT 1 FROM %i u2 INNER JOIN %i r ON r.redo_of = u2.id WHERE u2.undo_of = o.id AND u2.status = 'committed' AND r.status = 'committed')) OR ((o.generation <> %d OR (o.expires_at IS NOT NULL AND o.expires_at <= %s) OR (o.expires_at IS NULL AND o.created_at <= %s)) AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed'))))" . ')'
				. ' ORDER BY o.created_at DESC, o.seq DESC, o.id DESC LIMIT %d OFFSET %d',
			[$args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6], $args[7], $args[8], $args[9], $args[10], $args[11], $args[12], $args[13], $args[14], $args[15], $args[16], $args[17], $args[18], $args[19], $args[20], $args[21], $args[22], $args[23], $args[24], $args[25], $args[26], $args[27], $args[28], $args[29], $args[30], $args[31], $args[32], $args[33], $args[34], $args[35], $args[36], $args[37], $args[38], $args[39], $args[40], $args[41], $args[42], $args[43], $args[44], $args[45], $args[46], $args[47], $args[48], $args[49], $args[50], $args[51], $args[52], $args[53], $args[54], $args[55], $args[56], $args[57], $args[58], $args[59]]
		), ARRAY_A);
		$generation = self::currentGeneration();
		$items = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$adapter = self::adapter((string) $row['object_type']);
			$intent = $row['intended_data'] !== null ? json_decode((string) $row['intended_data'], true) : null;
			$intent = is_array($intent) ? $intent : null;
			if ($row['label'] !== null && $row['label'] !== '') {
				$title = self::displayLabel((string) $row['label']);
			} else {
				$title = (string) $row['object_type'];
				if ($adapter) {
					$title = ($intent !== null ? self::intentLabel($intent) : null) ?? self::displayLabel(self::sourceLabel($adapter, null) ?? (string) $row['object_type']);
				}
			}
			$committed = (string) $row['status'] === 'committed';
			$expiresAt = $committed ? self::operationExpiry($row) : null;
			$expired = $committed && ($expiresAt === null || $expiresAt <= time());
			$staleGeneration = (int) $row['generation'] !== $generation;
			$items[] = [
				'id' => (string) $row['id'],
				'tool' => (string) $row['tool'],
				'object_type' => (string) $row['object_type'],
				'object_id' => (int) $row['object_id'],
				'title' => $title,
				'status' => (string) $row['status'],
				'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
				'credential' => (string) $row['credential_key'],
				'user_id' => (int) $row['user_id'],
				'before_hash' => (string) $row['before_hash'],
				'request_hash' => (string) $row['request_hash'],
				'after_hash' => $row['after_hash'] === null ? null : (string) $row['after_hash'],
				'object_current_hash' => null,
				'changes' => $intent !== null ? self::describeIntent($intent) : null,
				'intent' => ($filters['intent'] ?? '') === 'full' ? $intent : null,
				'intent_of' => $intent !== null && is_string($intent['of'] ?? null) ? $intent['of'] : null,
				'undo_of' => $row['undo_of'] === null ? null : (string) $row['undo_of'],
				'redo_of' => $row['redo_of'] === null ? null : (string) $row['redo_of'],
				'batch_id' => $row['batch_id'] === null ? null : (string) $row['batch_id'],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
				'expires_at' => $expiresAt === null ? null : gmdate('Y-m-d H:i:s', $expiresAt),
				'undo_expired' => $expired || $staleGeneration,
				'undo_restored' => $staleGeneration,
				'undo_reason' => $staleGeneration
					? __('The site database was restored after this change.', 'llamly')
					: ($expired
						? ($expiresAt === null
							? __('The snapshot retention period could not be confirmed.', 'llamly')
							: __('The snapshot retention period has ended.', 'llamly'))
						: null),
			];
		}
		return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
	}

	/**
	 * Display name taken from a journaled intent, for operations that have no captured state yet.
	 * Only naming fields are read (titles, names, logins, the uploaded file name); e-mails, content and other fields
	 * never reach the label. The value is scrubbed like log text and shortened to one line.
	 *
	 * @param array<string, mixed> $intent
	 */
	private static function intentLabel(array $intent): ?string
	{
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$candidates = [$fields['post_title'] ?? null, $fields['name'] ?? null, $fields['display_name'] ?? null, $fields['user_login'] ?? null, $intent['filename'] ?? null];
		foreach ($candidates as $candidate) {
			if (!is_string($candidate)) {
				continue;
			}
			$text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($candidate)));
			if ($text === '') {
				continue;
			}
			$text = Redactor::text($text);
			return function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 80, '…') : substr($text, 0, 80);
		}
		return null;
	}

	/** Remove reversible payloads after their retention window while preserving the activity and recovery metadata. */
	public static function pruneExpiredSnapshots(): bool
	{
		global $wpdb;
		$settings = Settings::get();
		if (empty($settings['_valid'])) {
			return false;
		}
		$table = Schema::tableNames()['operations'];
		$days = (int) $settings['journal_retention_days'];
		$backfill = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET expires_at = DATE_ADD(created_at, INTERVAL %d DAY) WHERE status = 'committed' AND expires_at IS NULL",
			$table,
			$days
		));
		if ($backfill === false) {
			return false;
		}
		$expired = $wpdb->get_results($wpdb->prepare(
			"SELECT id, object_type, before_data, after_data FROM %i WHERE status = 'committed' AND expires_at <= %s AND (before_data <> '{}' OR after_data IS NOT NULL OR intended_data IS NOT NULL) LIMIT 200",
			$table,
			gmdate('Y-m-d H:i:s')
		), ARRAY_A);
		foreach (is_array($expired) ? $expired : [] as $row) {
			do_action('llamly_free_snapshot_expired', (string) $row['object_type'], (string) $row['id'], json_decode((string) $row['before_data'], true), json_decode((string) ($row['after_data'] ?? ''), true));
		}
		if (!self::beginTransaction()) {
			return false;
		}
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET before_data = '{}', after_data = NULL, intended_data = NULL
			 WHERE status = 'committed' AND expires_at <= %s AND (before_data <> '{}' OR after_data IS NOT NULL OR intended_data IS NOT NULL)",
			$table,
			gmdate('Y-m-d H:i:s')
		));
		if ($updated === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return false;
		}
		$wpdb->query('COMMIT');
		return $wpdb->last_error === '';
	}

	/**
	 * A journal hash stored in an earlier form of the adapter's fingerprint (post hashes up to Llamly 1.0 covered
	 * post_modified), given in the current form, recomputed from the state stored with it. Any other hash is returned as is.
	 *
	 * @param array<string, mixed>|null|false $state
	 */
	public static function currentFormHash(Adapter $adapter, string $stored, array|null|false $state): string
	{
		if (!$adapter instanceof LegacyFingerprint || !is_array($state) || $stored === '') {
			return $stored;
		}
		$current = (string) $adapter->fingerprint($state);
		if (hash_equals($current, $stored) || !hash_equals((string) $adapter->legacyFingerprint($state), $stored)) {
			return $stored;
		}
		return $current;
	}

	/** @return array<string, mixed>|null|false false when the stored state is unreadable; null for an absent object. */
	private static function decodeState(string $json, string $hash, Adapter $adapter): array|null|false
	{
		if ($hash !== '' && str_ends_with($hash, '_' . self::ABSENT)) {
			return null;
		}
		if ($json === '' || $json === '{}') {
			return $hash === '' ? null : false;
		}
		$value = json_decode($json, true);
		return is_array($value) ? $value : false;
	}

	private static function insertOperation(Adapter $adapter, array $w): array|\WP_Error
	{
		global $wpdb;
		$auth = $w['auth'];
		$existing = self::operationByKey((string) $auth['credential_key'], $w['key']);
		if ($existing) {
			return self::existingOperation($adapter, $existing, $w['request_hash']);
		}
		if (strlen((string) $w['expected']) > self::HASH_COLUMN) {
			// wpdb would drop the row without a database error; say why instead (the module contract keeps prefixes short).
			/* translators: 1: object type, 2: maximum hash length. */
			return self::error('hash_too_long', sprintf(__('The revision hash of %1$s items is longer than the journal stores (%2$d characters): the hash prefix of its adapter is too long. No change was made.', 'llamly'), $adapter->type(), self::HASH_COLUMN), 500);
		}
		$id = wp_generate_uuid4();
		// Named after what the agent asked for; a request without a name is labelled once its state is known.
		$label = $w['inverse'] ? null : self::intentLabel(is_array($w['intent']) ? $w['intent'] : []);
		$inserted = $wpdb->insert(Schema::tableNames()['operations'], [
			'id' => $id,
			'blog_id' => get_current_blog_id(),
			'credential_id' => isset($auth['credential_id']) ? (int) $auth['credential_id'] : null,
			'credential_key' => (string) $auth['credential_key'],
			'user_id' => (int) $auth['user_id'],
			'idempotency_key' => $w['key'],
			'request_hash' => $w['request_hash'],
			'tool' => $w['tool'],
			'object_type' => $adapter->type(),
			'object_id' => max(0, $w['object_id']),
			'status' => 'pending',
			'before_data' => '{}',
			'intended_data' => wp_json_encode($w['inverse'] ? ['op' => $w['intent']['op'], 'of' => $w['intent']['of']] : $w['intent']),
			'before_hash' => $w['expected'],
			'undo_of' => $w['undo_of'],
			'redo_of' => $w['redo_of'],
			'batch_id' => $w['batch_id'],
			'generation' => self::currentGeneration(),
			// Microsecond sequence: orders changes made within the same second (batches) newest first.
			'seq' => self::sequence(),
			'created_at' => gmdate('Y-m-d H:i:s'),
			'updated_at' => gmdate('Y-m-d H:i:s'),
			'label' => $label,
		], ['%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
		if ($inserted !== 1) {
			$existing = self::operationByKey((string) $auth['credential_key'], $w['key']);
			if ($existing) {
				return self::existingOperation($adapter, $existing, $w['request_hash']);
			}
			return self::error('journal_unavailable', __('The initial journal row could not be saved. No change was made.', 'llamly'), 503);
		}
		return ['id' => $id];
	}

	/**
	 * Same key and same request, already committed: the original result again, flagged so an agent that did not mean to repeat
	 * the change notices. The confirm retry and the human-approval resume execute for the first time and are never flagged.
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	public static function markReplayed(array $result, string $createdAt): array
	{
		$time = strtotime($createdAt . ' UTC');
		$result['replayed'] = true;
		$result['created_at'] = $time ? gmdate('Y-m-d\TH:i:s\Z', $time) : $createdAt;
		return $result;
	}

	private static function existingOperation(Adapter $adapter, array $existing, string $requestHash): array|\WP_Error
	{
		if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
			return self::error('idempotency_mismatch', __('This idempotency key was already used for a different change.', 'llamly'), 409);
		}
		if ($existing['status'] === 'committed') {
			$result = self::operationResult($adapter, $existing);
			if (is_array($result)) {
				$result = self::markReplayed($result, (string) ($existing['created_at'] ?? ''));
			}
			return ['result' => $result];
		}
		if ($existing['status'] === 'recovery_required') {
			return self::error('recovery_required', __('This operation needs administrator recovery before it can be retried.', 'llamly'), 409);
		}
		if ($existing['status'] === 'aborted' && (string) $existing['error_code'] === 'approval_superseded') {
			return self::supersededError();
		}
		if ($existing['status'] === 'aborted' && (string) $existing['error_code'] === 'approval_expired') {
			return self::expiredError();
		}
		// awaiting_approval is resumed by run() only under the human policy with a valid permit.
		if ($existing['status'] === 'aborted' && (string) $existing['error_code'] !== 'awaiting_approval' && !in_array((string) $existing['error_code'], self::RETRYABLE, true)) {
			return self::error((string) ($existing['error_code'] ?: 'mutation_failed'), __('This idempotent request was already rejected.', 'llamly'), 409);
		}
		return ['id' => (string) $existing['id']];
	}

	private static function markRecoveryRequired(array $operation, array $proof): void
	{
		global $wpdb;
		$id = (string) ($operation['id'] ?? '');
		if ($id === '') {
			return;
		}
		$wpdb->query($wpdb->prepare(
			"UPDATE %i SET status = 'recovery_required', error_code = 'recovery_required', proof = %s, updated_at = %s WHERE id = %s AND status IN ('applying','pending')",
			Schema::tableNames()['operations'],
			wp_json_encode($proof),
			gmdate('Y-m-d H:i:s'),
			$id
		));
	}

	private static function setAborted(string $operationId, string $errorCode, string $type, int $objectId): void
	{
		global $wpdb;
		$wpdb->query($wpdb->prepare(
			"UPDATE %i SET status = 'aborted', error_code = %s, updated_at = %s WHERE id = %s AND status IN ('pending','applying')",
			Schema::tableNames()['operations'],
			sanitize_key($errorCode),
			gmdate('Y-m-d H:i:s'),
			$operationId
		));
		if ($objectId > 0) {
			$wpdb->query($wpdb->prepare("UPDATE %i SET pending_op = NULL, updated_at = %s WHERE object_type = %s AND object_id = %d AND pending_op = %s", Schema::tableNames()['object_versions'], gmdate('Y-m-d H:i:s'), $type, $objectId, $operationId));
		}
	}

	/** Re-read the server and journal switches under a shared row lock so a concurrent pause serializes with this write. */
	private static function lockedWriteGate(?array $policyBasis = null): bool|\WP_Error
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LOCK IN SHARE MODE", Settings::OPTION));
		$value = $raw === null ? null : maybe_unserialize($raw);
		if ($wpdb->last_error !== '' || !is_array($value) || empty($value['enabled']) || empty($value['journal_enabled'])) {
			return self::error('journal_unavailable', __('Llamly or its required journal is paused.', 'llamly'), 503);
		}
		if ($policyBasis !== null && [(int) ($value['revision'] ?? 0), !empty($value['safe_mode']), !empty($value['require_approval'])] !== $policyBasis) {
			return self::error('policy_changed', __('The approval policy changed while this request was being prepared. Repeat the same call with the same idempotency_key.', 'llamly'), 409);
		}
		return true;
	}

	private static function beginTransaction(): bool
	{
		global $wpdb;
		$result = $wpdb->query('START TRANSACTION');
		return $result !== false && $wpdb->last_error === '';
	}

	private static function beginTransactionOrFail(): void
	{
		if (!self::beginTransaction()) {
			throw new MutationFailure('journal_unavailable', esc_html__('The change and journal transaction could not start.', 'llamly'), 503);
		}
	}

	public static function adminContext(int $userId): array
	{
		return [
			'credential_id' => null,
			'credential_key' => 'admin:' . $userId,
			'user_id' => $userId,
			'kind' => 'admin',
			'scope_mode' => 'full',
			'allowed_tools' => [],
		];
	}

	private static function snapshotExpiryAt(): string
	{
		$settings = Settings::get();
		$days = empty($settings['_valid']) ? (int) Settings::defaults()['journal_retention_days'] : (int) $settings['journal_retention_days'];
		return gmdate('Y-m-d H:i:s', time() + max(1, min(90, $days)) * DAY_IN_SECONDS);
	}

	/** @param array<string, mixed> $operation */
	private static function operationExpiry(array $operation): ?int
	{
		$storedExpiry = (string) ($operation['expires_at'] ?? '');
		if ($storedExpiry !== '') {
			$parsed = strtotime($storedExpiry . ' UTC');
			return $parsed === false ? null : $parsed;
		}
		$created = strtotime((string) ($operation['created_at'] ?? '') . ' UTC');
		$settings = Settings::get();
		if ($created === false || empty($settings['_valid'])) {
			return null;
		}
		return $created + (int) $settings['journal_retention_days'] * DAY_IN_SECONDS;
	}

	/** @param array<string, mixed> $operation */
	private static function snapshotExpired(array $operation): bool
	{
		$expiry = self::operationExpiry($operation);
		return $expiry === null || $expiry <= time();
	}

	public static function operation(string $id): ?array
	{
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %s LIMIT 1", Schema::tableNames()['operations'], $id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/** Whether a committed change is currently undone (it has a committed undo that was not redone). */
	public static function isUndone(string $operationId): bool
	{
		$undo = self::latestLinkedOperation($operationId, 'undo_of');
		return $undo !== null && self::latestLinkedOperation((string) $undo['id'], 'redo_of') === null;
	}

	/** Committed change already recorded for this credential and idempotency key (batch admission replays). */
	public static function committedByKey(string $credentialKey, string $key): ?array
	{
		$row = self::operationByKey($credentialKey, $key);
		return $row && $row['status'] === 'committed' ? $row : null;
	}

	private static function operationByKey(string $credentialKey, string $key): ?array
	{
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE credential_key = %s AND idempotency_key = %s LIMIT 1", Schema::tableNames()['operations'], $credentialKey, $key), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/** Guards read only committed links: an aborted attempt must never mask a committed undo or redo. */
	private static function latestLinkedOperation(string $operationId, string $linkColumn): ?array
	{
		global $wpdb;
		$column = $linkColumn === 'redo_of' ? 'redo_of' : 'undo_of';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE %i = %s AND status = 'committed' ORDER BY created_at DESC, seq DESC, id DESC LIMIT 1", Schema::tableNames()['operations'], $column, $operationId), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	private static function operationResult(Adapter $adapter, array $operation): mixed
	{
		$objectId = (int) ($operation['object_id'] ?? 0);
		if ($objectId > 0 && ($operation['status'] ?? '') === 'committed' && ($operation['undo_of'] ?? null) === null && ($operation['redo_of'] ?? null) === null) {
			$adapter->clearMarker($objectId, (string) ($operation['id'] ?? ''));
		}
		$result = json_decode((string) ($operation['result_data'] ?? ''), true);
		return is_array($result) ? $result : self::error('journal_unavailable', __('The stored operation result is invalid.', 'llamly'), 503);
	}

	/** Strictly increasing within a process; microsecond clock across processes. */
	private static function sequence(): int
	{
		static $last = 0;
		$now = (int) floor(microtime(true) * 1000000);
		$last = max($now, $last + 1);
		return $last;
	}

	public static function requestHash(string $tool, array $data): string
	{
		$encoded = wp_json_encode(['tool' => $tool, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return hash('sha256', is_string($encoded) ? $encoded : '');
	}

	public static function idempotencyKey($value): string|\WP_Error
	{
		if (!is_string($value) || strlen($value) < 16 || strlen($value) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
			return self::error('invalid_arguments', __('Use an idempotency key of 16 to 128 letters, numbers, dots, underscores, colons, or dashes.', 'llamly'), 400);
		}
		return $value;
	}

	/**
	 * Cache invalidation after the database outcome is final (COMMIT or ROLLBACK), plus once more at shutdown.
	 *
	 * Residual window (by design, not closable without cache versioning): a reader that read the old value from the
	 * database before the COMMIT and writes it to the object cache after the shutdown invalidation leaves that old
	 * value until the next flush or TTL. The shutdown pass narrows the window, it does not close it.
	 */
	private static function flushAfterOutcome(Adapter $adapter, int ...$ids): void
	{
		$now = [];
		foreach (array_unique($ids) as $id) {
			if ($id < 1) {
				continue;
			}
			$key = $adapter->type() . ':' . $id;
			$now[$key] = [$adapter, $id];
			if (!isset(self::$flushPairs[$key])) {
				if (count(self::$flushPairs) >= self::MAX_FLUSH_PAIRS) {
					// Long loops (WP-CLI, imports): settle what is pending now and start a new set.
					self::settle(self::$flushPairs);
					self::$flushPairs = [];
				}
				self::$flushPairs[$key] = [$adapter, $id];
			}
		}
		if (!self::$shutdownRegistered) {
			self::$shutdownRegistered = true;
			add_action('shutdown', [self::class, 'flushPending'], PHP_INT_MAX);
		}
		// Only this write's own pairs now; the full (deduplicated) set is invalidated once, at shutdown.
		self::flushPairs($now);
	}

	/** Shutdown pass: invalidates every pair touched in this request once more, deduplicated. */
	public static function flushPending(): void
	{
		$pairs = self::$flushPairs;
		self::$flushPairs = [];
		self::settle($pairs);
	}

	/**
	 * Final invalidation of a set of pairs, then the per-request state the adapters kept for them is dropped, so a
	 * long-running process (WP-CLI, imports) does not grow it without bound.
	 *
	 * @param array<string, array{0:Adapter,1:int}> $pairs
	 */
	private static function settle(array $pairs): void
	{
		self::flushPairs($pairs);
		foreach ($pairs as [$adapter, $id]) {
			if (method_exists($adapter, 'forgetRequestState')) {
				$adapter->forgetRequestState($id);
			}
		}
	}

	/** @param array<string, array{0:Adapter,1:int}> $pairs */
	private static function flushPairs(array $pairs): void
	{
		foreach ($pairs as [$adapter, $id]) {
			try {
				$adapter->flushCaches($id);
				do_action('llamly_free_cache_flushed', $adapter->type(), $id);
			} catch (\Throwable $error) {
				// A cache that cannot be cleared must never turn a settled database outcome into a failure.
				unset($error);
			}
		}
	}

	private static function phase(string $phase, string $operationId): void
	{
		do_action('llamly_free_mutation_phase', $phase, $operationId);
	}

	private static function asError(MutationFailure $failure): \WP_Error
	{
		return self::error($failure->errorCode, $failure->reason(), $failure->httpStatus);
	}

	public static function error(string $code, string $message, int $status): \WP_Error
	{
		return new \WP_Error($code, $message, ['status' => $status]);
	}

	private static function notReady(): \WP_Error
	{
		return self::error('write_not_ready', __('Writing is paused until concurrency and journal proof is complete.', 'llamly'), 503);
	}
}
