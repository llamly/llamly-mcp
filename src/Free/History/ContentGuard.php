<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Core\Redactor;

/**
 * Write policy shared by every path that changes post content (MCP tools, batch, undo/redo, other adapters).
 *
 * - Which post types the generic post tools may touch (templates, global styles, patterns, menu items, Elementor
 *   library and other internal types have their own adapters or stay read-only).
 * - Elementor documents are read-only in Llamly Free: content, excerpt, meta and revision restores are refused, and
 *   Elementor library items/kits are never trashed. Title, slug, status, terms and featured image stay editable.
 * - Which post meta keys are part of the reversible state and which ones an agent may write.
 */
final class ContentGuard
{
	/** Post types that are never handled by the generic post tools. */
	public const DENIED_TYPES = [
		'attachment', 'nav_menu_item', 'revision', 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_block',
		'elementor_library', 'customize_changeset', 'user_request', 'oembed_cache', 'custom_css', 'wp_font_family',
		'wp_font_face', 'wp_changeset', 'elementor_snippet', 'elementor_font', 'elementor_icons',
	];

	/** Protected meta that belongs to the post state (restored exactly, never written directly by an agent). */
	public const STATE_PRIVATE_META = [
		'_thumbnail_id', '_wp_page_template', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status',
		'_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug',
	];

	/** @return list<string> */
	public static function allowedPostTypes(): array
	{
		$types = [];
		foreach (get_post_types(['show_in_rest' => true], 'names') as $type) {
			$type = (string) $type;
			if (self::isAllowedType($type)) {
				$types[] = $type;
			}
		}
		sort($types);
		return $types;
	}

	public static function isAllowedType(string $type): bool
	{
		if ($type === '' || in_array($type, self::DENIED_TYPES, true) || strpos($type, 'elementor') === 0 || strpos($type, 'e-') === 0) {
			return false;
		}
		$object = get_post_type_object($type);
		return $object instanceof \WP_Post_Type && !empty($object->show_in_rest);
	}

	/**
	 * Elementor document = library item, active kit, builder edit mode or any stored Elementor data. Read from the
	 * database (inside the engine transaction the row is already locked by the capture).
	 */
	public static function isElementorDocument(int $postId): bool
	{
		global $wpdb;
		if ($postId < 1) {
			return false;
		}
		$type = (string) $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $postId));
		if ($type === 'elementor_library' || self::isElementorKit($postId)) {
			return true;
		}
		$found = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND ((meta_key = '_elementor_edit_mode' AND meta_value = 'builder') OR (meta_key = '_elementor_data' AND meta_value <> ''))",
			$postId
		));
		return $found > 0;
	}

	public static function isElementorKit(int $postId): bool
	{
		global $wpdb;
		$kit = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'elementor_active_kit'));
		return $kit > 0 && $kit === $postId;
	}

	public static function revisionHasElementorData(int $revisionId): bool
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_elementor_data', '_elementor_edit_mode') AND meta_value <> ''",
			$revisionId
		)) > 0;
	}

	/**
	 * Guard for a forward post intent (update, trash, restore_revision). Returns null when allowed.
	 *
	 * @param array<string, mixed> $intent
	 */
	public static function checkIntent(int $postId, array $intent): ?MutationFailure
	{
		$op = (string) ($intent['op'] ?? '');
		if ($postId < 1 || $op === 'create') {
			return null;
		}
		if ($op === 'restore_revision' && self::revisionHasElementorData((int) ($intent['revision_id'] ?? 0))) {
			return self::readOnly();
		}
		if (!self::isElementorDocument($postId)) {
			return null;
		}
		if ($op === 'restore_revision') {
			return self::readOnly();
		}
		if ($op === 'trash') {
			global $wpdb;
			$type = (string) $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $postId));
			return $type === 'elementor_library' || self::isElementorKit($postId) ? self::readOnly() : null;
		}
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if (array_key_exists('post_content', $fields) || array_key_exists('post_excerpt', $fields) || !empty($intent['meta'])) {
			return self::readOnly();
		}
		return null;
	}

	/**
	 * Guard for undo/redo: an object that is now an Elementor document keeps its content, excerpt and meta.
	 *
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $current
	 */
	public static function checkRestore(int $postId, ?array $target, ?array $current): ?MutationFailure
	{
		if ($current === null || !self::isElementorDocument($postId)) {
			return null;
		}
		if ($target === null) {
			return self::readOnly();
		}
		foreach (['post_content', 'post_excerpt'] as $field) {
			if ((string) ($target['fields'][$field] ?? '') !== (string) ($current['fields'][$field] ?? '')) {
				return self::readOnly();
			}
		}
		// Only content meta counts. Protected keys in the state are WordPress bookkeeping of allowed changes (trash,
		// slug/date history, featured image, template), so undoing a trash or slug change of a builder page still works.
		return self::contentMeta((array) ($target['meta'] ?? [])) === self::contentMeta((array) ($current['meta'] ?? [])) ? null : self::readOnly();
	}

	/** @param array<string, list<string>> $meta @return array<string, list<string>> */
	private static function contentMeta(array $meta): array
	{
		$out = [];
		foreach ($meta as $key => $values) {
			if ((string) $key !== '' && ((string) $key)[0] !== '_') {
				$out[(string) $key] = $values;
			}
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * Meta keys excluded from the reversible post state: protected keys other than STATE_PRIVATE_META, and anything
	 * that looks like a secret. One regex, so capture and restore always use the same scope.
	 */
	public static function metaExcludeRegex(): string
	{
		$private = implode('|', array_map(static fn(string $key): string => preg_quote(substr($key, 1), '/'), self::STATE_PRIVATE_META));
		$sensitive = substr(Redactor::SENSITIVE_KEY, 1, strrpos(Redactor::SENSITIVE_KEY, '/') - 1);
		return '/(?:^_(?!(?:' . $private . ')$))|' . $sensitive . '/i';
	}

	public static function metaInScope(string $key): bool
	{
		return !preg_match(self::metaExcludeRegex(), $key);
	}

	/** Whether an agent may write this meta key on a post of $postType (object capability checked separately). */
	public static function metaWritable(string $key, string $postType): bool
	{
		if ($key === '' || strlen($key) > 255 || $key[0] === '_' || !self::metaInScope($key) || Redactor::isSensitiveKey($key)) {
			return false;
		}
		return !is_protected_meta($key, 'post');
	}

	private static function readOnly(): MutationFailure
	{
		return new MutationFailure('elementor_read_only', __('This is an Elementor document. Its content, excerpt, metadata and revisions are read-only in Llamly.', 'llamly'), 403);
	}
}
