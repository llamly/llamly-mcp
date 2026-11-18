<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\History\ContentGuard;
use Llamly\Free\History\PostAdapter;

/**
 * Post revisions: list, diff and restore. Restoring is a journaled write on the parent post
 * (adapter "post"), so it is undoable and guarded by the parent's revision_hash.
 */
final class RevisionTools
{
	public const DIFF_FIELD_MAX = 61440;
	private const FIELDS = ['post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt'];

	public static function register(): void
	{
		$id = ['type' => 'integer', 'minimum' => 1];
		Toolkit::read('wp_list_revisions', 'List the revisions of a post, newest first, with the fields each one changed. post_hash is the expected_hash for wp_restore_revision.', 'revisions', 'edit_post', [
			'post_id' => $id,
		] + Toolkit::pageProps(), ['post_id'], [self::class, 'listRevisions']);

		Toolkit::read('wp_get_revision_diff', 'Line diff between a revision and the current post (or another revision of the same post): what restoring it would change.', 'revisions', 'edit_posts', [
			'revision_id' => $id,
			'compare_to' => $id + ['description' => 'Another revision of the same post; default is the current post.'],
		], ['revision_id'], [self::class, 'revisionDiff']);

		Toolkit::write('wp_restore_revision', 'Restore title, content and excerpt of a post from one of its revisions. expected_hash is the parent post revision_hash. Public posts wait for a human in wp-admin only when the optional approval mode is on in Settings.', 'revisions', 'edit_post', [
			'revision_id' => $id,
		], ['revision_id'], true, [self::class, 'restoreRevision']);
	}

	/** @return list<\Llamly\Free\History\Adapter> The post adapter is provided by PostTools. */
	public static function adapters(): array
	{
		return [];
	}

	public static function listRevisions(array $args, array $auth): array|\WP_Error
	{
		global $wpdb;
		$userId = (int) $auth['user_id'];
		$postId = (int) $args['post_id'];
		$parent = self::editableParent($postId, $userId);
		if (is_wp_error($parent)) {
			return $parent;
		}
		[$page, $perPage] = Toolkit::page($args);
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $postId));
		// One extra row: the next older revision is what each item is compared with.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_author, post_date_gmt, post_name, post_title, post_content, post_excerpt FROM {$wpdb->posts}
			 WHERE post_parent = %d AND post_type = 'revision' ORDER BY post_date_gmt DESC, ID DESC LIMIT %d OFFSET %d",
			$postId,
			$perPage + 1,
			($page - 1) * $perPage
		), ARRAY_A) ?: [];
		$items = [];
		foreach (array_slice($rows, 0, $perPage) as $index => $row) {
			$older = $rows[$index + 1] ?? null;
			$changed = [];
			foreach (self::FIELDS as $column => $name) {
				if ($older === null ? $row[$column] !== '' : $row[$column] !== $older[$column]) {
					$changed[] = $name;
				}
			}
			$authorId = (int) $row['post_author'];
			$items[] = [
				'id' => (int) $row['ID'],
				'date_gmt' => (string) $row['post_date_gmt'],
				'author' => ['id' => $authorId, 'name' => (string) get_the_author_meta('display_name', $authorId)],
				'is_autosave' => strpos((string) $row['post_name'], $postId . '-autosave') === 0,
				'changed_fields' => $changed,
			];
		}
		return [
			'post_id' => $postId,
			'post_type' => $parent['fields']['post_type'],
			'post_hash' => PostAdapter::fingerprintOf($parent),
			'revisions' => $items,
		] + Toolkit::listResult([], $total, $page, $perPage);
	}

	public static function revisionDiff(array $args, array $auth): array|\WP_Error
	{
		$userId = (int) $auth['user_id'];
		$revision = self::revision((int) $args['revision_id']);
		if (is_wp_error($revision)) {
			return $revision;
		}
		$postId = (int) $revision->post_parent;
		$parent = self::editableParent($postId, $userId);
		if (is_wp_error($parent)) {
			return $parent;
		}
		$compareTo = isset($args['compare_to']) ? (int) $args['compare_to'] : 0;
		if ($compareTo > 0) {
			$other = self::revision($compareTo);
			if (is_wp_error($other) || (int) $other->post_parent !== $postId) {
				return Toolkit::error('invalid_arguments', __('compare_to must be another revision of the same post.', 'llamly'), 400);
			}
			$from = ['post_title' => $other->post_title, 'post_content' => $other->post_content, 'post_excerpt' => $other->post_excerpt];
		} else {
			$from = $parent['fields'];
		}
		$fields = [];
		$truncated = [];
		$identical = true;
		foreach (self::FIELDS as $column => $name) {
			$old = (string) $from[$column];
			$new = (string) $revision->{$column};
			if ($old === $new) {
				continue;
			}
			$identical = false;
			$diff = self::unifiedDiff($old, $new, $compareTo > 0 ? 'revision ' . $compareTo : 'current', 'revision ' . $revision->ID);
			if (strlen($diff) > self::DIFF_FIELD_MAX) {
				$diff = mb_strcut($diff, 0, self::DIFF_FIELD_MAX, 'UTF-8');
				$truncated[] = $name;
			}
			$fields[$name] = $diff;
		}
		return [
			'revision_id' => (int) $revision->ID,
			'post_id' => $postId,
			'compare_to' => $compareTo > 0 ? $compareTo : 'current',
			'identical' => $identical,
			'fields' => $fields ?: (object) [],
			'truncated' => $truncated,
		];
	}

	public static function restoreRevision(array $args, array $auth): mixed
	{
		$replay = PostTools::replay($args, $auth, 'wp_restore_revision');
		if ($replay !== null) {
			return $replay;
		}
		$userId = (int) $auth['user_id'];
		$revision = self::revision((int) ($args['revision_id'] ?? 0));
		if (is_wp_error($revision)) {
			return $revision;
		}
		$postId = (int) $revision->post_parent;
		$parent = self::editableParent($postId, $userId);
		if (is_wp_error($parent)) {
			return $parent;
		}
		if ($parent['fields']['post_status'] === 'trash') {
			return Toolkit::error('parent_trashed', __('Restore the post from the trash before restoring one of its revisions.', 'llamly'), 409);
		}
		if (wp_is_post_autosave($revision) && (int) $revision->post_author !== $userId) {
			return Toolkit::error('forbidden', __('This autosave belongs to another user.', 'llamly'), 403);
		}
		$fields = [];
		foreach (array_keys(self::FIELDS) as $column) {
			$fields[$column] = (string) $revision->{$column};
		}
		$intent = ['op' => 'restore_revision', 'revision_id' => (int) $revision->ID, 'fields' => $fields];
		// Revisioned meta (WP 6.4+) is copied by WordPress on restore; the intent states the expected result.
		if (function_exists('wp_post_revision_meta_keys')) {
			$meta = [];
			foreach (wp_post_revision_meta_keys((string) $parent['fields']['post_type']) as $key) {
				if (!ContentGuard::metaInScope((string) $key)) {
					continue;
				}
				$values = get_post_meta((int) $revision->ID, (string) $key, false);
				$meta[(string) $key] = $values ? (string) $values[0] : null;
			}
			if ($meta) {
				ksort($meta, SORT_STRING);
				$intent['meta'] = $meta;
			}
		}
		$guard = ContentGuard::checkIntent($postId, $intent);
		if ($guard) {
			return Toolkit::error($guard->errorCode, $guard->reason(), $guard->httpStatus);
		}
		return PostTools::runWrite('wp_restore_revision', $postId, $args, $auth, $intent, 'post_revision_restored');
	}

	private static function revision(int $revisionId): \WP_Post|\WP_Error
	{
		$revision = $revisionId > 0 ? wp_get_post_revision($revisionId) : null;
		if (!$revision instanceof \WP_Post) {
			return Toolkit::error('object_not_found', __('The revision was not found.', 'llamly'), 404);
		}
		return $revision;
	}

	/** @return array<string, mixed>|\WP_Error Parent S_POST state. */
	private static function editableParent(int $postId, int $userId): array|\WP_Error
	{
		$state = PostAdapter::capture($postId);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('The post was not found or its type is not handled by the post tools.', 'llamly'), 404);
		}
		if (!user_can($userId, 'edit_post', $postId)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit this post.', 'llamly'), 403);
		}
		if (!post_type_supports((string) $state['fields']['post_type'], 'revisions')) {
			return Toolkit::error('revisions_unsupported', __('This post type does not keep revisions.', 'llamly'), 400);
		}
		return $state;
	}

	/** Line diff with "-"/"+" lines, unchanged runs collapsed to at most 3 context lines. */
	private static function unifiedDiff(string $old, string $new, string $fromLabel, string $toLabel): string
	{
		if (!class_exists('Text_Diff', false)) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}
		$oldLines = explode("\n", normalize_whitespace($old));
		$newLines = explode("\n", normalize_whitespace($new));
		$diff = new \Text_Diff('auto', [$oldLines, $newLines]);
		$out = ['--- ' . $fromLabel, '+++ ' . $toLabel];
		$edits = $diff->getDiff();
		$count = count($edits);
		foreach ($edits as $index => $edit) {
			if ($edit instanceof \Text_Diff_Op_copy) {
				$lines = (array) $edit->orig;
				$head = $index > 0 ? array_slice($lines, 0, 3) : [];
				$tail = $index < $count - 1 ? array_slice($lines, -3) : [];
				if (count($lines) > 6 || $index === 0 || $index === $count - 1) {
					foreach ($head as $line) {
						$out[] = ' ' . $line;
					}
					$out[] = '@@';
					foreach ($tail as $line) {
						$out[] = ' ' . $line;
					}
				} else {
					foreach ($lines as $line) {
						$out[] = ' ' . $line;
					}
				}
				continue;
			}
			foreach ((array) ($edit->orig ?? []) as $line) {
				$out[] = '-' . $line;
			}
			foreach ((array) ($edit->final ?? []) as $line) {
				$out[] = '+' . $line;
			}
		}
		return implode("\n", $out);
	}
}
