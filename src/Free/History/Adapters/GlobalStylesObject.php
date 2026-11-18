<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\History\Adapter;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\State;

/**
 * User global styles of the active theme (wp_global_styles post, S_GSTYLES).
 *
 * The state keeps the stored post_content bytes (restores are exact); the hash uses the canonical JSON so key order
 * does not make a read stale. A theme without a user styles post gets one created by the first update (op=create);
 * undoing it deletes the post and the theme defaults apply again.
 *
 * Intents: create {op, theme, content}, update {op, content}. The tool validates and merges before the intent exists;
 * the adapter re-checks capabilities under the engine lock. Custom CSS (`css` in any style node) is never written:
 * apply and restore refuse any change to it.
 */
final class GlobalStylesObject implements Adapter
{
	public const TYPES = ['wp_global_styles'];
	public const PREFIX = 'g1';

	public function type(): string
	{
		return 'global_styles';
	}

	public function prefix(): string
	{
		return self::PREFIX;
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		$state = PostAdapter::capture($id, $forUpdate, self::TYPES);
		if ($state === null) {
			return null;
		}
		unset($state['comments'], $state['sticky']);
		return $state;
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return self::PREFIX . '_' . Engine::ABSENT;
		}
		$fields = (array) ($state['fields'] ?? []);
		$fields['post_content'] = self::canonicalJson((string) ($fields['post_content'] ?? ''));
		return State::hash(self::PREFIX, [
			'post_id' => (int) ($state['post_id'] ?? 0),
			'fields' => $fields,
			'meta' => $state['meta'] ?? [],
			'terms' => $state['terms'] ?? [],
		]);
	}

	public static function canonicalJson(string $content): string
	{
		$decoded = json_decode($content, true);
		if (!is_array($decoded)) {
			return $content;
		}
		$encoded = wp_json_encode(State::canonical($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		return is_string($encoded) ? $encoded : $content;
	}

	/** User styles post of a theme, from the database (null when the theme has none). */
	public static function postIdFor(string $theme, bool $forUpdate = false): ?int
	{
		global $wpdb;
		$id = $forUpdate
			? $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				 WHERE p.post_type = 'wp_global_styles' AND p.post_status = 'publish'
				 ORDER BY p.ID DESC LIMIT 1 FOR UPDATE",
				$theme
			))
			: $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				 WHERE p.post_type = 'wp_global_styles' AND p.post_status = 'publish'
				 ORDER BY p.ID DESC LIMIT 1",
				$theme
			));
		return $id === null ? null : (int) $id;
	}

	/** Whether a decoded user theme.json contains custom CSS in any style node. */
	public static function hasCss(?array $data): bool
	{
		foreach (self::cssOf($data) as $value) {
			if ($value !== '' && $value !== null) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed>|null $state */
	public static function decode(?array $state): ?array
	{
		if ($state === null) {
			return null;
		}
		$decoded = json_decode((string) ($state['fields']['post_content'] ?? ''), true);
		return is_array($decoded) ? $decoded : [];
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		$userId = (int) ($auth['user_id'] ?? 0);
		$content = (string) ($intent['content'] ?? '');
		if (!user_can($userId, 'edit_theme_options')) {
			throw new MutationFailure('forbidden', esc_html__('The connected user cannot change the global styles.', 'llamly'), 403);
		}
		self::refuseCssChange($content, $id > 0 ? $this->capture($id) : null);
		$op = (string) ($intent['op'] ?? '');
		if ($op === 'create') {
			$theme = (string) ($intent['theme'] ?? '');
			if ($theme !== get_stylesheet()) {
				throw new MutationFailure('not_active_theme', esc_html__('Only the global styles of the active theme can be changed.', 'llamly'), 409);
			}
			if (self::postIdFor($theme, true) !== null) {
				throw new MutationFailure('stale_revision', esc_html__('The global styles were saved after the caller read them. Read them again before updating.', 'llamly'), 409);
			}
			$created = wp_insert_post(wp_slash([
				'post_type' => 'wp_global_styles',
				'post_status' => 'publish',
				'post_title' => 'Custom Styles',
				'post_name' => sprintf('wp-global-styles-%s', urlencode($theme)),
				'post_content' => $content,
				'meta_input' => [PostObject::MARKER => $operationId],
			]), true);
			if (is_wp_error($created)) {
				return $created;
			}
			$id = (int) $created;
			if ($id < 1) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not create the global styles.', 'llamly'), 500);
			}
			$set = wp_set_object_terms($id, $theme, 'wp_theme', false);
			if (is_wp_error($set)) {
				throw new MutationFailure('mutation_failed', esc_html($set->get_error_message()), 500);
			}
		} elseif ($op === 'update') {
			clean_post_cache($id);
			$updated = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $content]), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			if ((int) $updated !== $id) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not update the global styles.', 'llamly'), 500);
			}
		} else {
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown global styles operation.', 'llamly'), 400);
		}
		clean_post_cache($id);
		self::flush();
		return $id;
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		// An undo or redo never brings back or removes custom CSS: a change made before Llamly refused CSS stays as it is.
		self::refuseCssChange((string) ($target['fields']['post_content'] ?? ''), $current);
		if ($target === null) {
			if ($current !== null) {
				PatternObject::removePost($id);
			}
		} else {
			PatternObject::writeState($id, $target, $current, self::TYPES);
		}
		self::flush();
		return $id;
	}

	public static function flush(): void
	{
		if (function_exists('wp_clean_theme_json_cache')) {
			wp_clean_theme_json_cache();
		}
		if (class_exists('WP_Theme_JSON_Resolver') && method_exists('WP_Theme_JSON_Resolver', 'clean_cached_data')) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		if (self::canonicalJson((string) $current['fields']['post_content']) !== self::canonicalJson((string) ($intent['content'] ?? ''))) {
			return false;
		}
		if ($before === null) {
			return ($current['fields']['post_type'] ?? '') === 'wp_global_styles';
		}
		return PatternObject::sameOutside($before, $current, ['post_content', 'post_modified', 'post_modified_gmt'], ['_wp_old_slug', '_wp_old_date'], []);
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		return [
			'destructive' => in_array($op, ['undo', 'redo'], true) && $after === null,
			'public' => true,
			'sensitive' => false,
			'external_effects' => ['The whole site changes for visitors; page caches and CDNs are not purged.'],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		return user_can($userId, 'edit_theme_options');
	}

	/**
	 * Custom CSS is edited only in the WordPress Site Editor: a write whose content would change any `css` value of the
	 * current styles (add, edit or remove) is refused before anything is written.
	 *
	 * @param array<string, mixed>|null $current
	 */
	private static function refuseCssChange(string $content, ?array $current): void
	{
		$wanted = json_decode($content, true);
		if (self::cssOf(is_array($wanted) ? $wanted : []) !== self::cssOf(self::decode($current))) {
			throw new MutationFailure('css_not_supported', esc_html__('Custom CSS cannot be changed through Llamly. Use the WordPress Site Editor.', 'llamly'), 409);
		}
	}

	/**
	 * Every custom CSS value under styles, keyed by its dot path. theme.json accepts `css` in the root, blocks,
	 * elements, variations and breakpoints, so the walk is recursive over any `css` key.
	 *
	 * @return array<string, mixed>
	 */
	public static function cssOf(?array $data): array
	{
		$css = [];
		if (is_array($data['styles'] ?? null)) {
			self::collectCss($data['styles'], 'styles', $css);
		}
		ksort($css, SORT_STRING);
		return $css;
	}

	/** @param array<string, mixed> $css */
	private static function collectCss(array $node, string $path, array &$css): void
	{
		foreach ($node as $key => $value) {
			if ((string) $key === 'css') {
				$css[$path . '.css'] = $value;
			} elseif (is_array($value)) {
				self::collectCss($value, $path . '.' . $key, $css);
			}
		}
	}

	/** The styles tree without any `css` key (checked apart from the theme.json sanitizer). */
	public static function withoutCss(array $node): array
	{
		foreach ($node as $key => $value) {
			if ((string) $key === 'css') {
				unset($node[$key]);
			} elseif (is_array($value)) {
				$node[$key] = self::withoutCss($value);
			}
		}
		return $node;
	}

	public function label(?array $state): string
	{
		return __('Global styles', 'llamly');
	}

	public function tables(): array
	{
		return PatternObject::postTables();
	}

	public function findMarked(string $operationId): array
	{
		return PatternObject::markedPosts($operationId, self::TYPES);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		delete_post_meta($id, PostObject::MARKER, $operationId);
	}

	public function flushCaches(int $id): void
	{
		PatternObject::flushPostCaches($id);
		self::flush();
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$result = ['post_id' => $id, 'created' => $before === null && $after !== null];
		$warnings = array_values((array) ($intent['warnings'] ?? []));
		if ($warnings) {
			$result['warnings'] = $warnings;
		}
		return $result;
	}
}
