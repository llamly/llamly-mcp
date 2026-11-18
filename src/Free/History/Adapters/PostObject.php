<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\History\Adapter;
use Llamly\Free\History\ContentGuard;
use Llamly\Free\History\DerivedMetaAware;
use Llamly\Free\History\Engine;
use Llamly\Free\History\LegacyFingerprint;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\RawRows;

/**
 * Posts, pages, CPTs and wp_navigation (S_POST, PostAdapter::capture).
 *
 * Intents: create, update, trash, restore_revision. restore() rebuilds a journaled state exactly: status transitions
 * through the WordPress API first (untrash/trash hooks, comment statuses), wp_update_post for the remaining hooks, then
 * a raw pin of every column, meta in scope, term relationships, sticky flag and comment statuses.
 */
final class PostObject implements Adapter, DerivedMetaAware, LegacyFingerprint
{
	/** @var array<int, true> posts whose write changed the sticky list (options cache) in this request */
	private static array $optionsDirty = [];

	public const MARKER = '_llamly_pending_operation';
	private const PUBLIC_STATUSES = ['publish', 'future', 'private'];
	/** Meta WordPress maintains itself when slugs/dates/status change. */
	private const DERIVED_META = ['_wp_old_slug', '_wp_old_date'];
	/** @var array<int, list<array{string,string}>> Llamly-owned meta rows per post, read just before an effect (see guardedMetaIntact()). */
	private static array $guardedBefore = [];
	private const TRASH_META = ['_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug'];

	public function type(): string
	{
		return 'post';
	}

	public function prefix(): string
	{
		return 'p2';
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		return PostAdapter::capture($id, $forUpdate);
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
		self::$guardedBefore = [];
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$guard = ContentGuard::checkIntent($id, $intent);
		if ($guard) {
			throw $guard;
		}
		if ($op === 'create') {
			if (!ContentGuard::isAllowedType((string) ($fields['post_type'] ?? ''))) {
				throw new MutationFailure('unsupported_post_type', esc_html__('This post type cannot be changed through the post tools.', 'llamly'), 400);
			}
			$data = $fields + ['meta_input' => [self::MARKER => $operationId]];
			if (isset($fields['post_date_gmt'])) {
				$data['edit_date'] = true;
			}
			$created = wp_insert_post(wp_slash($data), true);
			if (is_wp_error($created)) {
				return $created;
			}
			$id = (int) $created;
			if ($id < 1) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not create the post.', 'llamly'), 500);
			}
			$this->applyRelations($id, $intent);
			clean_post_cache($id);
			return $id;
		}

		self::$guardedBefore = [$id => $this->guardedMeta($id)];
		clean_post_cache($id);
		if ($op === 'trash') {
			if (!EMPTY_TRASH_DAYS) {
				throw new MutationFailure('trash_disabled', esc_html__('The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so trashing would delete permanently.', 'llamly'), 409);
			}
			if (get_post_status($id) === 'trash') {
				throw new MutationFailure('already_trashed', esc_html__('The post is already in the trash.', 'llamly'), 409);
			}
			$trashed = wp_trash_post($id);
			if (!$trashed instanceof \WP_Post) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not move the post to the trash.', 'llamly'), 409);
			}
			clean_post_cache($id);
			return $id;
		}
		if ($op === 'restore_revision') {
			$revisionId = (int) ($intent['revision_id'] ?? 0);
			$revision = wp_get_post_revision($revisionId);
			if (!$revision instanceof \WP_Post || (int) $revision->post_parent !== $id) {
				throw new MutationFailure('object_not_found', esc_html__('The revision does not belong to this post.', 'llamly'), 404);
			}
			$restored = wp_restore_post_revision($revisionId);
			if (!$restored || is_wp_error($restored)) {
				return is_wp_error($restored) ? $restored : new \WP_Error('mutation_failed', __('WordPress did not restore the revision.', 'llamly'), ['status' => 500]);
			}
			clean_post_cache($id);
			return $id;
		}
		if ($fields) {
			$data = ['ID' => $id] + $fields;
			if (isset($fields['post_date_gmt'])) {
				$data['edit_date'] = true;
			}
			$updated = wp_update_post(wp_slash($data), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			if ((int) $updated !== $id) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not update the requested post.', 'llamly'), 500);
			}
		}
		$this->applyRelations($id, $intent);
		clean_post_cache($id);
		return $id;
	}

	/** Terms, meta, featured image and sticky flag of a create/update intent. @param array<string, mixed> $intent */
	private function applyRelations(int $id, array $intent): void
	{
		foreach ((array) ($intent['terms'] ?? []) as $taxonomy => $termIds) {
			$set = wp_set_object_terms($id, array_map('intval', (array) $termIds), (string) $taxonomy, false);
			if (is_wp_error($set)) {
				throw new MutationFailure('invalid_terms', esc_html($set->get_error_message()), 400);
			}
		}
		$meta = (array) ($intent['meta'] ?? []);
		if (array_key_exists('featured_media', $intent)) {
			$featured = (int) $intent['featured_media'];
			$meta['_thumbnail_id'] = $featured > 0 ? (string) $featured : null;
		}
		foreach ($meta as $key => $value) {
			$key = (string) $key;
			delete_post_meta($id, $key);
			if ($value !== null && !add_post_meta($id, $key, wp_slash((string) $value))) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not store the post metadata.', 'llamly'), 500);
			}
		}
		if (array_key_exists('sticky', $intent)) {
			self::$optionsDirty[$id] = true;
			if ($intent['sticky']) {
				stick_post($id);
			} else {
				unstick_post($id);
			}
		}
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		global $wpdb;
		self::$guardedBefore = []; // a snapshot left by an earlier operation must never be compared with this one
		$guard = ContentGuard::checkRestore($id, $target, $current);
		if ($guard) {
			throw $guard;
		}
		if ($target === null) {
			if ($current === null) {
				return $id;
			}
			// Undo of a create removes the post only while nothing new hangs from it. FOR UPDATE takes next-key locks on the
			// indexed dependents, so a comment or child saved concurrently waits for this transaction instead of being lost.
			$comments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d FOR UPDATE", $id));
			$children = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type NOT IN ('revision') FOR UPDATE", $id));
			$menuItems = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->postmeta} t ON t.post_id = m.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type' WHERE m.meta_key = '_menu_item_object_id' AND m.meta_value = %s",
				(string) $id
			));
			if ($comments > 0 || $children > 0 || $menuItems > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('Comments, child items or menu items now depend on this post, so removing it would lose them.', 'llamly'), 409);
			}
			self::$optionsDirty[$id] = true;
			unstick_post($id);
			$deleted = wp_delete_post($id, true);
			if (!$deleted) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not remove the post.', 'llamly'), 500);
			}
			return $id;
		}

		$fields = (array) $target['fields'];
		if (!ContentGuard::isAllowedType((string) ($fields['post_type'] ?? ''))) {
			throw new MutationFailure('unsupported_post_type', esc_html__('This post type cannot be restored through the post tools.', 'llamly'), 409);
		}
		if ($current === null) {
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id));
			if ($exists > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('Another item now uses this post ID.', 'llamly'), 409);
			}
			self::$guardedBefore = [$id => $this->guardedMeta($id)];
			$inserted = wp_insert_post(wp_slash(['import_id' => $id] + $this->apiFields($fields)), true);
			if (is_wp_error($inserted)) {
				return $inserted;
			}
			if ((int) $inserted !== $id) {
				throw new MutationFailure('undo_conflict', esc_html__('WordPress could not recreate the post with its original ID.', 'llamly'), 409);
			}
		} else {
			self::$guardedBefore = [$id => $this->guardedMeta($id)];
			clean_post_cache($id);
			$from = (string) $current['fields']['post_status'];
			$to = (string) $fields['post_status'];
			if ($from === 'trash' && $to !== 'trash') {
				// Untrash first so its hooks run and comments leave the post-trashed state; the pin below sets the status.
				if (!wp_untrash_post($id)) {
					throw new MutationFailure('mutation_failed', esc_html__('WordPress did not restore the post from the trash.', 'llamly'), 409);
				}
			} elseif ($from !== 'trash' && $to === 'trash') {
				if (!EMPTY_TRASH_DAYS) {
					throw new MutationFailure('trash_disabled', esc_html__('The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so trashing would delete permanently.', 'llamly'), 409);
				}
				if (!wp_trash_post($id) instanceof \WP_Post) {
					throw new MutationFailure('mutation_failed', esc_html__('WordPress did not move the post to the trash.', 'llamly'), 409);
				}
			}
			clean_post_cache($id);
			if ($to !== 'trash') {
				$updated = wp_update_post(wp_slash(['ID' => $id] + $this->apiFields($fields)), true);
				if (is_wp_error($updated)) {
					return $updated;
				}
			}
		}

		// Pin: WordPress rewrites slugs, dates, guid and modified times while saving; the journaled values win.
		$row = [];
		foreach (PostAdapter::COLUMNS as $column) {
			$row[$column] = in_array($column, PostAdapter::INT_FIELDS, true) ? (int) $fields[$column] : (string) $fields[$column];
		}
		if ($wpdb->update($wpdb->posts, $row, ['ID' => $id]) === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The post row could not be restored.', 'llamly'), 503);
		}
		$metaRows = [];
		foreach ((array) ($target['meta'] ?? []) as $key => $values) {
			foreach ((array) $values as $value) {
				$metaRows[] = ['key' => (string) $key, 'value' => (string) $value];
			}
		}
		$currentMeta = RawRows::captureMeta($wpdb->postmeta, 'post_id', $id, null, ContentGuard::metaExcludeRegex(), true);
		if ($currentMeta === null) {
			throw new MutationFailure('journal_unavailable', esc_html__('Post metadata could not be read for restore.', 'llamly'), 503);
		}
		if ($this->metaMap($currentMeta) !== $this->metaMap($metaRows)) {
			RawRows::restoreMeta($wpdb->postmeta, 'post_id', $id, $metaRows, null, ContentGuard::metaExcludeRegex(), 'post_meta');
		}
		$this->restoreTerms($id, (string) $fields['post_type'], (array) ($target['terms'] ?? []));
		$this->restoreSticky($id, (bool) ($target['sticky'] ?? false));
		$this->restoreComments($id, (array) ($target['comments'] ?? []));
		clean_post_cache($id);
		return $id;
	}

	/** @param list<array{key:string,value:string}> $rows @return array<string, list<string>> */
	private function metaMap(array $rows): array
	{
		$map = [];
		foreach ($rows as $row) {
			$map[$row['key']][] = $row['value'];
		}
		ksort($map, SORT_STRING);
		return $map;
	}

	/** @param array<string, list<int>> $terms taxonomy => term IDs */
	private function restoreTerms(int $id, string $postType, array $terms): void
	{
		global $wpdb;
		$wanted = [];
		foreach ($terms as $taxonomy => $termIds) {
			foreach ((array) $termIds as $termId) {
				$ttId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id = %d", (string) $taxonomy, (int) $termId));
				if ($ttId < 1) {
					throw new MutationFailure('undo_conflict', esc_html__('A category, tag or term used by this post no longer exists.', 'llamly'), 409);
				}
				$wanted[] = $ttId;
			}
		}
		$existing = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d FOR UPDATE", $id)));
		sort($existing);
		sort($wanted);
		if ($existing === $wanted) {
			clean_object_term_cache($id, $postType);
			return;
		}
		$removed = array_values(array_diff($existing, $wanted));
		$added = array_values(array_diff($wanted, $existing));
		foreach ($removed as $ttId) {
			if ($wpdb->delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId], ['%d', '%d']) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('Post terms could not be restored.', 'llamly'), 503);
			}
		}
		foreach ($added as $ttId) {
			if ($wpdb->insert($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
				throw new MutationFailure('journal_unavailable', esc_html__('Post terms could not be restored.', 'llamly'), 503);
			}
		}
		foreach (array_merge($removed, $added) as $ttId) {
			$taxonomy = (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId));
			if ($taxonomy !== '') {
				wp_update_term_count_now([$ttId], $taxonomy);
			}
		}
		clean_object_term_cache($id, $postType);
	}

	private function restoreSticky(int $id, bool $sticky): void
	{
		$isSticky = in_array($id, PostAdapter::stickyIds(), true);
		if ($isSticky === $sticky) {
			return;
		}
		self::$optionsDirty[$id] = true;
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('sticky_posts', 'options');
		if ($sticky) {
			stick_post($id);
		} else {
			unstick_post($id);
		}
	}

	/** @param list<array{id:int,approved:string}> $comments */
	private function restoreComments(int $id, array $comments): void
	{
		global $wpdb;
		if (!$comments) {
			return;
		}
		$touched = [];
		foreach ($comments as $comment) {
			$commentId = (int) ($comment['id'] ?? 0);
			$status = (string) ($comment['approved'] ?? '');
			$now = $wpdb->get_var($wpdb->prepare("SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d AND comment_post_ID = %d FOR UPDATE", $commentId, $id));
			if ($now === null || (string) $now === $status) {
				continue;
			}
			if ($wpdb->update($wpdb->comments, ['comment_approved' => $status], ['comment_ID' => $commentId], ['%s'], ['%d']) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('Comment statuses could not be restored.', 'llamly'), 503);
			}
			$touched[] = $commentId;
		}
		if ($touched) {
			clean_comment_cache($touched);
			wp_update_comment_count_now($id);
		}
	}

	/** @param array<string, mixed> $fields @return array<string, mixed> */
	private function apiFields(array $fields): array
	{
		$api = [];
		foreach (['post_author', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'post_content_filtered', 'post_parent', 'menu_order', 'post_type', 'post_mime_type'] as $key) {
			$api[$key] = $fields[$key];
		}
		if (($fields['post_date_gmt'] ?? '0000-00-00 00:00:00') !== '0000-00-00 00:00:00') {
			$api['post_date'] = (string) $fields['post_date'];
			$api['post_date_gmt'] = (string) $fields['post_date_gmt'];
			$api['edit_date'] = true;
		}
		return $api;
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'trash') {
			$fields = ['post_status' => 'trash'];
		}
		foreach ($fields as $key => $value) {
			if (!array_key_exists($key, $current['fields'])) {
				return false;
			}
			$expected = in_array($key, PostAdapter::INT_FIELDS, true) ? (int) $value : (string) $value;
			if ($current['fields'][$key] !== $expected) {
				return false;
			}
		}
		$meta = (array) ($intent['meta'] ?? []);
		if (array_key_exists('featured_media', $intent)) {
			$meta['_thumbnail_id'] = (int) $intent['featured_media'] > 0 ? (string) (int) $intent['featured_media'] : null;
		}
		foreach ($meta as $key => $value) {
			$now = $current['meta'][(string) $key] ?? null;
			if ($value === null ? $now !== null : $now !== [(string) $value]) {
				return false;
			}
		}
		foreach ((array) ($intent['terms'] ?? []) as $taxonomy => $termIds) {
			$want = array_map('intval', (array) $termIds);
			sort($want);
			if (($current['terms'][(string) $taxonomy] ?? []) !== array_values(array_unique($want))) {
				return false;
			}
		}
		if (array_key_exists('sticky', $intent) && (bool) $current['sticky'] !== (bool) $intent['sticky']) {
			return false;
		}
		if ($before === null) {
			return true;
		}

		// Nothing outside the intent may have changed, except what WordPress derives from it and public meta written by hooks.
		return $this->derivedMeta($before, $current, $intent)['ok'];
	}

	/**
	 * Compares the state a forward write left with the pre-effect state, outside the intent (PostAdapter::compareWithDerived).
	 * Not part of the Adapter interface: the Engine calls it when the adapter has it.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $intent
	 * @return array{ok:bool,derived:list<string>,reason:?string}
	 */
	public function derivedMeta(?array $before, array $current, array $intent): array
	{
		if ($before === null) {
			return ['ok' => true, 'derived' => [], 'reason' => null];
		}
		if (!$this->guardedMetaIntact((int) ($current['post_id'] ?? 0))) {
			return ['ok' => false, 'derived' => [], 'reason' => 'meta_outside_intent'];
		}
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'trash') {
			$fields = ['post_status' => 'trash'];
		}
		$meta = (array) ($intent['meta'] ?? []);
		if (array_key_exists('featured_media', $intent)) {
			$meta['_thumbnail_id'] = null;
		}
		$ignoreFields = ['post_modified', 'post_modified_gmt'];
		if ($op === 'trash' || isset($fields['post_status']) || isset($fields['post_date_gmt'])) {
			$ignoreFields = array_merge($ignoreFields, ['post_name', 'post_date', 'post_date_gmt']);
		} elseif (($before['fields']['post_date_gmt'] ?? '') === '0000-00-00 00:00:00' && $current['fields']['post_date_gmt'] === '0000-00-00 00:00:00') {
			// A draft without a fixed date "floats": WordPress moves its local date to now on every save.
			$ignoreFields[] = 'post_date';
		}
		// WordPress re-escapes the guid on the first save of a post created with a raw "&" in its URL (non-public CPTs: ?post_type=x&p=N
		// becomes ...&#038;p=N). Same URL, escaped; any other guid change stays a mismatch.
		if (($before['fields']['guid'] ?? '') !== ($current['fields']['guid'] ?? '')
			&& wp_specialchars_decode((string) ($before['fields']['guid'] ?? ''), ENT_QUOTES) === wp_specialchars_decode((string) ($current['fields']['guid'] ?? ''), ENT_QUOTES)) {
			$ignoreFields[] = 'guid';
		}
		$ignoreMeta = self::DERIVED_META;
		if ($op === 'trash' || ($before['fields']['post_status'] ?? '') === 'trash') {
			$ignoreMeta = array_merge($ignoreMeta, self::TRASH_META);
		}
		return PostAdapter::compareWithDerived($before, $current, [
			'fields' => array_merge(array_keys($fields), $ignoreFields),
			'meta' => array_merge(array_map('strval', array_keys($meta)), $ignoreMeta),
			'terms' => array_map('strval', array_keys((array) ($intent['terms'] ?? []))),
			'sticky' => array_key_exists('sticky', $intent),
		]);
	}

	/**
	 * Llamly's own meta (markers, tokens, module data) and attachment file records live outside the post state, so the state
	 * comparison cannot see a hook changing them. They are read raw before the effect and compared once afterwards.
	 *
	 * @return list<array{string,string}>
	 */
	private function guardedMeta(int $id): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')) ORDER BY meta_key ASC, meta_id ASC",
			$id,
			$wpdb->esc_like('_llamly_') . '%',
			$wpdb->esc_like('llamly_') . '%'
		), ARRAY_N);
		return is_array($rows) ? array_map(static fn(array $row): array => [(string) $row[0], (string) $row[1]], $rows) : [];
	}

	/**
	 * Whether the Llamly-owned meta of a post is exactly as it was before the effect (one-shot: the snapshot is consumed).
	 * Without a snapshot (a recovery after a crash) there is nothing to compare and it passes.
	 */
	public function guardedMetaIntact(int $id): bool
	{
		if (!isset(self::$guardedBefore[$id])) {
			return true;
		}
		$before = self::$guardedBefore[$id];
		unset(self::$guardedBefore[$id]);
		return $before === $this->guardedMeta($id);
	}

	/**
	 * Verifies an undo/redo result against its target, ignoring the derived keys of the original operation.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $observed
	 * @param list<string> $inheritedDerived
	 * @return array{ok:bool,derived:list<string>,reason:?string}
	 */
	public function derivedMetaInverse(array $target, array $observed, ?array $before, array $inheritedDerived): array
	{
		// Keys this undo/redo had to change must come out exactly as journaled; a hook rewriting them is corruption.
		$before ??= ['meta' => []]; // recreate of a deleted post: every key of the target must come back exactly
		$strict = [];
		foreach (array_unique(array_merge(array_keys((array) ($before['meta'] ?? [])), array_keys((array) ($target['meta'] ?? [])))) as $key) {
			$key = (string) $key;
			if (!in_array($key, $inheritedDerived, true) && (($before['meta'][$key] ?? null) !== ($target['meta'][$key] ?? null))) {
				$strict[] = $key;
			}
		}
		return PostAdapter::compareWithDerived($target, $observed, ['meta' => $inheritedDerived, 'strict_meta' => $strict]);
	}

	/**
	 * The target an undo/redo restores: the journaled one, with the derived keys left as they are now.
	 *
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $current
	 * @param list<string> $derived
	 * @return array<string, mixed>|null
	 */
	public function inverseTarget(?array $target, ?array $current, array $derived): ?array
	{
		return PostAdapter::targetKeepingMeta($target, $current, $derived);
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$beforeStatus = (string) ($before['fields']['post_status'] ?? '');
		$op = (string) ($intent['op'] ?? '');
		if (in_array($op, ['undo', 'redo'], true)) {
			$afterStatus = (string) ($after['fields']['post_status'] ?? '');
			$public = in_array($beforeStatus, self::PUBLIC_STATUSES, true) || in_array($afterStatus, self::PUBLIC_STATUSES, true);
			return [
				'destructive' => $after === null || ($afterStatus === 'trash' && $beforeStatus !== 'trash'),
				'public' => $public,
				'sensitive' => false,
				'external_effects' => $public ? ['Pings, feeds, sitemaps, caches and webhooks of third parties are not undone.'] : [],
			];
		}
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$status = $op === 'trash' ? 'trash' : (string) ($fields['post_status'] ?? $beforeStatus);
		$public = in_array($beforeStatus, self::PUBLIC_STATUSES, true) || in_array($status, self::PUBLIC_STATUSES, true);
		return [
			// Trashing an item already in the trash destroys nothing; the adapter refuses it without asking a human first.
			'destructive' => $op === 'trash' && $beforeStatus !== 'trash',
			'public' => $public,
			'sensitive' => false,
			'external_effects' => $public ? ['Pings, feeds, sitemaps, caches and webhooks of third parties are not undone.'] : [],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		if ($id > 0 && get_post($id)) {
			$removes = $after === null || $before === null
				|| ($after['fields']['post_status'] ?? '') === 'trash' || ($before['fields']['post_status'] ?? '') === 'trash';
			return user_can($userId, 'edit_post', $id) && (!$removes || user_can($userId, 'delete_post', $id));
		}
		$type = get_post_type_object((string) ($after['fields']['post_type'] ?? $before['fields']['post_type'] ?? 'post'));
		return $type instanceof \WP_Post_Type && user_can($userId, $type->cap->edit_posts);
	}

	public function label(?array $state): string
	{
		$title = trim((string) ($state['fields']['post_title'] ?? ''));
		return $title !== '' ? $title : __('(untitled)', 'llamly');
	}

	public function tables(): array
	{
		global $wpdb;
		return [$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->comments, $wpdb->options];
	}

	public function findMarked(string $operationId): array
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 2",
			self::MARKER,
			$operationId
		));
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		delete_post_meta($id, self::MARKER, $operationId);
	}

	/** True while a write of this request changed the sticky list for this post (read by the pattern adapter too). */
	public static function optionsDirty(int $id): bool
	{
		return !empty(self::$optionsDirty[$id]);
	}

	/** Marks the sticky list as touched for this post, so the next cache flush drops the options cache. */
	public static function markOptionsDirty(int $id): void
	{
		self::$optionsDirty[$id] = true;
	}

	/** Called by the engine after the final cache pass for this post. */
	public function forgetRequestState(int $id): void
	{
		unset(self::$optionsDirty[$id]);
	}

	public function flushCaches(int $id): void
	{
		global $wpdb;
		PatternObject::flushPostCaches($id);
		// The options cache is only dropped when this object's write touched the sticky list.
		if (!empty(self::$optionsDirty[$id])) {
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('sticky_posts', 'options');
		}
		$comments = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d LIMIT 500", $id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		if ($comments) {
			clean_comment_cache($comments);
		}
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$result = ['post_id' => $id];
		if ($after !== null) {
			$result['post_status'] = (string) $after['fields']['post_status'];
		}
		$names = ['post_title' => 'title', 'post_content' => 'content', 'post_status' => 'status', 'post_excerpt' => 'excerpt', 'post_name' => 'slug', 'post_author' => 'author', 'post_parent' => 'parent', 'menu_order' => 'menu_order', 'post_date_gmt' => 'date'];
		$op = (string) ($intent['op'] ?? '');
		if (in_array($op, ['update', 'restore_revision'], true)) {
			$changed = [];
			foreach (array_keys((array) ($intent['fields'] ?? [])) as $field) {
				if (isset($names[$field])) {
					$changed[] = $names[$field];
				}
			}
			foreach (['terms', 'meta', 'featured_media', 'sticky'] as $part) {
				if (array_key_exists($part, $intent)) {
					$changed[] = $part;
				}
			}
			$result['changed_fields'] = array_values(array_unique($changed));
		}
		// Request-dependent notes (rejected meta keys, scheduling) are added by the tool, outside the journaled intent.
		$warnings = [];
		foreach (['page_on_front' => 'front page', 'page_for_posts' => 'posts page', 'wp_page_for_privacy_policy' => 'privacy policy page'] as $option => $label) {
			if ((int) get_option($option) === $id && $id > 0) {
				$warnings[] = sprintf('This post is the site %s.', $label);
			}
		}
		if ($warnings) {
			$result['warnings'] = array_values(array_unique($warnings));
		}
		return $result;
	}
}
