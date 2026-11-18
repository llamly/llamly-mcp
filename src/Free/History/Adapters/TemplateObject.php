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
 * Block template and template part overrides (wp_template / wp_template_part posts, S_TEMPLATE).
 *
 * A theme file template has no row: its first edit is a create of the override (materialize) and undoing it deletes
 * the override, so WordPress serves the theme file again. A reset deletes the override (op=delete); its undo
 * re-inserts the same ID with meta, terms and up to 50 retained revisions. Theme files are never written.
 *
 * Intents:
 *   create: {op, theme, slug, template_type, area?, origin?, fields{post_title,post_name,post_excerpt,post_content}}
 *   update: {op, fields{post_title?,post_excerpt?,post_content?}, area?}
 *   delete: {op}
 */
final class TemplateObject implements Adapter
{
	public const TYPES = ['wp_template', 'wp_template_part'];
	public const PREFIX = 'tp1';
	public const MAX_REVISIONS = 50;

	public function type(): string
	{
		return 'template';
	}

	public function prefix(): string
	{
		return self::PREFIX;
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		$state = PostAdapter::capture($id, $forUpdate, self::TYPES);
		if ($state === null) {
			return null;
		}
		unset($state['comments'], $state['sticky']);
		// Retain scope of a reset: revisions are history, not hashed.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID DESC LIMIT %d",
			$id,
			self::MAX_REVISIONS
		), ARRAY_A);
		$state['revisions'] = is_array($rows) ? array_reverse($rows) : [];
		return $state;
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return self::PREFIX . '_' . Engine::ABSENT;
		}
		return State::hash(self::PREFIX, [
			'post_id' => (int) ($state['post_id'] ?? 0),
			'fields' => $state['fields'] ?? [],
			'meta' => $state['meta'] ?? [],
			'terms' => $state['terms'] ?? [],
		]);
	}

	/** Hash of a template served from the theme file (no override): changes when the theme updates the file. */
	public static function virtualHash(string $content): string
	{
		return self::PREFIX . '_' . hash('sha256', 'virtual:' . md5($content));
	}

	/** Override post of an active-theme template, read from the database (null when the theme file is served). */
	public static function overrideId(string $theme, string $slug, string $type, bool $forUpdate = false): ?int
	{
		global $wpdb;
		$id = $forUpdate
			? $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				 WHERE p.post_type = %s AND p.post_name = %s AND p.post_status IN ('publish','draft','auto-draft')
				 ORDER BY p.ID ASC LIMIT 1 FOR UPDATE",
				$theme,
				$type,
				$slug
			))
			: $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s
				 WHERE p.post_type = %s AND p.post_name = %s AND p.post_status IN ('publish','draft','auto-draft')
				 ORDER BY p.ID ASC LIMIT 1",
				$theme,
				$type,
				$slug
			));
		return $id === null ? null : (int) $id;
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		if (!user_can((int) ($auth['user_id'] ?? 0), 'edit_theme_options')) {
			throw new MutationFailure('forbidden', esc_html__('The connected user cannot edit templates.', 'llamly'), 403);
		}
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'create') {
			$theme = (string) ($intent['theme'] ?? '');
			$type = (string) ($intent['template_type'] ?? '');
			$slug = (string) ($intent['slug'] ?? '');
			if ($theme !== get_stylesheet() || !in_array($type, self::TYPES, true) || $slug === '') {
				throw new MutationFailure('not_active_theme', esc_html__('Only templates of the active theme can be saved.', 'llamly'), 409);
			}
			// Another override may have been saved since the caller read the virtual hash.
			if (self::overrideId($theme, $slug, $type, true) !== null) {
				throw new MutationFailure('stale_revision', esc_html__('The template was customized after the caller read it. Read it again before saving.', 'llamly'), 409);
			}
			$data = ['post_type' => $type, 'post_status' => 'publish', 'post_name' => $slug] + $fields;
			$meta = [PostObject::MARKER => $operationId];
			if (($intent['origin'] ?? '') !== '') {
				$meta['origin'] = (string) $intent['origin'];
			}
			$created = wp_insert_post(wp_slash($data + ['meta_input' => $meta]), true);
			if (is_wp_error($created)) {
				return $created;
			}
			$id = (int) $created;
			if ($id < 1) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not create the template override.', 'llamly'), 500);
			}
			$set = wp_set_object_terms($id, $theme, 'wp_theme', false);
			if (is_wp_error($set)) {
				throw new MutationFailure('mutation_failed', esc_html($set->get_error_message()), 500);
			}
			if ($type === 'wp_template_part') {
				$this->setArea($id, (string) ($intent['area'] ?? 'uncategorized'));
			}
			clean_post_cache($id);
			return $id;
		}
		clean_post_cache($id);
		if ($op === 'delete') {
			PatternObject::removePost($id);
			return $id;
		}
		if ($op !== 'update') {
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown template operation.', 'llamly'), 400);
		}
		if ($fields) {
			$updated = wp_update_post(wp_slash(['ID' => $id] + $fields), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			if ((int) $updated !== $id) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not update the template.', 'llamly'), 500);
			}
		}
		if (isset($intent['area']) && get_post_type($id) === 'wp_template_part') {
			$this->setArea($id, (string) $intent['area']);
		}
		clean_post_cache($id);
		return $id;
	}

	private function setArea(int $id, string $area): void
	{
		$area = function_exists('_filter_block_template_part_area') ? _filter_block_template_part_area($area) : $area;
		$set = wp_set_object_terms($id, $area, 'wp_template_part_area', false);
		if (is_wp_error($set)) {
			throw new MutationFailure('mutation_failed', esc_html($set->get_error_message()), 500);
		}
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		if ($target === null) {
			if ($current !== null) {
				PatternObject::removePost($id);
			}
			return $id;
		}
		PatternObject::writeState($id, $target, $current, self::TYPES);
		return $id;
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if (!PatternObject::fieldsMatch($current, $fields)) {
			return false;
		}
		$op = (string) ($intent['op'] ?? '');
		if ($op === 'create') {
			$themeTerm = get_term_by('slug', (string) ($intent['theme'] ?? ''), 'wp_theme');
			if (!$themeTerm instanceof \WP_Term || ($current['terms']['wp_theme'] ?? []) !== [(int) $themeTerm->term_id]) {
				return false;
			}
			return ($current['fields']['post_type'] ?? '') === ($intent['template_type'] ?? '');
		}
		if ($before === null) {
			return true;
		}
		$taxonomies = isset($intent['area']) ? ['wp_template_part_area'] : [];
		return PatternObject::sameOutside($before, $current, array_merge(array_keys($fields), ['post_modified', 'post_modified_gmt']), ['_wp_old_slug', '_wp_old_date'], $taxonomies);
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$removes = $op === 'delete' || (in_array($op, ['undo', 'redo'], true) && $after === null);
		return [
			'destructive' => $removes,
			'public' => true,
			'sensitive' => false,
			'external_effects' => ['Every page rendered with this template changes for visitors; page caches and CDNs are not purged.'],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		return user_can($userId, 'edit_theme_options');
	}

	public function label(?array $state): string
	{
		$title = trim((string) ($state['fields']['post_title'] ?? ''));
		return $title !== '' ? $title : (string) ($state['fields']['post_name'] ?? __('(template)', 'llamly'));
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
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$state = $after ?? $before;
		$result = ['post_id' => $id];
		if ($state !== null) {
			$themeId = (int) ($state['terms']['wp_theme'][0] ?? 0);
			$theme = $themeId > 0 ? get_term($themeId, 'wp_theme') : null;
			$result['template_id'] = ($theme instanceof \WP_Term ? $theme->slug : (string) ($intent['theme'] ?? '')) . '//' . (string) $state['fields']['post_name'];
			$result['template_type'] = (string) $state['fields']['post_type'];
		}
		$result['has_override'] = $after !== null;
		if (($intent['op'] ?? '') === 'create' && !empty($intent['materialized'])) {
			$result['materialized_override'] = true;
		}
		$warnings = array_values((array) ($intent['warnings'] ?? []));
		if ($warnings) {
			$result['warnings'] = $warnings;
		}
		return $result;
	}
}
