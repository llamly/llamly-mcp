<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationRunner;

/**
 * Shared building blocks for domain tools: closed schemas, the write envelope, pagination and registration.
 *
 * Every write tool accepts the envelope `dry_run`, `idempotency_key` and (for existing objects) `expected_hash`,
 * builds an intent and hands it to Toolkit::write(), which runs Engine::write(). Tools never touch WordPress directly
 * for a write; only the domain adapter does, inside the engine's transaction.
 */
final class Toolkit
{
	public const HASH_PATTERN = '^[a-z0-9]{1,4}_[a-f0-9]{64}$';
	public const MAX_PER_PAGE = 100;
	/** Pure creations: the only writes whose MCP destructiveHint is false ("additive changes only"). Every other write may overwrite or remove something. */
	private const ADDITIVE = ['wp_create_post', 'wp_create_term', 'wp_create_comment', 'wp_create_pattern', 'wp_upload_media', 'wp_upload_media_start', 'wp_upload_media_part', 'wp_upload_media_finish'];
	/** Writes whose repeat with the same arguments changes nothing more (MCP idempotentHint). Creates and uploads are not. */
	private const NOT_IDEMPOTENT_PREFIXES = ['wp_create_', 'wp_upload_', 'wp_undo_', 'wp_redo_', 'wp_restore_'];

	/** @param array<string, array<string, mixed>> $properties @param list<string> $required */
	public static function schema(array $properties, array $required = []): array
	{
		$schema = ['type' => 'object', 'properties' => $properties ?: (object) [], 'additionalProperties' => false];
		if ($required) {
			$schema['required'] = array_values($required);
		}
		return $schema;
	}

	/**
	 * MCP tool annotations derived from the tool's access and name, so every tool carries explicit hints.
	 *
	 * @return array<string, bool>
	 */
	public static function annotations(string $name, string $access): array
	{
		if ($access === 'read') {
			// wp_batch_execute is registered as a read tool but runs writes (each one checked and journaled on its own).
			if ($name === 'wp_batch_execute') {
				return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
			}
			return ['readOnlyHint' => true, 'openWorldHint' => false];
		}
		$idempotent = true;
		foreach (self::NOT_IDEMPOTENT_PREFIXES as $prefix) {
			if (strpos($name, $prefix) === 0) {
				$idempotent = false;
			}
		}
		return ['readOnlyHint' => false, 'destructiveHint' => !in_array($name, self::ADDITIVE, true), 'idempotentHint' => $idempotent, 'openWorldHint' => false];
	}

	/** @return array<string, array<string, mixed>> */
	public static function pageProps(int $defaultPerPage = 20): array
	{
		return [
			'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
			'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => $defaultPerPage],
		];
	}

	/** Envelope merged into every write tool. @return array<string, array<string, mixed>> */
	public static function writeProps(bool $existing): array
	{
		$props = [
			'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Return the plan, risk and current hash without changing anything.'],
			'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$'],
			'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Set to true only after the user agreed, when safe mode asks for confirmation (the error carries a preview). It never replaces a human approval.'],
		];
		if ($existing) {
			$props['expected_hash'] = ['type' => 'string', 'pattern' => self::HASH_PATTERN, 'description' => 'revision_hash returned by the latest read of this item.'];
		}
		return $props;
	}

	/** @return list<string> */
	public static function writeRequired(bool $existing): array
	{
		return $existing ? ['idempotency_key', 'expected_hash'] : ['idempotency_key'];
	}

	/** @return array{0:int,1:int} page, per_page */
	public static function page(array $args, int $defaultPerPage = 20): array
	{
		$page = max(1, (int) ($args['page'] ?? 1));
		$perPage = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? $defaultPerPage)));
		return [$page, $perPage];
	}

	/** @param list<mixed> $items @return array<string, mixed> */
	public static function listResult(array $items, int $total, int $page, int $perPage): array
	{
		return ['items' => array_values($items), 'total' => $total, 'pages' => (int) max(1, (int) ceil($total / max(1, $perPage))), 'page' => $page, 'per_page' => $perPage];
	}

	/**
	 * Register a read tool.
	 *
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 */
	public static function read(string $name, string $description, string $category, string $capability, array $properties, array $required, callable $handler): bool
	{
		return ToolRegistry::register([
			'name' => $name,
			'description' => $description,
			'category' => $category,
			'access' => 'read',
			'capability' => $capability,
			'inputSchema' => self::schema($properties, $required),
			'annotations' => self::annotations($name, 'read'),
		], $handler);
	}

	/**
	 * Register a write tool backed by an executor owned by a Llamly Free class.
	 *
	 * @param array<string, array<string, mixed>> $properties Tool-specific properties (the envelope is added here).
	 * @param list<string> $required
	 * @param array{0:class-string,1:string} $executor
	 */
	public static function write(string $name, string $description, string $category, string $capability, array $properties, array $required, bool $existing, array $executor): bool
	{
		$executorName = str_replace('_', '', $category) . '.' . $name;
		if (!MutationRunner::hasExecutor($executorName) && !MutationRunner::registerExecutor($executorName, $executor)) {
			return false;
		}
		return ToolRegistry::register([
			'name' => $name,
			'description' => $description,
			'category' => $category,
			'access' => 'write',
			'capability' => $capability,
			'inputSchema' => self::schema($properties + self::writeProps($existing), array_values(array_unique(array_merge($required, self::writeRequired($existing))))),
			'executor' => $executorName,
			'annotations' => self::annotations($name, 'write'),
		]);
	}

	/**
	 * Run a domain write through the engine.
	 *
	 * @param array<string, mixed> $args Raw tool arguments (envelope included).
	 * @param array<string, mixed> $auth
	 * @param array<string, mixed> $intent JSON-serializable intent understood by the adapter.
	 */
	public static function run(string $type, string $tool, int $objectId, array $args, array $auth, array $intent, string $event): mixed
	{
		$adapter = Engine::adapter($type);
		if (!$adapter) {
			return Engine::error('tool_not_found', __('This kind of item cannot be changed through Llamly.', 'llamly'), 404);
		}
		$key = Engine::idempotencyKey($args['idempotency_key'] ?? null);
		if (is_wp_error($key)) {
			return $key;
		}
		$expected = null;
		if ($objectId > 0) {
			$expected = (string) ($args['expected_hash'] ?? '');
			if (!preg_match('/' . self::HASH_PATTERN . '/', $expected)) {
				return Engine::error('stale_revision', __('Send the revision_hash from your latest read of this item as expected_hash.', 'llamly'), 409);
			}
		}
		return Engine::write($adapter, [
			'tool' => $tool,
			'object_id' => $objectId,
			'expected_hash' => $expected,
			'intent' => $intent,
			'auth' => $auth,
			'idempotency_key' => $key,
			'request_hash' => Engine::requestHash($tool, ['object_id' => $objectId, 'expected_hash' => $expected, 'intent' => $intent]),
			'event' => $event,
			'batch_id' => isset($auth['batch_id']) && is_string($auth['batch_id']) ? $auth['batch_id'] : null,
			'dry_run' => !empty($args['dry_run']),
			// A UX brake the agent controls: it is not part of the request hash and never skips a human approval.
			'confirm' => ($args['confirm'] ?? false) === true,
		]);
	}

	public static function error(string $code, string $message, int $status = 400): \WP_Error
	{
		return Engine::error($code, $message, $status);
	}

	/** Revision hash of an object for read results (same value expected_hash must carry). */
	public static function hashOf(string $type, int $id): ?string
	{
		$adapter = Engine::adapter($type);
		return $adapter ? $adapter->fingerprint($adapter->capture($id)) : null;
	}
}
