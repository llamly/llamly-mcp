<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\Mcp\SchemaValidator;

/**
 * wp_batch_execute: up to 50 calls (20 writes), admitted as a whole before the first one runs, then executed in order.
 * Each write is its own journaled change tagged with the batch ID; there is no cross-domain atomicity (only
 * wp_edit_blocks is all-or-nothing by contract). wp_undo_change {batch_id} reverses a batch newest first.
 */
final class BatchTools
{
	private const REFUSED = ['wp_batch_execute', 'llamly_run', 'llamly_discover', 'wp_undo_change', 'wp_redo_change'];
	private const MAX_WRITES = 20;
	private static bool $running = false;

	public static function register(): void
	{
		Toolkit::read('wp_batch_execute', 'Run several Llamly tools in one request. All calls are validated (scope, schema, safe mode) before the first runs; writes run in order, each journaled with a shared batch_id. on_error=stop (default) skips the rest after a failure. With safe mode on, each destructive call carries its own confirm: true. Calls that wait for a human in wp-admin must be run on their own.', 'history', 'edit_posts', [
			'calls' => [
				'type' => 'array',
				'minItems' => 1,
				'maxItems' => 50,
				'items' => [
					'type' => 'object',
					'additionalProperties' => false,
					'required' => ['name', 'arguments'],
					'properties' => [
						'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,63}$'],
						'arguments' => ['type' => 'object'],
					],
				],
			],
			'on_error' => ['type' => 'string', 'enum' => ['stop', 'continue'], 'default' => 'stop'],
			'dry_run' => ['type' => 'boolean', 'default' => false],
		], ['calls'], [self::class, 'execute']);
	}

	public static function adapters(): array
	{
		return [];
	}

	public static function execute(array $args, array $auth): array|\WP_Error
	{
		if (self::$running || !empty($auth['batch_id'])) {
			return Toolkit::error('batch_recursion', 'A batch cannot run another batch.', 400);
		}
		self::$running = true;
		try {
			return self::run($args, $auth);
		} finally {
			self::$running = false;
		}
	}

	private static function run(array $args, array $auth): array|\WP_Error
	{
		$calls = array_values((array) ($args['calls'] ?? []));
		$stopOnError = ($args['on_error'] ?? 'stop') !== 'continue';
		$dryRun = !empty($args['dry_run']);
		$batchId = wp_generate_uuid4();

		// Admission: every call is checked before any runs.
		$admitted = [];
		$writes = 0;
		$keys = [];
		$objects = [];
		foreach ($calls as $index => $call) {
			$name = (string) ($call['name'] ?? '');
			$callArgs = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
			if (in_array($name, self::REFUSED, true)) {
				return self::admissionError($index, $name, 'not_allowed_in_batch', 'This tool cannot run inside a batch.');
			}
			$definition = ToolRegistry::get($name);
			if (!$definition) {
				return self::admissionError($index, $name, 'tool_not_found', 'The tool is not registered.', ['tool' => $name]);
			}
			if (!Authorization::canUseTool($auth, $definition)) {
				return self::admissionError($index, $name, 'forbidden', 'This connection cannot use the tool.');
			}
			$valid = SchemaValidator::validate($callArgs, $definition['inputSchema']);
			if (is_wp_error($valid)) {
				return self::admissionError($index, $name, 'invalid_arguments', $valid->get_error_message());
			}
			$isWrite = ($definition['access'] ?? '') === 'write';
			if ($isWrite && ++$writes > self::MAX_WRITES) {
				return self::admissionError($index, $name, 'too_many_writes', 'A batch can contain at most 20 writes.');
			}
			if ($isWrite) {
				$key = (string) ($callArgs['idempotency_key'] ?? '');
				if ($key !== '' && isset($keys[$key])) {
					return self::admissionError($index, $name, 'duplicate_idempotency_key', 'Each write in a batch needs its own idempotency_key.');
				}
				$keys[$key] = true;
				// A retried batch replays the writes that already committed instead of failing on their new hash.
				if ($key !== '' && \Llamly\Free\History\Engine::committedByKey((string) ($auth['credential_key'] ?? ''), $key)) {
					$admitted[] = ['index' => $index, 'name' => $name, 'arguments' => $callArgs, 'write' => true, 'plan' => ['replay' => true]];
					continue;
				}
				$plan = ToolRegistry::call($name, ['dry_run' => true] + $callArgs, $auth);
				if (is_wp_error($plan)) {
					return self::admissionError($index, $name, $plan->get_error_code(), $plan->get_error_message());
				}
				if (!empty($plan['requires_human_approval'])) {
					return self::admissionError($index, $name, 'confirmation_required', 'This call needs a site administrator approval. Run it on its own to get an approval_id; nothing in this batch was changed.', [
						'requires' => 'human',
						'risk' => $plan['risk'] ?? null,
						'preview' => ['risk' => $plan['risk'] ?? null, 'changes' => $plan['changes'] ?? [], 'plan' => $plan['plan'] ?? null, 'current_hash' => $plan['current_hash'] ?? null],
					]);
				}
				// Safe mode: each destructive or irreversible call carries its own confirm: true; one call's confirm never covers another.
				if (!empty($plan['requires_confirmation']) && ($callArgs['confirm'] ?? false) !== true) {
					return self::admissionError($index, $name, 'confirmation_required', 'Safe mode is on and this call needs confirm: true in its own arguments. Ask the user first; nothing in this batch was changed.', [
						'requires' => 'confirm',
						'requires_confirmation' => true,
						'risk' => $plan['risk'] ?? null,
						'preview' => ['risk' => $plan['risk'] ?? null, 'changes' => $plan['changes'] ?? [], 'plan' => $plan['plan'] ?? null, 'current_hash' => $plan['current_hash'] ?? null, 'object_type' => $plan['object_type'] ?? null, 'object_id' => $plan['object_id'] ?? null],
					]);
				}
				if (!empty($plan['would_fail'])) {
					return self::admissionError($index, $name, (string) $plan['would_fail'], 'The call would fail with its current arguments.');
				}
				// Two writes to one existing object would share an expected_hash; the second always fails mid-batch.
				$objectKey = isset($plan['object_type'], $plan['object_id']) && (int) $plan['object_id'] > 0 ? $plan['object_type'] . ':' . (int) $plan['object_id'] : '';
				if ($objectKey !== '' && isset($objects[$objectKey])) {
					return self::admissionError($index, $name, 'duplicate_object', 'A batch can change each existing item only once. Combine the changes or run them in separate calls.');
				}
				if ($objectKey !== '') {
					$objects[$objectKey] = true;
				}
			}
			$admitted[] = ['index' => $index, 'name' => $name, 'arguments' => $callArgs, 'write' => $isWrite, 'plan' => $isWrite ? $plan : null];
		}

		if ($dryRun) {
			return ['dry_run' => true, 'batch_id' => null, 'total' => count($admitted), 'items' => array_map(static fn(array $item): array => ['index' => $item['index'], 'tool' => $item['name'], 'write' => $item['write'], 'plan' => $item['plan']], $admitted)];
		}

		$items = [];
		$executed = 0;
		$stoppedAt = null;
		foreach ($admitted as $item) {
			if ($stoppedAt !== null) {
				$items[] = ['index' => $item['index'], 'tool' => $item['name'], 'status' => 'skipped'];
				continue;
			}
			$result = ToolRegistry::call($item['name'], $item['arguments'], $auth + ['batch_id' => $batchId]);
			$executed++;
			if (is_wp_error($result)) {
				$items[] = ['index' => $item['index'], 'tool' => $item['name'], 'status' => 'error', 'error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()]];
				if ($stopOnError) {
					$stoppedAt = $item['index'];
				}
				continue;
			}
			$items[] = ['index' => $item['index'], 'tool' => $item['name'], 'status' => 'ok', 'change_id' => is_array($result) ? ($result['operation_id'] ?? null) : null, 'result' => $result];
		}
		$out = ['batch_id' => $batchId, 'executed' => $executed, 'total' => count($admitted), 'items' => $items];
		if ($stoppedAt !== null) {
			$out['stopped_at'] = $stoppedAt;
		}
		return $out;
	}

	/** @param array<string, mixed> $extra */
	private static function admissionError(int $index, string $name, string $code, string $message, array $extra = []): \WP_Error
	{
		return new \WP_Error($code, sprintf('Batch call %d (%s): %s Nothing in the batch was run.', $index, $name, $message), ['status' => 400, 'index' => $index] + $extra);
	}
}
