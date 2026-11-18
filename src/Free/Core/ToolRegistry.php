<?php

declare(strict_types=1);

namespace Llamly\Free\Core;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\History\MutationRunner;

final class ToolRegistry
{
	/** @var array<string, array<string, mixed>> */
	private static array $definitions = [];
	/** @var array<string, callable> */
	private static array $handlers = [];
	private static bool $booted = false;

	public static function boot(): void
	{
		if (self::$booted) {
			return;
		}
		if (did_action('init') === 0) {
			add_action('init', [self::class, 'boot'], 1);
			return;
		}
		self::$booted = true;
		\Llamly\Free\Tools\Catalog::registerTools();
		// Add-on module tools come right after the core and before the legacy hook, so their names (prefixed by the
		// module id) are never taken first. Write tools name an executor; the module never hands over a callable.
		if (\Llamly\Free\Modules\ModuleRegistry::ready()) {
			foreach (\Llamly\Free\Modules\ModuleRegistry::tools() as $tool) {
				self::register($tool['definition'], $tool['handler']);
			}
		}
		do_action('llamly_free_register_tools');
	}

	/**
	 * Read tools register a query handler. Write tools never register a callable: they name a mutation
	 * executor owned by MutationRunner, so every write goes through the journal, permits and CAS.
	 *
	 * @param array<string, mixed> $definition
	 */
	public static function register(array $definition, ?callable $handler = null): bool
	{
		$name = $definition['name'] ?? '';
		if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)
			|| !isset($definition['inputSchema']) || !is_array($definition['inputSchema'])
			|| !isset($definition['capability']) || !is_string($definition['capability'])
			|| !isset($definition['access']) || !in_array($definition['access'], ['read', 'write'], true)
			|| isset(self::$definitions[$name])
		) {
			return false;
		}
		if ($definition['access'] === 'read' && $handler === null) {
			return false;
		}
		if ($definition['access'] === 'write'
			&& ($handler !== null || !is_string($definition['executor'] ?? null) || !MutationRunner::hasExecutor($definition['executor']))
		) {
			return false;
		}

		$definition['readOnly'] = $definition['access'] === 'read';
		// Tools registered without annotations (including add-ons) still get explicit MCP hints.
		if (!isset($definition['annotations']) || !is_array($definition['annotations'])) {
			$definition['annotations'] = \Llamly\Free\Tools\Toolkit::annotations($name, (string) $definition['access']);
		}
		self::$definitions[$name] = $definition;
		if ($handler !== null) {
			self::$handlers[$name] = $handler;
		}
		return true;
	}

	/** @return array<string, array<string, mixed>> */
	public static function all(): array
	{
		self::boot();
		return self::$definitions;
	}

	/** @return array<string, mixed>|null */
	public static function get(string $name): ?array
	{
		self::boot();
		return self::$definitions[$name] ?? null;
	}

	public static function call(string $name, array $arguments, array $context): mixed
	{
		self::boot();
		$definition = self::$definitions[$name] ?? null;
		if ($definition && ($definition['access'] ?? '') === 'write') {
			return MutationRunner::execute((string) $definition['executor'], $name, $arguments, $context);
		}
		$handler = self::$handlers[$name] ?? null;
		if (!$handler) {
			return new \WP_Error('tool_not_found', __('The requested tool is not available.', 'llamly'), ['status' => 404]);
		}
		return $handler($arguments, $context);
	}

	public static function visibleTo(array $auth): array
	{
		$allowed = [];
		foreach (self::all() as $name => $definition) {
			if (($definition['access'] ?? '') === 'write' && !MutationRunner::writeEnabled()) {
				continue;
			}
			if (Authorization::canUseTool($auth, $definition)) {
				$allowed[$name] = $definition;
			}
		}
		return $allowed;
	}
}
