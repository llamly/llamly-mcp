<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

/**
 * Post state (S_POST), read straight from the database.
 *
 * State = wp_posts columns (minus comment_count, to_ping, pinged) + post meta in scope (public keys and
 * ContentGuard::STATE_PRIVATE_META) + term IDs per taxonomy + sticky flag. `comments` (id => approval status) is the
 * retain scope of a trash: it is restored but not hashed, so moderating a comment does not make a post stale.
 */
final class PostAdapter
{
	public const COLUMNS = [
		'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status',
		'comment_status', 'ping_status', 'post_password', 'post_name', 'post_modified', 'post_modified_gmt',
		'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
	];
	public const INT_FIELDS = ['post_author', 'post_parent', 'menu_order'];

	/** Derived-meta ceilings per operation (keys, and bytes of the new values). */
	public const DERIVED_META_MAX_KEYS = 20;
	public const DERIVED_META_MAX_BYTES = 65536;
	/** Meta that never counts as derived, whatever a hook does: attachment file records. */
	public const DERIVED_META_DENY_KEYS = ['_wp_attached_file', '_wp_attachment_metadata'];
	/** Llamly markers, edit tokens and module meta never count as derived (compared case-insensitively). */
	public const DERIVED_META_DENY_PREFIXES = ['_llamly_', 'llamly_'];

	/**
	 * @param list<string>|null $types Post types this capture accepts (default: ContentGuard::allowedPostTypes()).
	 * @return array{post_id:int,fields:array<string,mixed>,meta:array<string,list<string>>,terms:array<string,list<int>>,sticky:bool,comments:list<array{id:int,approved:string}>}|null
	 */
	public static function capture(int $postId, bool $forUpdate = false, ?array $types = null): ?array
	{
		global $wpdb;
		if ($postId < 1) {
			return null;
		}
		// The column list is self::COLUMNS written out.
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare("SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $postId), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare("SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $postId), ARRAY_A);
		if (!is_array($row) || $wpdb->last_error !== '') {
			return null;
		}
		$type = (string) $row['post_type'];
		if (!in_array($type, $types ?? ContentGuard::allowedPostTypes(), true)) {
			return null;
		}
		$metaRows = RawRows::captureMeta($wpdb->postmeta, 'post_id', $postId, null, ContentGuard::metaExcludeRegex(), $forUpdate);
		if ($metaRows === null) {
			return null;
		}
		$meta = [];
		foreach ($metaRows as $item) {
			$meta[$item['key']][] = $item['value'];
		}
		ksort($meta, SORT_STRING);

		$ttIds = $forUpdate
			? $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC FOR UPDATE", $postId))
			: $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC", $postId));
		if (!is_array($ttIds) || $wpdb->last_error !== '') {
			return null;
		}
		$terms = self::termsOf(array_map('intval', $ttIds));
		if ($terms === null) {
			return null;
		}
		$comments = $wpdb->get_results($wpdb->prepare("SELECT comment_ID, comment_approved FROM {$wpdb->comments} WHERE comment_post_ID = %d ORDER BY comment_ID ASC", $postId), ARRAY_A);
		if (!is_array($comments) || $wpdb->last_error !== '') {
			return null;
		}

		return [
			'post_id' => (int) $row['ID'],
			'fields' => self::canonicalFields($row),
			'meta' => $meta,
			'terms' => $terms,
			'sticky' => $type === 'post' && in_array((int) $row['ID'], self::stickyIds(), true),
			'comments' => array_map(static fn(array $c): array => ['id' => (int) $c['comment_ID'], 'approved' => (string) $c['comment_approved']], $comments),
		];
	}

	public static function fingerprint(int $postId, bool $forUpdate = false): ?string
	{
		$capture = self::capture($postId, $forUpdate);
		return $capture === null ? null : self::fingerprintOf($capture);
	}

	/** Columns a save rewrites even when nothing was edited: the post hash leaves them out. */
	public const VOLATILE_COLUMNS = ['post_modified', 'post_modified_gmt'];
	/** post_date_gmt of a draft whose date floats (never set): WordPress moves its post_date to the time of every save. */
	public const FLOATING_DATE_GMT = '0000-00-00 00:00:00';

	/**
	 * Hash of what an edit can change: every column, meta in scope, terms and the sticky flag, but not the modified dates,
	 * nor the post_date of a draft whose date floats (post_date_gmt still zero), which WordPress sets to "now" on every save.
	 * Opening a post in the editor and saving it without changes (which only moves those dates and adds a revision row)
	 * keeps the hash, so it does not block the undo of an earlier change; any real edit (title, content, status, meta,
	 * terms, a date set by hand, ...) still changes it. Comments are the retain scope only.
	 *
	 * @param array<string, mixed> $capture
	 */
	public static function fingerprintOf(array $capture): ?string
	{
		$fields = is_array($capture['fields'] ?? null) ? $capture['fields'] : [];
		foreach (self::VOLATILE_COLUMNS as $column) {
			unset($fields[$column]);
		}
		if (array_key_exists('post_date_gmt', $fields) && (string) $fields['post_date_gmt'] === self::FLOATING_DATE_GMT) {
			unset($fields['post_date']);
		}
		return State::hash('p2', [
			'post_id' => (int) ($capture['post_id'] ?? 0),
			'fields' => $fields,
			'meta' => $capture['meta'] ?? [],
			'terms' => $capture['terms'] ?? [],
			'sticky' => (bool) ($capture['sticky'] ?? false),
		]);
	}

	/**
	 * The post hash up to Llamly 1.0, which also covered post_modified. Only used to recognize hashes the journal stored
	 * before the update (see LegacyFingerprint).
	 *
	 * @param array<string, mixed> $capture
	 */
	public static function legacyFingerprintOf(array $capture): ?string
	{
		return State::hash('p2', [
			'post_id' => (int) ($capture['post_id'] ?? 0),
			'fields' => $capture['fields'] ?? [],
			'meta' => $capture['meta'] ?? [],
			'terms' => $capture['terms'] ?? [],
			'sticky' => (bool) ($capture['sticky'] ?? false),
		]);
	}

	/** @return list<int> Sticky post IDs from the raw option row (no option cache). */
	public static function stickyIds(): array
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sticky_posts'));
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		$value = is_serialized($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
		return is_array($value) ? array_values(array_unique(array_map('intval', $value))) : [];
	}

	/**
	 * @param list<int> $ttIds
	 * @return array<string, list<int>>|null taxonomy => sorted term IDs
	 */
	public static function termsOf(array $ttIds): ?array
	{
		global $wpdb;
		if (!$ttIds) {
			return [];
		}
		$ttIds = array_map('intval', $ttIds);
		$rows = $wpdb->get_results($wpdb->prepare("SELECT taxonomy, term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN (" . implode(',', array_fill(0, count($ttIds), '%d')) . ')', $ttIds), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return null;
		}
		$terms = [];
		foreach ($rows as $row) {
			$terms[(string) $row['taxonomy']][] = (int) $row['term_id'];
		}
		foreach ($terms as &$ids) {
			sort($ids);
		}
		unset($ids);
		ksort($terms, SORT_STRING);
		return $terms;
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function canonicalFields(array $row): array
	{
		$fields = [];
		foreach (self::COLUMNS as $key) {
			$value = $row[$key] ?? '';
			$fields[$key] = in_array($key, self::INT_FIELDS, true) ? (int) $value : (string) $value;
		}
		return $fields;
	}

	/**
	 * Whether a meta key may be reported as derived by a hook. Keys Llamly maps from an intent field (ContentGuard::STATE_PRIVATE_META,
	 * such as _wp_page_template and _thumbnail_id), Llamly's own markers and tokens, and attachment file records never are.
	 */
	public static function isDerivableMetaKey(string $key): bool
	{
		if ($key === '' || in_array($key, ContentGuard::STATE_PRIVATE_META, true) || in_array($key, self::DERIVED_META_DENY_KEYS, true)) {
			return false;
		}
		$lower = strtolower($key);
		foreach (self::DERIVED_META_DENY_PREFIXES as $prefix) {
			if (str_starts_with($lower, $prefix)) {
				return false;
			}
		}
		return ContentGuard::metaInScope($key);
	}

	/**
	 * Compares two post states outside a scope and classifies meta differences as derived (written by a hook during the effect).
	 *
	 * $scope removes what the caller verifies by other means: fields (columns to ignore or already checked), meta (keys already
	 * checked, or derived keys of an earlier operation), terms (taxonomies already checked) and sticky. Everything else must be
	 * equal, except public meta that passes isDerivableMetaKey() within the ceilings.
	 *
	 * @param array<string, mixed> $before State the observed one is measured against (the pre-effect state, or an undo/redo target).
	 * @param array<string, mixed> $observed
	 * @param array{fields?:list<string>,meta?:list<string>,terms?:list<string>,sticky?:bool,strict_meta?:list<string>} $scope `strict_meta` keys must match exactly (never derived)
	 * @return array{ok:bool,derived:list<string>,reason:?string}
	 */
	public static function compareWithDerived(array $before, array $observed, array $scope = []): array
	{
		$fail = static fn(string $reason): array => ['ok' => false, 'derived' => [], 'reason' => $reason];
		$skipMeta = array_flip(array_map('strval', $scope['meta'] ?? []));
		$strictMeta = array_flip(array_map('strval', $scope['strict_meta'] ?? []));
		$expected = $before;
		foreach ([&$expected, &$observed] as &$state) {
			foreach ($scope['fields'] ?? [] as $key) {
				unset($state['fields'][$key]);
			}
			foreach ($scope['terms'] ?? [] as $taxonomy) {
				unset($state['terms'][$taxonomy]);
			}
			if (!empty($scope['sticky'])) {
				unset($state['sticky']);
			}
		}
		unset($state);
		if (($expected['fields'] ?? []) !== ($observed['fields'] ?? [])) {
			return $fail('fields');
		}
		if (($expected['terms'] ?? []) !== ($observed['terms'] ?? []) || (bool) ($expected['sticky'] ?? false) !== (bool) ($observed['sticky'] ?? false)) {
			return $fail('terms');
		}
		$derived = [];
		$bytes = 0;
		$keys = array_unique(array_merge(array_keys((array) ($expected['meta'] ?? [])), array_keys((array) ($observed['meta'] ?? []))));
		foreach ($keys as $key) {
			$key = (string) $key;
			if (isset($skipMeta[$key])) {
				continue;
			}
			$was = $expected['meta'][$key] ?? null;
			$now = $observed['meta'][$key] ?? null;
			if ($was === $now) {
				continue;
			}
			if (isset($strictMeta[$key]) || !self::isDerivableMetaKey($key)) {
				return $fail('meta_outside_intent');
			}
			$derived[] = $key;
			foreach ((array) $now as $value) {
				$bytes += strlen((string) $value);
			}
		}
		if (count($derived) > self::DERIVED_META_MAX_KEYS || $bytes > self::DERIVED_META_MAX_BYTES) {
			return $fail('derived_meta_limit');
		}
		sort($derived, SORT_STRING);
		return ['ok' => true, 'derived' => $derived, 'reason' => null];
	}

	/**
	 * An undo/redo target that keeps the current value of the given (derived) meta keys, so restoring it never touches them.
	 *
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $current
	 * @param list<string> $keys
	 * @return array<string, mixed>|null
	 */
	public static function targetKeepingMeta(?array $target, ?array $current, array $keys): ?array
	{
		if ($target === null || $current === null || !$keys) {
			return $target;
		}
		foreach ($keys as $key) {
			if (isset($current['meta'][$key])) {
				$target['meta'][$key] = $current['meta'][$key];
			} else {
				unset($target['meta'][$key]);
			}
		}
		ksort($target['meta'], SORT_STRING);
		return $target;
	}
}
