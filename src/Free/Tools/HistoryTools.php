<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\History\Engine;

/**
 * Agent-facing history: list changes and undo/redo them through the same engine and policy as the Activity tab.
 * A connection sees and reverses only its own changes: agent requests never carry manage_options (Auth\CapabilityCeiling),
 * so the administrator branches below only apply if an add-on relaxes the ceiling. Force is never available over MCP.
 */
final class HistoryTools
{
	public static function register(): void
	{
		$uuid = ['type' => 'string', 'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'];
		Toolkit::read('wp_list_changes', 'List journaled changes made through Llamly, newest first, with undo/redo state. Each connection sees its own changes; every change is listed in Llamly > Activity in wp-admin.', 'history', 'edit_posts', [
			'object_type' => ['type' => 'string', 'maxLength' => 48],
			'object_id' => ['type' => 'integer', 'minimum' => 1],
			'tool' => ['type' => 'string', 'maxLength' => 96],
			'batch_id' => $uuid,
			'status' => ['type' => 'string', 'enum' => ['committed', 'aborted', 'recovery_required']],
			'date_from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
			'date_to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
		] + Toolkit::pageProps(), [], [self::class, 'listChanges']);

		Toolkit::write('wp_undo_change', 'Undo one change, several changes (newest first) or a whole batch. An undo is the inverse of the change: undoing a create removes the item it created (wp_redo_change brings it back with the same ID); undoing a delete restores it. Every undo is itself a journaled change and is normally reversible with wp_redo_change while its snapshot is kept (a redo can fail on a conflict); prefer it over wp_delete_post to revert something you just created. Stops at the first conflict and reports what remains. With safe mode on, needs confirm: true; show the user the preview and ask before sending it. If the undo touches public or sensitive content and the optional approval mode is on, it waits for a human in wp-admin.', 'history', 'edit_posts', [
			'change_id' => $uuid,
			'change_ids' => ['type' => 'array', 'items' => $uuid, 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true],
			'batch_id' => $uuid,
		], [], false, [self::class, 'undo']);

		Toolkit::write('wp_redo_change', 'Redo a change that was undone. Pass the ID of the original change or of its undo. If the redo is destructive or irreversible, with safe mode on it needs confirm: true; ask the user before sending it. With the optional approval mode on, a redo that touches public or sensitive content waits for a human in wp-admin.', 'history', 'edit_posts', [
			'change_id' => $uuid,
		], ['change_id'], false, [self::class, 'redo']);
	}

	public static function adapters(): array
	{
		return [];
	}

	public static function listChanges(array $args, array $auth): array
	{
		[$page, $perPage] = Toolkit::page($args);
		$filters = array_intersect_key($args, array_flip(['object_type', 'object_id', 'tool', 'batch_id', 'status', 'date_from', 'date_to']));
		if (!user_can((int) $auth['user_id'], 'manage_options')) {
			// Never empty: an empty filter would mean "all connections".
			$filters['credential'] = (string) ($auth['credential_key'] ?? 'none');
		}
		$listing = Engine::listOperations($page, $perPage, $filters);
		$items = array_map(static function (array $item): array {
			$owner = \Llamly\Free\Modules\ModuleRegistry::ownerOfType((string) $item['object_type']);
			$module = $owner === null ? [] : ['module' => $owner['id'], 'module_label' => $owner['label'], 'module_active' => $owner['active']];
			return $module + [
				'change_id' => $item['id'],
				'tool' => $item['tool'],
				'object_type' => $item['object_type'],
				'object_id' => $item['object_id'],
				'title' => $item['title'],
				'status' => $item['status'],
				'error_code' => $item['error_code'],
				'changes' => $item['changes'],
				'undo_of' => $item['undo_of'],
				'redo_of' => $item['redo_of'],
				'batch_id' => $item['batch_id'],
				'is_current' => $item['after_hash'] !== null && $item['after_hash'] === $item['object_current_hash'],
				'undo_expired' => $item['undo_expired'],
				'created_at' => $item['created_at'],
			];
		}, $listing['items']);
		return Toolkit::listResult($items, $listing['total'], $page, $perPage);
	}

	public static function undo(array $args, array $auth): mixed
	{
		$ids = self::targets($args, $auth);
		if (is_wp_error($ids)) {
			return $ids;
		}
		$key = Engine::idempotencyKey($args['idempotency_key'] ?? null);
		if (is_wp_error($key)) {
			return $key;
		}
		if (!empty($args['dry_run'])) {
			$plans = [];
			foreach ($ids as $id) {
				$plan = Engine::planInverse($id, 'undo', $auth);
				if (is_wp_error($plan)) {
					$plans[] = ['change_id' => $id, 'would_fail' => $plan->get_error_code()];
					continue;
				}
				$policy = Engine::inversePolicy($plan, 'undo', $auth);
				$risk = $plan['adapter']->impact($plan['current'], $plan['target'], ['op' => 'undo']);
				$wouldFail = self::isStale($plan) ? 'stale_revision' : null;
				$plans[] = ['change_id' => $id, 'object_type' => $plan['adapter']->type(), 'object_id' => $plan['object_id'], 'risk' => $risk, 'changes' => Engine::previewInverse($plan, 'undo')['changes'], 'reversible' => empty($risk['irreversible']) && $wouldFail === null, 'reverse_with' => 'wp_redo_change', 'requires_human_approval' => $policy === 'human', 'requires_confirmation' => $policy === 'confirm', 'would_fail' => $wouldFail];
			}
			return ['dry_run' => true, 'plan' => $plans];
		}
		// One confirm: true covers the whole request (every change in it); without it, safe mode refuses the request
		// before the first undo and shows what each change would do.
		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm) {
			$pending = [];
			foreach ($ids as $id) {
				$plan = Engine::planInverse($id, 'undo', $auth);
				if (!is_wp_error($plan) && Engine::inversePolicy($plan, 'undo', $auth) === 'confirm') {
					$preview = Engine::previewInverse($plan, 'undo');
					$pending[] = ['change_id' => $id, 'reversible' => empty($preview['risk']['irreversible']), 'reverse_with' => 'wp_redo_change'] + $preview;
				}
			}
			if ($pending) {
				return self::confirmationError($pending);
			}
		}
		$done = [];
		foreach ($ids as $index => $id) {
			$result = Engine::inverse($id, 'undo', $auth + ['idempotency_key' => $key . ':' . $id], '', $confirm);
			if (is_wp_error($result)) {
				if (count($ids) === 1) {
					return $result;
				}
				return [
					'undone_count' => count($done),
					'undone' => $done,
					'stopped_at' => $id,
					'error' => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'remaining' => array_slice($ids, $index),
				];
			}
			$done[] = ['change_id' => $id, 'undo_change_id' => $result['operation_id'] ?? null, 'revision_hash' => $result['revision_hash'] ?? null];
		}
		return count($ids) === 1 ? $result : ['undone_count' => count($done), 'undone' => $done, 'remaining' => []];
	}

	public static function redo(array $args, array $auth): mixed
	{
		$key = Engine::idempotencyKey($args['idempotency_key'] ?? null);
		if (is_wp_error($key)) {
			return $key;
		}
		$id = (string) ($args['change_id'] ?? '');
		$operation = Engine::operation($id);
		if ($operation && $operation['undo_of'] !== null) {
			$id = (string) $operation['undo_of'];
		}
		if (!empty($args['dry_run'])) {
			$plan = Engine::planInverse($id, 'redo', $auth);
			return is_wp_error($plan) ? ['dry_run' => true, 'would_fail' => $plan->get_error_code()] : ['dry_run' => true, 'change_id' => $id, 'object_type' => $plan['adapter']->type(), 'object_id' => $plan['object_id'], 'risk' => $plan['adapter']->impact($plan['current'], $plan['target'], ['op' => 'redo']), 'requires_human_approval' => Engine::inversePolicy($plan, 'redo', $auth) === 'human', 'requires_confirmation' => Engine::inversePolicy($plan, 'redo', $auth) === 'confirm', 'would_fail' => self::isStale($plan) ? 'stale_revision' : null];
		}
		return Engine::inverse($id, 'redo', $auth + ['idempotency_key' => $key], '', ($args['confirm'] ?? false) === true);
	}

	/** @param list<array<string, mixed>> $pending Per-change previews of what safe mode wants confirmed. */
	private static function confirmationError(array $pending): \WP_Error
	{
		return new \WP_Error('confirmation_required', __('Safe mode is on and undoing needs explicit confirmation. Nothing was changed. Ask the user whether to go ahead; only if they agree, repeat the same call with the same idempotency_key and confirm: true (one confirm covers every change in this request).', 'llamly'), [
			'status' => 409,
			'requires' => 'confirm',
			'requires_confirmation' => true,
			'preview' => ['changes' => $pending],
		]);
	}

	/** @return list<string>|\WP_Error Change IDs ordered newest first. */
	private static function targets(array $args, array $auth): array|\WP_Error
	{
		$given = (int) isset($args['change_id']) + (int) isset($args['change_ids']) + (int) isset($args['batch_id']);
		if ($given !== 1) {
			return Toolkit::error('invalid_arguments', 'Pass exactly one of change_id, change_ids or batch_id.');
		}
		if (isset($args['change_id'])) {
			return [(string) $args['change_id']];
		}
		$filters = isset($args['batch_id']) ? ['batch_id' => (string) $args['batch_id'], 'status' => 'committed'] : ['status' => 'committed'];
		if (!user_can((int) $auth['user_id'], 'manage_options')) {
			// Never empty: an empty filter would mean "all connections".
			$filters['credential'] = (string) ($auth['credential_key'] ?? 'none');
		}
		$wanted = isset($args['change_ids']) ? array_map('strval', (array) $args['change_ids']) : null;
		$ordered = [];
		for ($page = 1; $page <= 50; $page++) {
			$listing = Engine::listOperations($page, 100, $filters);
			foreach ($listing['items'] as $item) {
				if ($item['undo_of'] !== null || $item['redo_of'] !== null) {
					continue;
				}
				if ($wanted === null || in_array($item['id'], $wanted, true)) {
					$ordered[] = (string) $item['id'];
				}
			}
			if ($page * 100 >= $listing['total']) {
				break;
			}
		}
		// Repeating a batch undo resumes where it stopped: changes that are currently undone are skipped.
		// Undo rows carry no batch_id, so ask the journal directly which changes are currently undone.
		$ordered = array_values(array_filter($ordered, static fn(string $id): bool => !Engine::isUndone($id)));
		if ($wanted !== null) {
			$wanted = array_values(array_filter($wanted, static fn(string $id): bool => !Engine::isUndone($id)));
		}
		if ($wanted !== null && count($ordered) !== count($wanted)) {
			return Toolkit::error('operation_not_found', 'One or more change IDs were not found among the changes this connection can undo.', 404);
		}
		if (!$ordered) {
			return Toolkit::error('operation_not_found', 'No committed changes were found for this batch.', 404);
		}
		return $ordered;
	}

	private static function isStale(array $plan): bool
	{
		$current = $plan['adapter']->fingerprint($plan['current']);
		return $current === null || !hash_equals($plan['expected'], $current);
	}
}
