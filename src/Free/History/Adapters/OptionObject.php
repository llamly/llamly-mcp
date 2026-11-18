<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\History\Adapter;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\State;

/**
 * Basic site options. Closed allowlist: the object ID of an option is its fixed 1-based position
 * in NAMES, so the list may only grow at the end. State is the raw wp_options row (value as stored plus autoload), so
 * filters on option_* never leak into the fingerprint and restore returns the exact row. Options are never created
 * through Llamly: an absent option is simply a state with exists=false.
 */
final class OptionObject implements Adapter
{
	/** Index = object ID - 1. Never reorder or remove. */
	public const NAMES = [
		'blogname',
		'blogdescription',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'posts_per_page',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
	];

	/** Impact flags per option: P = public, S = sensitive. */
	private const PUBLIC = ['blogname', 'blogdescription', 'date_format', 'time_format', 'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts'];
	private const SENSITIVE = ['timezone_string', 'gmt_offset', 'show_on_front', 'page_on_front', 'page_for_posts'];

	public static function idOf(string $name): int
	{
		$index = array_search($name, self::NAMES, true);
		return $index === false ? 0 : $index + 1;
	}

	public static function nameOf(int $id): ?string
	{
		return self::NAMES[$id - 1] ?? null;
	}

	public function type(): string
	{
		return 'option';
	}

	public function prefix(): string
	{
		return 'o1';
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		$name = self::nameOf($id);
		if ($name === null) {
			return null;
		}
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE", $name), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name), ARRAY_A);
		if ($wpdb->last_error !== '') {
			return null;
		}
		if (!is_array($row)) {
			return ['name' => $name, 'exists' => false, 'raw_value' => null, 'autoload' => null];
		}
		return ['name' => $name, 'exists' => true, 'raw_value' => (string) $row['option_value'], 'autoload' => (string) $row['autoload']];
	}

	public function fingerprint(?array $state): ?string
	{
		return State::hash('o1', $state);
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		$name = self::nameOf($id);
		if ($name === null || ($intent['name'] ?? null) !== $name || !array_key_exists('value', $intent) || !is_scalar($intent['value'])) {
			throw new MutationFailure('invalid_arguments', esc_html__('The option change is not valid.', 'llamly'), 400);
		}
		$value = (string) $intent['value'];
		// Core site option journaled by Llamly 1.0.1 (nameOf() only knows that list); used by undo/redo in Activity.
		$updated = update_option($name, $value);
		if (!$updated) {
			// update_option() returns false for "same value" and for failures; only a stored mismatch is a failure.
			$state = $this->capture($id);
			if ($state === null || !$state['exists'] || $state['raw_value'] !== $value) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not save the option.', 'llamly'), 500);
			}
		}
		$this->flush($name);
		return $id;
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		global $wpdb;
		$name = self::nameOf($id);
		if ($name === null || $target === null || ($target['name'] ?? null) !== $name) {
			throw new MutationFailure('undo_conflict', esc_html__('The option snapshot does not match this option.', 'llamly'), 409);
		}
		if (empty($target['exists'])) {
			if ($current !== null && !empty($current['exists'])) {
				delete_option($name);
				if ($wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']) === false) {
					throw new MutationFailure('journal_unavailable', esc_html__('The option could not be removed.', 'llamly'), 503);
				}
			}
			$this->flush($name);
			return $id;
		}
		$raw = (string) $target['raw_value'];
		$autoload = (string) $target['autoload'];
		if ($current !== null && !empty($current['exists'])) {
			update_option($name, maybe_unserialize($raw));
		} else {
			add_option($name, maybe_unserialize($raw), '', $autoload);
		}
		// Row fixup: hooks and sanitizers may rewrite the value or autoload flag; the journaled row wins.
		$fixed = $wpdb->update($wpdb->options, ['option_value' => $raw, 'autoload' => $autoload], ['option_name' => $name], ['%s', '%s'], ['%s']);
		if ($fixed === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The option row could not be restored.', 'llamly'), 503);
		}
		$this->flush($name);
		return $id;
	}

	private function flush(string $name): void
	{
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
		wp_cache_delete($name, 'options');
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		return !empty($current['exists'])
			&& ($current['name'] ?? null) === ($intent['name'] ?? '')
			&& (string) $current['raw_value'] === (string) ($intent['value'] ?? '');
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$name = (string) ($intent['name'] ?? $before['name'] ?? $after['name'] ?? '');
		$known = in_array($name, self::NAMES, true);
		// An unknown name can never reach here, but an unclassified option must fail closed.
		$public = !$known || in_array($name, self::PUBLIC, true);
		$sensitive = !$known || in_array($name, self::SENSITIVE, true);
		return [
			'destructive' => false,
			'public' => $public,
			'sensitive' => $sensitive,
			'external_effects' => $public ? ['page caches, search engines and feeds may keep the previous value'] : [],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		return self::nameOf($id) !== null && user_can($userId, 'manage_options');
	}

	public function label(?array $state): string
	{
		return $state === null ? __('Site option', 'llamly') : (string) ($state['name'] ?? __('Site option', 'llamly'));
	}

	public function tables(): array
	{
		global $wpdb;
		return [$wpdb->options];
	}

	public function findMarked(string $operationId): array
	{
		return [];
	}

	public function clearMarker(int $id, string $operationId): void
	{
	}

	public function flushCaches(int $id): void
	{
		$name = self::nameOf($id);
		if ($name !== null) {
			$this->flush($name);
		}
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		return [
			'option_name' => self::nameOf($id),
			'changed' => true,
			'previous_value' => $before !== null && !empty($before['exists']) ? $before['raw_value'] : null,
			'option_value' => $after !== null && !empty($after['exists']) ? $after['raw_value'] : null,
		];
	}
}
