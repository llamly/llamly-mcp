<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\History\Adapter;
use Llamly\Free\History\ContentGuard;
use Llamly\Free\History\Engine;
use Llamly\Free\History\LegacyFingerprint;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\RawRows;

/**
 * User patterns (wp_block posts, S_PATTERN = S_POST + wp_pattern_sync_status + wp_pattern_category).
 *
 * Intents: create, update, trash. The state reuses PostAdapter::capture (restricted to wp_block) and its p2 hash.
 * restore() writes the journaled row straight back (raw pin, raw meta, term relationships) and recreates a removed
 * pattern with its original ID. The static helpers below are shared with the template and global styles adapters.
 */
final class PatternObject implements Adapter, LegacyFingerprint
{
	public const TYPES = ['wp_block'];
	public const SYNC_META = 'wp_pattern_sync_status';
	public const CATEGORY = 'wp_pattern_category';
	private const TRASH_META = ['_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug'];

	public function type(): string
	{
		return 'pattern';
	}

	public function prefix(): string
	{
		return 'p2';
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		return PostAdapter::capture($id, $forUpdate, self::TYPES);
	}

	public function fingerprint(?array $state): ?string
	{
		return $state === null ? 'p2_' . Engine::ABSENT : PostAdapter::fingerprintOf($state);
	}

	public function legacyFingerprint(?array $state): ?string
	{
		return $state === null ? 'p2_' . Engine::ABSENT : PostAdapter::legacyFingerprintOf($state);
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$userId = (int) ($auth['user_id'] ?? 0);
		if ($op === 'create') {
			if (!user_can($userId, 'publish_posts') || !user_can($userId, 'edit_posts')) {
				throw new MutationFailure('forbidden', esc_html__('The connected user cannot create patterns.', 'llamly'), 403);
			}
			$data = ['post_type' => 'wp_block', 'post_status' => 'publish'] + $fields + ['meta_input' => [PostObject::MARKER => $operationId]];
			$created = wp_insert_post(wp_slash($data), true);
			if (is_wp_error($created)) {
				return $created;
			}
			$id = (int) $created;
			if ($id < 1) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not create the pattern.', 'llamly'), 500);
			}
			$this->applyRelations($id, $intent);
			clean_post_cache($id);
			return $id;
		}
		if (!user_can($userId, $op === 'trash' ? 'delete_post' : 'edit_post', $id)) {
			throw new MutationFailure('forbidden', esc_html__('The connected user cannot change this pattern.', 'llamly'), 403);
		}
		clean_post_cache($id);
		if ($op === 'trash') {
			if (!EMPTY_TRASH_DAYS) {
				throw new MutationFailure('trash_disabled', esc_html__('The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so deleting would be permanent.', 'llamly'), 409);
			}
			if (get_post_status($id) === 'trash') {
				throw new MutationFailure('already_trashed', esc_html__('The pattern is already in the trash.', 'llamly'), 409);
			}
			if (!wp_trash_post($id) instanceof \WP_Post) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not move the pattern to the trash.', 'llamly'), 409);
			}
			clean_post_cache($id);
			return $id;
		}
		if ($op !== 'update') {
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown pattern operation.', 'llamly'), 400);
		}
		if ($fields) {
			$updated = wp_update_post(wp_slash(['ID' => $id] + $fields), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			if ((int) $updated !== $id) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not update the pattern.', 'llamly'), 500);
			}
		}
		$this->applyRelations($id, $intent);
		clean_post_cache($id);
		return $id;
	}

	/** @param array<string, mixed> $intent */
	private function applyRelations(int $id, array $intent): void
	{
		if (array_key_exists('synced', $intent)) {
			delete_post_meta($id, self::SYNC_META);
			if (!$intent['synced'] && !add_post_meta($id, self::SYNC_META, 'unsynced')) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not store the pattern sync status.', 'llamly'), 500);
			}
		}
		if (array_key_exists('categories', $intent)) {
			$set = wp_set_object_terms($id, array_map('intval', (array) $intent['categories']), self::CATEGORY, false);
			if (is_wp_error($set)) {
				throw new MutationFailure('invalid_terms', esc_html($set->get_error_message()), 400);
			}
		}
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		if ($target === null) {
			if ($current !== null) {
				self::removePost($id);
			}
			return $id;
		}
		self::writeState($id, $target, $current, self::TYPES);
		return $id;
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = $op === 'trash' ? ['post_status' => 'trash'] : (is_array($intent['fields'] ?? null) ? $intent['fields'] : []);
		if (!self::fieldsMatch($current, $fields)) {
			return false;
		}
		if (array_key_exists('synced', $intent) && self::isSynced($current) !== (bool) $intent['synced']) {
			return false;
		}
		if (array_key_exists('categories', $intent)) {
			$want = array_values(array_unique(array_map('intval', (array) $intent['categories'])));
			sort($want);
			if (($current['terms'][self::CATEGORY] ?? []) !== $want) {
				return false;
			}
		}
		if ($before === null) {
			return true;
		}
		$ignoreFields = array_merge(array_keys($fields), ['post_modified', 'post_modified_gmt']);
		$ignoreMeta = ['_wp_old_slug', '_wp_old_date'];
		if ($op === 'trash') {
			$ignoreFields[] = 'post_name';
			$ignoreMeta = array_merge($ignoreMeta, self::TRASH_META);
		}
		if (array_key_exists('synced', $intent)) {
			$ignoreMeta[] = self::SYNC_META;
		}
		return self::sameOutside($before, $current, $ignoreFields, $ignoreMeta, array_key_exists('categories', $intent) ? [self::CATEGORY] : []);
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$id = (int) ($before['post_id'] ?? $after['post_id'] ?? 0);
		$usage = $id > 0 ? self::usageCount($id) : 0;
		$inUse = $usage > 0;
		if (in_array($op, ['undo', 'redo'], true)) {
			$public = $inUse && (($before !== null && self::isSynced($before)) || ($after !== null && self::isSynced($after)) || $after === null || $before === null);
			$beforeStatus = (string) ($before['fields']['post_status'] ?? '');
			$afterStatus = (string) ($after['fields']['post_status'] ?? '');
			return [
				'destructive' => $after === null || ($afterStatus === 'trash' && $beforeStatus !== 'trash'),
				'public' => $public,
				'sensitive' => false,
				'external_effects' => $public ? ['Pages that embed this synced pattern change for visitors.'] : [],
			];
		}
		if ($op === 'create') {
			return ['destructive' => false, 'public' => false, 'sensitive' => false, 'external_effects' => []];
		}
		$public = $inUse && ($op === 'trash' || ($before !== null && self::isSynced($before)) || (array_key_exists('synced', $intent) && $intent['synced']));
		return [
			'destructive' => $op === 'trash' && ($before['fields']['post_status'] ?? '') !== 'trash',
			'public' => $public,
			'sensitive' => false,
			'external_effects' => $public ? ['Pages that embed this synced pattern change for visitors.'] : [],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		if ($id > 0 && get_post($id)) {
			$removes = $after === null || $before === null || ($after['fields']['post_status'] ?? '') === 'trash' || ($before['fields']['post_status'] ?? '') === 'trash';
			return user_can($userId, 'edit_post', $id) && (!$removes || user_can($userId, 'delete_post', $id));
		}
		return user_can($userId, 'publish_posts') && user_can($userId, 'edit_posts');
	}

	public function label(?array $state): string
	{
		$title = trim((string) ($state['fields']['post_title'] ?? ''));
		return $title !== '' ? $title : __('(untitled pattern)', 'llamly');
	}

	public function tables(): array
	{
		return self::postTables();
	}

	public function findMarked(string $operationId): array
	{
		return self::markedPosts($operationId, self::TYPES);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		delete_post_meta($id, PostObject::MARKER, $operationId);
	}

	public function flushCaches(int $id): void
	{
		self::flushPostCaches($id);
		// A sticky pattern removed by this write: the sticky list lives in the options cache.
		if (PostObject::optionsDirty($id)) {
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('sticky_posts', 'options');
		}
	}

	/** Called by the engine after the final cache pass for this object. */
	public function forgetRequestState(int $id): void
	{
		(new PostObject())->forgetRequestState($id);
	}

	/** Post, post meta, term relationships and the `last_changed` markers of one post-backed object, read from the database. */
	public static function flushPostCaches(int $id): void
	{
		global $wpdb;
		$type = (string) $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		clean_post_cache($id);
		wp_cache_delete($id, 'posts');
		wp_cache_delete($id, 'post_meta');
		if ($type !== '') {
			clean_object_term_cache($id, $type);
			return;
		}
		// The post no longer exists: drop the lists that could still name it.
		if (function_exists('wp_cache_set_posts_last_changed')) {
			wp_cache_set_posts_last_changed();
		}
		wp_cache_delete("post_parent:{$id}", 'posts');
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$result = ['pattern_id' => $id];
		if ($after !== null) {
			$result['synced'] = self::isSynced($after);
			$result['status'] = (string) $after['fields']['post_status'];
		}
		$warnings = array_values((array) ($intent['warnings'] ?? []));
		if ($warnings) {
			$result['warnings'] = $warnings;
		}
		if (!empty($intent['referenced_by'])) {
			$result['referenced_by'] = array_values((array) $intent['referenced_by']);
		}
		if (!empty($intent['blocks'])) {
			$result['blocks'] = $intent['blocks'];
		}
		return $result;
	}

	// ------------------------------------------------------------------ helpers shared by the Gutenberg adapters

	/** @param array<string, mixed> $state */
	public static function isSynced(array $state): bool
	{
		return ($state['meta'][self::SYNC_META][0] ?? '') !== 'unsynced';
	}

	/** Posts that embed the pattern through <!-- wp:block {"ref":ID} -->, excluding revisions and trash. */
	public static function usageCount(int $id): int
	{
		return count(self::referencedBy($id, 1000));
	}

	/** @return list<int> */
	public static function referencedBy(int $id, int $limit = 100): array
	{
		global $wpdb;
		$rows = $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision') AND post_status NOT IN ('trash','auto-draft','inherit') AND ID <> %d AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
			$id,
			'%' . $wpdb->esc_like('"ref":' . $id) . '%',
			max(1, $limit) * 2
		));
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $postId) {
			$content = (string) $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", (int) $postId));
			if (preg_match('/<!--\s+wp:block\s+\{[^}]*"ref":' . $id . '[,}]/', $content)) {
				$out[] = (int) $postId;
			}
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/** @return list<string> */
	public static function postTables(): array
	{
		global $wpdb;
		return [$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms];
	}

	/**
	 * @param list<string> $types
	 * @return list<int>
	 */
	public static function markedPosts(string $operationId, array $types): array
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ') ORDER BY p.ID ASC LIMIT 2',
			array_merge([PostObject::MARKER, $operationId], $types)
		));
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	/** @param array<string, mixed> $state @param array<string, mixed> $fields */
	public static function fieldsMatch(array $state, array $fields): bool
	{
		foreach ($fields as $key => $value) {
			if (!array_key_exists($key, $state['fields'])) {
				return false;
			}
			$expected = in_array($key, PostAdapter::INT_FIELDS, true) ? (int) $value : (string) $value;
			if ($state['fields'][$key] !== $expected) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Nothing outside the intent changed (fields, meta, terms), comparing hashes of the remaining state.
	 *
	 * @param list<string> $ignoreFields @param list<string> $ignoreMeta @param list<string> $ignoreTaxonomies
	 */
	public static function sameOutside(array $before, array $current, array $ignoreFields, array $ignoreMeta, array $ignoreTaxonomies): bool
	{
		foreach ([&$before, &$current] as &$state) {
			foreach ($ignoreFields as $key) {
				unset($state['fields'][$key]);
			}
			foreach ($ignoreMeta as $key) {
				unset($state['meta'][$key]);
			}
			foreach ($ignoreTaxonomies as $taxonomy) {
				unset($state['terms'][$taxonomy]);
			}
			unset($state['comments'], $state['revisions']);
		}
		unset($state);
		return PostAdapter::fingerprintOf($before) === PostAdapter::fingerprintOf($current);
	}

	/** Permanently remove a post created by an operation (undo of a create, redo of a reset). */
	public static function removePost(int $id): void
	{
		clean_post_cache($id);
		if (in_array($id, PostAdapter::stickyIds(), true)) {
			PostObject::markOptionsDirty($id);
		}
		if (!wp_delete_post($id, true)) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not remove the item.', 'llamly'), 500);
		}
		clean_post_cache($id);
	}

	/**
	 * Write a journaled S_POST-shaped state back exactly: the row (re-inserted with the same ID when absent), meta in
	 * scope, term relationships and optional retained rows (revisions). Runs inside the engine transaction.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed>|null $current
	 * @param list<string> $types
	 */
	public static function writeState(int $id, array $target, ?array $current, array $types): void
	{
		global $wpdb;
		$fields = (array) ($target['fields'] ?? []);
		if (!in_array((string) ($fields['post_type'] ?? ''), $types, true)) {
			throw new MutationFailure('undo_conflict', esc_html__('The journaled item has an unexpected type.', 'llamly'), 409);
		}
		$row = [];
		foreach (PostAdapter::COLUMNS as $column) {
			$row[$column] = in_array($column, PostAdapter::INT_FIELDS, true) ? (int) $fields[$column] : (string) $fields[$column];
		}
		if ($current === null) {
			RawRows::reinsert($wpdb->posts, 'ID', ['ID' => $id] + $row + ['to_ping' => '', 'pinged' => '', 'comment_count' => 0]);
		} elseif ($wpdb->update($wpdb->posts, $row, ['ID' => $id]) === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The item row could not be restored.', 'llamly'), 503);
		}
		$metaRows = [];
		foreach ((array) ($target['meta'] ?? []) as $key => $values) {
			foreach ((array) $values as $value) {
				$metaRows[] = ['key' => (string) $key, 'value' => (string) $value];
			}
		}
		RawRows::restoreMeta($wpdb->postmeta, 'post_id', $id, $metaRows, null, ContentGuard::metaExcludeRegex(), 'post_meta');
		self::restoreTerms($id, (string) $fields['post_type'], (array) ($target['terms'] ?? []));
		if ($current === null) {
			foreach ((array) ($target['revisions'] ?? []) as $revision) {
				$revisionId = (int) ($revision['ID'] ?? 0);
				$taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $revisionId));
				// Revisions are history, not state: one whose ID was reused is skipped instead of failing the restore.
				if ($revisionId > 0 && $taken === 0 && (int) ($revision['post_parent'] ?? 0) === $id) {
					RawRows::reinsert($wpdb->posts, 'ID', (array) $revision);
				}
			}
		}
		clean_post_cache($id);
	}

	/** @param array<string, list<int>> $terms taxonomy => term IDs */
	public static function restoreTerms(int $id, string $postType, array $terms): void
	{
		global $wpdb;
		$wanted = [];
		foreach ($terms as $taxonomy => $termIds) {
			foreach ((array) $termIds as $termId) {
				$ttId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id = %d", (string) $taxonomy, (int) $termId));
				if ($ttId < 1) {
					throw new MutationFailure('undo_conflict', esc_html__('A term used by this item no longer exists.', 'llamly'), 409);
				}
				$wanted[] = $ttId;
			}
		}
		$existing = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d FOR UPDATE", $id)));
		sort($existing);
		sort($wanted);
		if ($existing !== $wanted) {
			$removed = array_values(array_diff($existing, $wanted));
			$added = array_values(array_diff($wanted, $existing));
			foreach ($removed as $ttId) {
				if ($wpdb->delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId], ['%d', '%d']) === false) {
					throw new MutationFailure('journal_unavailable', esc_html__('Terms could not be restored.', 'llamly'), 503);
				}
			}
			foreach ($added as $ttId) {
				if ($wpdb->insert($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
					throw new MutationFailure('journal_unavailable', esc_html__('Terms could not be restored.', 'llamly'), 503);
				}
			}
			foreach (array_merge($removed, $added) as $ttId) {
				$taxonomy = (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId));
				if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
					wp_update_term_count_now([$ttId], $taxonomy);
				}
			}
		}
		clean_object_term_cache($id, $postType);
	}
}
