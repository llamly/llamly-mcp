<?php

declare(strict_types=1);

namespace Llamly\Free\Core;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Auth\CapabilityCeiling;
use Llamly\Free\Mcp\Dispatcher;

/**
 * SET-11: exposes Llamly's own tools as WordPress Abilities (WP 6.9+) when the administrator turns it on.
 * Each ability runs through the same Dispatcher as MCP: schema, scope, WordPress capability, journal, undo and the
 * approval policy (decision 28): confirm: true in safe mode for destructive and irreversible changes, and the
 * optional human queue for publishing and settings. The WordPress permission check of the Abilities API
 * never replaces that policy, and the call runs under the same capability ceiling as MCP (CapabilityCeiling).
 * Abilities from other plugins are never consumed.
 */
final class Abilities
{
	public const CATEGORY = 'llamly';

	public static function available(): bool
	{
		return function_exists('wp_register_ability') && function_exists('wp_register_ability_category');
	}

	public static function registerHooks(): void
	{
		if (!self::available() || empty(Settings::get()['abilities_enabled'])) {
			return;
		}
		add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
		add_action('wp_abilities_api_init', [self::class, 'registerAbilities']);
	}

	public static function registerCategory(): void
	{
		wp_register_ability_category(self::CATEGORY, [
			'label' => __('Llamly', 'llamly'),
			'description' => __('Reversible WordPress tools from Llamly. Every change is journaled and can be undone in Llamly › Activity.', 'llamly'),
		]);
	}

	public static function registerAbilities(): void
	{
		foreach (ToolRegistry::all() as $name => $definition) {
			if (in_array($name, ['wp_batch_execute'], true)) {
				continue;
			}
			$schema = $definition['inputSchema'];
			if (isset($schema['properties']) && $schema['properties'] === []) {
				$schema['properties'] = (object) [];
			}
			$write = ($definition['access'] ?? '') === 'write';
			wp_register_ability('llamly/' . str_replace('_', '-', $name), [
				'label' => ucfirst(str_replace(['wp_', '_'], ['', ' '], $name)),
				'description' => (string) ($definition['description'] ?? $name),
				'category' => self::CATEGORY,
				'input_schema' => $schema,
				'execute_callback' => static fn($input = null) => self::execute($name, $input),
				'permission_callback' => static fn($input = null) => self::permitted($name),
				'meta' => [
					'annotations' => ['readonly' => !$write, 'destructive' => $write, 'idempotent' => !$write],
					'public' => true,
					'show_in_rest' => true,
				],
			]);
		}
	}

	/** The current WordPress user acts with their own content capabilities; the key keeps their changes apart in the journal. */
	private static function context(): array
	{
		$userId = get_current_user_id();
		return [
			'kind' => 'ability',
			'user_id' => $userId,
			'credential_id' => null,
			'credential_key' => 'ability:' . $userId,
			'scope_mode' => 'full',
			'allowed_tools' => [],
		];
	}

	private static function permitted(string $name): bool
	{
		$definition = ToolRegistry::get($name);
		if ($definition === null || get_current_user_id() < 1) {
			return false;
		}
		$context = self::context();
		return CapabilityCeiling::run($context, static fn(): bool => Authorization::canUseTool($context, $definition));
	}

	private static function execute(string $name, mixed $input): mixed
	{
		if (get_current_user_id() < 1) {
			return new \WP_Error('forbidden', __('Sign in to use Llamly abilities.', 'llamly'), ['status' => 401]);
		}
		$arguments = is_array($input) || is_object($input) ? $input : [];
		$context = self::context();
		$result = CapabilityCeiling::run($context, static fn(): mixed => Dispatcher::run(['tool' => $name, 'arguments' => $arguments], $context));
		// LLP-476: same Llamly Pro line as the MCP endpoint for a refusal Llamly Pro covers.
		return is_wp_error($result) ? \Llamly\Free\Pro\ProFeatures::decorate($result, $name) : $result;
	}
}
