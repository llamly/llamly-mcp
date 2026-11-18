<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\Core\Redactor;
use Llamly\Free\History\Adapter;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\RawRows;
use Llamly\Free\History\SideEffects;
use Llamly\Free\History\State;

/**
 * Comments. State = the full wp_comments row (IP and user agent live only in the
 * journal, never in tool output), allowlisted commentmeta, the direct children and, for the retain scope, every
 * commentmeta row. Update/trash are restored in place; a permanent delete is restored by re-inserting the row with its
 * original comment_ID, its raw commentmeta and its re-parented children.
 */
final class CommentObject implements Adapter
{
	public const MARKER = '_llamly_pending_operation';
	private const INT_COLUMNS = ['comment_ID', 'comment_post_ID', 'comment_karma', 'comment_parent', 'user_id'];
	private const TEXT_FIELDS = ['comment_content', 'comment_author', 'comment_author_email', 'comment_author_url'];
	private const STATUS_VALUES = ['approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash'];

	public function type(): string
	{
		return 'comment';
	}

	public function prefix(): string
	{
		return 'c1';
	}

	/** @return list<string> */
	public static function metaAllowlist(): array
	{
		$keys = apply_filters('llamly_comment_meta_allowlist', ['_wp_trash_meta_status', '_wp_trash_meta_time', 'rating', 'verified']);
		return is_array($keys) ? array_values(array_unique(array_map('strval', $keys))) : [];
	}

	/** Meta never kept in a snapshot: the pending-create marker and anything that looks like a secret. */
	private static function excludePattern(): string
	{
		$sensitive = Redactor::SENSITIVE_KEY;
		$body = substr($sensitive, 1, (int) strrpos($sensitive, '/') - 1);
		return '/^' . preg_quote(self::MARKER, '/') . '$|' . $body . '/i';
	}

	public static function statusSlug(string $approved): string
	{
		$slug = array_search($approved, self::STATUS_VALUES, true);
		return $slug === false ? $approved : (string) $slug;
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function normalize(array $row): array
	{
		foreach ($row as $key => $value) {
			$row[$key] = in_array($key, self::INT_COLUMNS, true) ? (int) $value : (string) $value;
		}
		return $row;
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		if ($id < 1) {
			return null;
		}
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d FOR UPDATE", $id), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $id), ARRAY_A);
		if ($wpdb->last_error !== '') {
			throw self::unreadable();
		}
		if (!is_array($row)) {
			return null;
		}
		$meta = RawRows::captureMeta($wpdb->commentmeta, 'comment_id', $id, self::metaAllowlist(), '', $forUpdate);
		$metaAll = RawRows::captureMeta($wpdb->commentmeta, 'comment_id', $id, null, self::excludePattern(), $forUpdate);
		$children = $forUpdate
			? $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent = %d ORDER BY comment_ID ASC FOR UPDATE", $id))
			: $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent = %d ORDER BY comment_ID ASC", $id));
		if ($meta === null || $metaAll === null || !is_array($children) || $wpdb->last_error !== '') {
			throw self::unreadable();
		}
		return [
			'comment_id' => $id,
			'row' => self::normalize($row),
			'meta' => $meta,
			'children' => array_map('intval', $children),
			'meta_all' => $metaAll,
		];
	}

	/** A database error must never read as "the comment does not exist". */
	private static function unreadable(): MutationFailure
	{
		return new MutationFailure('journal_unavailable', __('The comment could not be read from the database.', 'llamly'), 503);
	}

	/** True when the comment carries meta that snapshots never keep (secret-looking keys), so a delete could not be undone faithfully. */
	public static function hasUnsnapshottedMeta(int $id): bool
	{
		global $wpdb;
		$keys = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key <> %s", $id, self::MARKER));
		if (!is_array($keys) || $wpdb->last_error !== '') {
			throw self::unreadable();
		}
		foreach ($keys as $key) {
			if (preg_match(self::excludePattern(), (string) $key)) {
				return true;
			}
		}
		return false;
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return State::hash('c1', null);
		}
		return State::hash('c1', ['row' => $state['row'], 'meta' => $state['meta'], 'children' => $state['children']]);
	}

	/**
	 * Revision hashes for a page of comments with three queries in total.
	 *
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	public function fingerprints(array $ids): array
	{
		global $wpdb;
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (!$ids) {
			return [];
		}
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')', $ids), ARRAY_A);
		$allow = self::metaAllowlist();
		$meta = [];
		if ($allow) {
			$metaRows = $wpdb->get_results($wpdb->prepare(
				"SELECT comment_id, meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ') AND meta_key IN (' . implode(',', array_fill(0, count($allow), '%s')) . ') ORDER BY meta_id ASC',
				array_merge($ids, $allow)
			), ARRAY_A);
			foreach ((array) $metaRows as $m) {
				$meta[(int) $m['comment_id']][] = ['key' => (string) $m['meta_key'], 'value' => (string) $m['meta_value']];
			}
		}
		$children = [];
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT comment_ID, comment_parent FROM {$wpdb->comments} WHERE comment_parent IN (" . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY comment_ID ASC', $ids), ARRAY_A) as $c) {
			$children[(int) $c['comment_parent']][] = (int) $c['comment_ID'];
		}
		$out = [];
		foreach ((array) $rows as $row) {
			$id = (int) $row['comment_ID'];
			$hash = $this->fingerprint(['row' => self::normalize($row), 'meta' => $meta[$id] ?? [], 'children' => $children[$id] ?? []]);
			if ($hash !== null) {
				$out[$id] = $hash;
			}
		}
		return $out;
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		return SideEffects::mailSilenced(function () use ($id, $intent, $operationId): int|\WP_Error {
			$op = (string) ($intent['op'] ?? '');
			$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
			if ($op === 'create') {
				return $this->applyCreate($fields, $operationId);
			}
			if ($op === 'update') {
				return $this->applyUpdate($id, $fields);
			}
			if ($op === 'trash' || $op === 'delete') {
				return $this->applyDelete($id, $op);
			}
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown comment operation.', 'llamly'), 400);
		});
	}

	/** @param array<string, mixed> $fields */
	private function applyCreate(array $fields, string $operationId): int|\WP_Error
	{
		$status = (string) ($fields['status'] ?? 'hold');
		$data = [
			'comment_post_ID' => (int) $fields['comment_post_ID'],
			'comment_parent' => (int) ($fields['comment_parent'] ?? 0),
			'comment_content' => (string) $fields['comment_content'],
			'comment_author' => (string) ($fields['comment_author'] ?? ''),
			'comment_author_email' => (string) ($fields['comment_author_email'] ?? ''),
			'comment_author_url' => (string) ($fields['comment_author_url'] ?? ''),
			'comment_author_IP' => '',
			'comment_agent' => '',
			'comment_type' => 'comment',
			'user_id' => (int) ($fields['user_id'] ?? 0),
			'comment_meta' => [self::MARKER => $operationId],
		];
		// Moderators are auto-approved by WordPress, which would fire comment_post with approved=1 before the requested
		// status applies; hand the requested status to the whole pipeline instead.
		$wanted = self::STATUS_VALUES[$status] ?? '0';
		$force = static fn() => $wanted;
		add_filter('pre_comment_approved', $force, PHP_INT_MAX);
		try {
			$created = wp_new_comment(wp_slash($data), true);
		} finally {
			remove_filter('pre_comment_approved', $force, PHP_INT_MAX);
		}
		if (is_wp_error($created)) {
			return $created;
		}
		$id = (int) $created;
		if ($id < 1) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not create the comment.', 'llamly'), 500);
		}
		$this->setStatus($id, $status);
		return $id;
	}

	/** @param array<string, mixed> $fields */
	private function applyUpdate(int $id, array $fields): int|\WP_Error
	{
		$data = [];
		foreach (self::TEXT_FIELDS as $field) {
			if (array_key_exists($field, $fields)) {
				$data[$field] = (string) $fields[$field];
			}
		}
		if ($data) {
			global $wpdb;
			$dates = $wpdb->get_row($wpdb->prepare("SELECT comment_date, comment_date_gmt FROM {$wpdb->comments} WHERE comment_ID = %d", $id), ARRAY_A);
			if (!is_array($dates)) {
				throw self::unreadable();
			}
			$updated = wp_update_comment(wp_slash(['comment_ID' => $id] + $data), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			// wp_update_comment() derives comment_date_gmt from comment_date with the current site timezone; keep the stored pair.
			if ($wpdb->update($wpdb->comments, $dates, ['comment_ID' => $id], ['%s', '%s'], ['%d']) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('The comment dates could not be kept.', 'llamly'), 503);
			}
			clean_comment_cache($id);
		}
		if (isset($fields['status'])) {
			$this->setStatus($id, (string) $fields['status']);
		}
		clean_comment_cache($id);
		return $id;
	}

	private function applyDelete(int $id, string $op): int|\WP_Error
	{
		$comment = get_comment($id);
		if (!$comment instanceof \WP_Comment) {
			throw new MutationFailure('object_not_found', esc_html__('The comment was not found.', 'llamly'), 404);
		}
		$permanent = self::isPermanent(self::statusSlug((string) $comment->comment_approved), $op === 'delete');
		if ($permanent !== ($op === 'delete')) {
			throw new MutationFailure('stale_revision', esc_html__('The comment status changed, so the delete plan no longer applies.', 'llamly'), 409);
		}
		if ($permanent && self::hasUnsnapshottedMeta($id)) {
			throw new MutationFailure('not_reversible', esc_html__('This comment has protected metadata that cannot be kept for undo, so it cannot be deleted permanently.', 'llamly'), 409);
		}
		if (!wp_delete_comment($id, $permanent)) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not delete the comment.', 'llamly'), 500);
		}
		if ($permanent) {
			wp_update_comment_count((int) $comment->comment_post_ID);
		}
		return $id;
	}

	/** Mirror of wp_delete_comment(): trash/spam or a disabled trash turn any delete into a permanent one. */
	public static function isPermanent(string $statusSlug, bool $force): bool
	{
		return $force || !EMPTY_TRASH_DAYS || in_array($statusSlug, ['trash', 'spam'], true);
	}

	/**
	 * Status change through the WordPress API so counts, transitions and trash bookkeeping stay consistent.
	 * $withTrashMeta=false (restores) leaves the _wp_trash_meta_* rows to the meta restore that follows.
	 */
	private function setStatus(int $id, string $slug, bool $withTrashMeta = true): void
	{
		global $wpdb;
		if (!isset(self::STATUS_VALUES[$slug])) {
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown comment status.', 'llamly'), 400);
		}
		$current = $wpdb->get_var($wpdb->prepare("SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d", $id));
		if ((string) $current === self::STATUS_VALUES[$slug]) {
			return; // wp_set_comment_status() reports "0 rows" as a failure.
		}
		clean_comment_cache($id);
		if ($withTrashMeta && $slug === 'trash') {
			if (!EMPTY_TRASH_DAYS) {
				throw new MutationFailure('invalid_arguments', esc_html__('The trash is disabled on this site; delete the comment instead.', 'llamly'), 400);
			}
			$result = wp_trash_comment($id);
		} elseif ($withTrashMeta && $slug === 'spam') {
			$result = wp_spam_comment($id);
		} else {
			$result = wp_set_comment_status($id, $slug, true);
			if ($withTrashMeta && is_bool($result) && $result && !in_array($slug, ['trash', 'spam'], true)) {
				delete_comment_meta($id, '_wp_trash_meta_status');
				delete_comment_meta($id, '_wp_trash_meta_time');
			}
		}
		if (is_wp_error($result)) {
			throw new MutationFailure(esc_html($result->get_error_code()), esc_html($result->get_error_message()), 500);
		}
		if ($result === false) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not change the comment status.', 'llamly'), 500);
		}
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		return SideEffects::mailSilenced(function () use ($id, $target, $current): int|\WP_Error {
			if ($target === null) {
				return $this->removeComment($id, $current);
			}
			return $current === null ? $this->reinsert($id, $target) : $this->restoreInPlace($id, $target, $current);
		});
	}

	/** @param array<string, mixed>|null $current */
	private function removeComment(int $id, ?array $current): int
	{
		if ($current === null) {
			return $id;
		}
		if (self::hasUnsnapshottedMeta($id)) {
			throw new MutationFailure('undo_conflict', esc_html__('This comment has protected metadata that cannot be kept for undo, so it cannot be removed.', 'llamly'), 409);
		}
		// Children need no check here: the engine proved the state equals the journaled one (children included), and a
		// redo of a delete legitimately moves them up to the parent, exactly like wp_delete_comment().
		if (!wp_delete_comment($id, true)) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not remove the comment.', 'llamly'), 500);
		}
		wp_update_comment_count((int) $current['row']['comment_post_ID']);
		return $id;
	}

	/** @param array<string, mixed> $target */
	private function reinsert(int $id, array $target): int
	{
		global $wpdb;
		$row = $target['row'];
		$postId = (int) $row['comment_post_ID'];
		$parent = (int) $row['comment_parent'];
		if (!get_post($postId)) {
			throw new MutationFailure('undo_conflict', esc_html__('The post of this comment no longer exists.', 'llamly'), 409);
		}
		if ($parent > 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID = %d", $parent)) !== 1) {
			throw new MutationFailure('undo_conflict', esc_html__('The parent comment no longer exists.', 'llamly'), 409);
		}
		// Children were moved up to the grandparent by the delete; check every one before touching anything.
		$children = array_map('intval', (array) ($target['children'] ?? []));
		foreach ($children as $childId) {
			$childParent = $wpdb->get_var($wpdb->prepare("SELECT comment_parent FROM {$wpdb->comments} WHERE comment_ID = %d FOR UPDATE", $childId));
			if ($childParent === null || (int) $childParent !== $parent) {
				throw new MutationFailure('undo_conflict', esc_html__('A reply to this comment was changed or removed since it was deleted.', 'llamly'), 409);
			}
		}
		RawRows::reinsert($wpdb->comments, 'comment_ID', $row);
		foreach ((array) ($target['meta_all'] ?? []) as $meta) {
			if ($wpdb->insert($wpdb->commentmeta, ['comment_id' => $id, 'meta_key' => $meta['key'], 'meta_value' => $meta['value']], ['%d', '%s', '%s']) !== 1) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('Comment metadata could not be restored.', 'llamly'), 503);
			}
		}
		if ($children) {
			if ($wpdb->query($wpdb->prepare("UPDATE {$wpdb->comments} SET comment_parent = %d WHERE comment_ID IN (" . implode(',', array_fill(0, count($children), '%d')) . ')', array_merge([$id], $children))) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('Replies could not be attached back to the comment.', 'llamly'), 503);
			}
			clean_comment_cache($children);
		}
		clean_comment_cache($id);
		wp_cache_delete($id, 'comment_meta');
		wp_update_comment_count($postId);
		return $id;
	}

	/** @param array<string, mixed> $target @param array<string, mixed> $current */
	private function restoreInPlace(int $id, array $target, array $current): int
	{
		global $wpdb;
		$wanted = (string) $target['row']['comment_approved'];
		$now = (string) $current['row']['comment_approved'];
		if ($wanted !== $now && in_array($wanted, self::STATUS_VALUES, true) && in_array($now, self::STATUS_VALUES, true)) {
				$this->setStatus($id, self::statusSlug($wanted), false);
		}
		$row = $target['row'];
		unset($row['comment_ID']);
		$formats = [];
		foreach ($row as $value) {
			$formats[] = is_int($value) ? '%d' : '%s';
		}
		if ($wpdb->update($wpdb->comments, $row, ['comment_ID' => $id], $formats, ['%d']) === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The comment row could not be restored.', 'llamly'), 503);
		}
		RawRows::restoreMeta($wpdb->commentmeta, 'comment_id', $id, (array) $target['meta'], self::metaAllowlist(), '', 'comment_meta');
		clean_comment_cache($id);
		wp_update_comment_count((int) $row['comment_post_ID']);
		return $id;
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if (!isset($current['row'])) {
			return false;
		}
		$row = $current['row'];
		if ($op === 'trash') {
			return $row['comment_approved'] === 'trash';
		}
		if (isset($fields['status']) && $row['comment_approved'] !== (self::STATUS_VALUES[(string) $fields['status']] ?? null)) {
			return false;
		}
		if ($op === 'create') {
			return $row['comment_post_ID'] === (int) $fields['comment_post_ID']
				&& $row['comment_parent'] === (int) ($fields['comment_parent'] ?? 0)
				&& $row['user_id'] === (int) ($fields['user_id'] ?? 0)
				&& $row['comment_type'] === 'comment';
		}
		if ($before === null || !isset($before['row'])) {
			return false;
		}
		// Text may legitimately differ from the request (WordPress filters it); everything else must be untouched.
		foreach ($row as $column => $value) {
			if (in_array($column, self::TEXT_FIELDS, true) || $column === 'comment_approved') {
				continue;
			}
			if ($before['row'][$column] !== $value) {
				return false;
			}
		}
		foreach (self::TEXT_FIELDS as $field) {
			if (!array_key_exists($field, $fields) && $before['row'][$field] !== $row[$field]) {
				return false;
			}
		}
		return true;
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$beforeApproved = $before !== null && isset($before['row']) && (string) $before['row']['comment_approved'] === '1';
		$beforeStatus = $before !== null && isset($before['row']) ? (string) $before['row']['comment_approved'] : null;
		$postId = (int) ($before['row']['comment_post_ID'] ?? $after['row']['comment_post_ID'] ?? $fields['comment_post_ID'] ?? 0);

		if (in_array($op, ['undo', 'redo'], true)) {
			$afterStatus = $after !== null && isset($after['row']) ? (string) $after['row']['comment_approved'] : null;
			$absent = $afterStatus === null;
		} elseif ($op === 'delete') {
			$afterStatus = null;
			$absent = true;
		} elseif ($op === 'trash') {
			$afterStatus = 'trash';
			$absent = false;
		} else {
			$default = $op === 'create' ? '0' : $beforeStatus;
			$afterStatus = isset($fields['status']) ? (self::STATUS_VALUES[(string) $fields['status']] ?? $default) : $default;
			$absent = false;
		}
		$afterApproved = $afterStatus === '1';
		$postPublic = $postId > 0 && get_post_status($postId) === 'publish';
		$public = $postPublic && ($beforeApproved || $afterApproved);
		$external = [];
		if ($public) {
			$external[] = 'Search engines, feeds and caches may already have picked up the public comment.';
		}
		if ($op === 'create') {
			$external[] = 'Anti-spam services or webhooks hooked to new comments may still be called.';
		}
		if (($afterStatus === 'spam' || $beforeStatus === 'spam') && (class_exists('Akismet') || defined('AKISMET_VERSION'))) {
			$external[] = 'Akismet may report this spam or ham decision to its service.';
		}
		return [
			'destructive' => $absent || in_array($afterStatus, ['spam', 'trash'], true),
			'public' => $public,
			'sensitive' => false,
			'external_effects' => $external,
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		if ($id > 0 && get_comment($id) instanceof \WP_Comment) {
			return user_can($userId, 'edit_comment', $id);
		}
		$postId = (int) ($before['row']['comment_post_ID'] ?? $after['row']['comment_post_ID'] ?? 0);
		if ($postId > 0 && get_post($postId)) {
			return user_can($userId, 'moderate_comments') && user_can($userId, 'edit_post', $postId);
		}
		return user_can($userId, 'moderate_comments');
	}

	public function label(?array $state): string
	{
		if ($state === null || !isset($state['row'])) {
			return __('(comment)', 'llamly');
		}
		$author = trim((string) $state['row']['comment_author']);
		$text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $state['row']['comment_content'])) ?? '');
		$text = function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 60, '…') : substr($text, 0, 60);
		return trim(($author !== '' ? $author . ': ' : '') . $text) ?: __('(comment)', 'llamly');
	}

	public function tables(): array
	{
		global $wpdb;
		return [$wpdb->comments, $wpdb->commentmeta];
	}

	public function findMarked(string $operationId): array
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY comment_id ASC LIMIT 2",
			self::MARKER,
			$operationId
		));
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		delete_comment_meta($id, self::MARKER, $operationId);
	}

	public function flushCaches(int $id): void
	{
		global $wpdb;
		$children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_parent = %d LIMIT 500", $id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		clean_comment_cache(array_merge([$id], $children));
		wp_cache_delete($id, 'comment');
		wp_cache_delete($id, 'comment_meta');
		$postId = (int) $wpdb->get_var($wpdb->prepare("SELECT comment_post_ID FROM {$wpdb->comments} WHERE comment_ID = %d", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		if ($postId > 0) {
			wp_cache_delete($postId, 'counts');
			wp_cache_delete("comments-{$postId}", 'counts');
			wp_cache_delete($postId, 'posts');
		}
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$postId = (int) ($after['row']['comment_post_ID'] ?? $before['row']['comment_post_ID'] ?? 0);
		$result = ['comment_id' => $id, 'post_id' => $postId];
		if (isset($after['row'])) {
			$result['comment_status'] = self::statusSlug((string) $after['row']['comment_approved']);
		}
		if ($op === 'delete' || $op === 'trash') {
			$result['permanent'] = $op === 'delete';
			if ($op === 'delete') {
				$result['comment_status'] = 'deleted';
				$result['replies_moved_to_parent'] = count((array) ($before['children'] ?? []));
			}
		}
		if ($op === 'update') {
			$result['changed_fields'] = array_values(array_map(static fn(string $f): string => str_replace('comment_', '', $f), array_keys((array) ($intent['fields'] ?? []))));
		}
		return $result;
	}
}
