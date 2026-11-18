<?php

declare(strict_types=1);

namespace Llamly\Free\Mcp;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\History\MutationRunner;

final class Dispatcher
{
	public static function gateways(): array
	{
		return [
			[
				'name' => 'llamly_discover',
				'description' => __('Find Llamly tools available to this connection and their input schemas.', 'llamly'),
				'annotations' => ['title' => 'Discover Llamly tools', 'readOnlyHint' => true, 'openWorldHint' => false],
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'query' => ['type' => 'string', 'maxLength' => 120],
						'category' => ['type' => 'string', 'enum' => self::categories(), 'description' => 'Only tools of this category.' . self::moduleCategoryNotes()],
						'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
					],
					'additionalProperties' => false,
				],
			],
			[
				'name' => 'llamly_run',
				'description' => __('Run one registered Llamly tool after the server checks its schema, scope, WordPress capability, and mutation policy.', 'llamly'),
				'annotations' => ['title' => 'Run a Llamly tool', 'readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'tool' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 64],
						'arguments' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => true],
					],
					'required' => ['tool', 'arguments'],
					'additionalProperties' => false,
				],
			],
		];
	}

	/** @return list<string> Categories of the registered tools, for the discover enum. */
	public static function categories(): array
	{
		$categories = [];
		foreach (ToolRegistry::all() as $definition) {
			$category = (string) ($definition['category'] ?? '');
			if ($category !== '') {
				$categories[$category] = true;
			}
		}
		$names = array_map('strval', array_keys($categories));
		sort($names);
		return $names;
	}

	/** Descriptions of the categories of active add-ons, appended to the category description ('' without add-ons). */
	private static function moduleCategoryNotes(): string
	{
		if (!\Llamly\Free\Modules\ModuleRegistry::ready()) {
			return '';
		}
		$notes = [];
		foreach (\Llamly\Free\Modules\ModuleRegistry::agentGuides() as $guide) {
			if ($guide['tools']) {
				$notes[] = $guide['id'] . ' (' . $guide['label'] . ($guide['category'] !== '' ? ': ' . rtrim($guide['category'], '.') : '') . ')';
			}
		}
		return $notes ? ' Add-on categories: ' . implode('; ', $notes) . '.' : '';
	}

	/**
	 * Tools that active add-ons placed in a core category (ModuleContext::addToCategory), in module id order.
	 *
	 * @return list<string>
	 */
	private static function moduleToolsFor(string $category): array
	{
		if ($category === '' || !\Llamly\Free\Modules\ModuleRegistry::ready()) {
			return [];
		}
		$names = [];
		foreach (\Llamly\Free\Modules\ModuleRegistry::agentGuides() as $guide) {
			foreach ($guide['related'][$category] ?? [] as $tool) {
				$names[] = (string) $tool;
			}
		}
		return array_values(array_unique($names));
	}

	/** Every word of the query must appear (so "create post" finds wp_create_post); underscores count as spaces. */
	private static function matches(string $haystack, string $query): bool
	{
		$haystack = strtolower(str_replace('_', ' ', $haystack));
		foreach (preg_split('/[\s_]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
			if (strpos($haystack, $word) === false) {
				return false;
			}
		}
		return true;
	}

	public static function discover(array $arguments, array $auth): array|\WP_Error
	{
		$definitions = ToolRegistry::visibleTo($auth);
		$query = strtolower(trim((string) ($arguments['query'] ?? '')));
		$category = sanitize_key((string) ($arguments['category'] ?? ''));
		$items = [];
		// A core category also lists the add-on tools placed in it, first, so an agent browsing "gutenberg" sees them.
		$coreCategories = [];
		foreach (ToolRegistry::all() as $definition) {
			if (!isset($definition['module'])) {
				$coreCategories[(string) ($definition['category'] ?? '')] = true;
			}
		}
		$placed = $category !== '' && isset($coreCategories[$category]) ? self::moduleToolsFor($category) : [];
		foreach ($placed as $name) {
			$definition = $definitions[$name] ?? null;
			if ($definition !== null && ($query === '' || self::matches($name . ' ' . (string) ($definition['description'] ?? ''), $query))) {
				unset($definition['capability'], $definition['access'], $definition['readOnly']);
				$items[] = $definition;
			}
		}
		foreach ($definitions as $name => $definition) {
			if ($query !== '' && !self::matches($name . ' ' . (string) ($definition['description'] ?? ''), $query)) {
				continue;
			}
			if ($category !== '' && ($definition['category'] ?? '') !== $category) {
				continue;
			}
			unset($definition['capability'], $definition['access'], $definition['readOnly']);
			$items[] = $definition;
		}
		$page = max(1, (int) ($arguments['page'] ?? 1));
		$perPage = 25;
		return [
			'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
			'page' => $page,
			'per_page' => $perPage,
			'total' => count($items),
			'pages' => (int) max(1, (int) ceil(count($items) / $perPage)),
		];
	}

	public static function run(array $arguments, array $auth): mixed
	{
		$name = (string) ($arguments['tool'] ?? '');
		$toolArguments = $arguments['arguments'] ?? null;
		$definition = ToolRegistry::get($name);
		if (!$definition) {
			return new \WP_Error('tool_not_found', __('The requested tool is not registered.', 'llamly'), ['status' => 404]);
		}
		if (($definition['access'] ?? '') === 'write' && !MutationRunner::writeEnabled()) {
			return new \WP_Error('write_not_ready', __('Writing is paused until the concurrency and journal guarantees pass their required development proof.', 'llamly'), ['status' => 503]);
		}
		// Scope before schema: a tool this credential cannot use stays opaque.
		if (!Authorization::canUseTool($auth, $definition)) {
			return new \WP_Error('forbidden', __('This connected user or credential cannot use the requested tool.', 'llamly'), ['status' => 403]);
		}
		$valid = SchemaValidator::validate($toolArguments, $definition['inputSchema']);
		if (is_wp_error($valid)) {
			return $valid;
		}
		$toolArguments = SchemaValidator::toArrays($toolArguments);
		if (!is_array($toolArguments)) {
			return new \WP_Error('invalid_arguments', __('Tool arguments must be an object.', 'llamly'), ['status' => 400]);
		}
		$objectId = isset($toolArguments['post_id']) ? (int) $toolArguments['post_id'] : null;
		$authorized = Authorization::requireTool($auth, $definition, $objectId);
		if (is_wp_error($authorized)) {
			return $authorized;
		}

		return ToolRegistry::call($name, $toolArguments, $auth);
	}
}
