<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\History\Adapters\CommentObject;
use Llamly\Free\History\Engine;

/**
 * Comment tools. Reads never return IP or user agent. Every write goes
 * through Toolkit::run() to the CommentObject adapter; the executors only validate and check object capabilities.
 */
final class CommentTools
{
	private const CATEGORY = 'comments';
	private const TYPES = ['comment', 'review', 'pingback', 'trackback'];
	private const LIST_STATUSES = ['approve', 'hold', 'spam', 'trash', 'all'];

	public static function register(): void
	{
		$id = ['type' => 'integer', 'minimum' => 1];
		$text = static fn(int $max): array => ['type' => 'string', 'maxLength' => $max];
		$editable = [
			'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 65535, 'description' => 'Comment text (HTML is filtered like a normal comment).'],
			'author' => $text(245),
			'author_email' => $text(100),
			'author_url' => $text(200),
		];

		Toolkit::read('wp_list_comments', 'List comments with their status and revision_hash. Never returns IP addresses or user agents.', self::CATEGORY, 'moderate_comments', [
			'post_id' => $id,
			'type' => ['type' => 'string', 'enum' => self::TYPES],
			'status' => ['type' => 'string', 'enum' => self::LIST_STATUSES, 'default' => 'all', 'description' => '"all" means approved, held, spam and trash.'],
			'author_email' => $text(100),
		] + Toolkit::pageProps(), [], [self::class, 'listComments']);

		Toolkit::write('wp_create_comment', 'Create a comment on a post. It is created on hold unless status says otherwise; approving it on a public post waits for a human in wp-admin only when the optional approval mode is on. E-mail notifications are not sent.', self::CATEGORY, 'moderate_comments', [
			'post_id' => $id,
			'content' => $editable['content'],
			'author' => $editable['author'],
			'author_email' => $editable['author_email'],
			'author_url' => $editable['author_url'],
			'parent' => $id + ['description' => 'Parent comment ID (same post).'],
			'status' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam'], 'default' => 'hold'],
		], ['post_id', 'content'], false, [self::class, 'createComment']);

		Toolkit::write('wp_update_comment', 'Edit a comment and/or change its status (approve, hold, spam, trash). Reversible with wp_undo_change. Moving to spam or trash is destructive: with safe mode on, it needs confirm: true; ask the user before sending it.', self::CATEGORY, 'moderate_comments', [
			'comment_id' => $id,
		] + $editable + [
			'status' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'trash']],
		], ['comment_id'], true, [self::class, 'updateComment']);

		Toolkit::write('wp_delete_comment', 'Delete a comment. Without force it goes to the trash, except when it is already in trash/spam or the trash is disabled: then it is deleted permanently (the dry run says "delete"). Permanent deletes are reversible with wp_undo_change and keep the same comment ID. With safe mode on, needs confirm: true; ask the user before sending it. With the optional approval mode on, deleting an approved comment on a public post waits for a human in wp-admin.', self::CATEGORY, 'moderate_comments', [
			'comment_id' => $id,
			'force' => ['type' => 'boolean', 'default' => false],
		], ['comment_id'], true, [self::class, 'deleteComment']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new CommentObject()];
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function listComments(array $args, array $auth): array|\WP_Error
	{
		$userId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$postId = isset($args['post_id']) ? (int) $args['post_id'] : 0;
		if ($postId > 0 && !user_can($userId, 'edit_post', $postId)) {
			return Toolkit::error('forbidden', __('The connected user cannot moderate comments on this post.', 'llamly'), 403);
		}
		$status = (string) ($args['status'] ?? 'all');
		$query = [
			'status' => $status === 'all' ? ['hold', 'approve', 'spam', 'trash'] : $status,
			'type__not_in' => ['note'],
			'orderby' => 'comment_date_gmt',
			'order' => 'DESC',
			'number' => $perPage,
			'offset' => ($page - 1) * $perPage,
			'no_found_rows' => false,
			'update_comment_meta_cache' => true,
		];
		if (isset($args['type'])) {
			$query['type'] = (string) $args['type'];
		}
		if ($postId > 0) {
			$query['post_id'] = $postId;
		}
		if (!empty($args['author_email'])) {
			$query['author_email'] = sanitize_email((string) $args['author_email']);
		}
		$commentQuery = new \WP_Comment_Query();
		$comments = $commentQuery->query($query);
		$comments = is_array($comments) ? $comments : [];
		$adapter = new CommentObject();
		$hashes = $adapter->fingerprints(array_map(static fn($c): int => (int) $c->comment_ID, $comments));
		$items = [];
		foreach ($comments as $comment) {
			$cid = (int) $comment->comment_ID;
			if (!user_can($userId, 'edit_comment', $cid) || !isset($hashes[$cid])) {
				continue;
			}
			$item = [
				'comment_id' => $cid,
				'post_id' => (int) $comment->comment_post_ID,
				'author' => (string) $comment->comment_author,
				'author_email' => (string) $comment->comment_author_email,
				'author_url' => (string) $comment->comment_author_url,
				'content' => (string) $comment->comment_content,
				'date' => str_replace(' ', 'T', (string) $comment->comment_date_gmt) . 'Z',
				'status' => CommentObject::statusSlug((string) $comment->comment_approved),
				'parent' => (int) $comment->comment_parent,
				'type' => (string) $comment->comment_type === '' ? 'comment' : (string) $comment->comment_type,
			];
			foreach (['rating', 'verified'] as $key) {
				$value = get_comment_meta($cid, $key, true);
				if ($value !== '' && $value !== false) {
					$item[$key] = is_scalar($value) ? (string) $value : null;
				}
			}
			$item['revision_hash'] = $hashes[$cid];
			$items[] = $item;
		}
		return Toolkit::listResult($items, (int) $commentQuery->found_comments, $page, $perPage);
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function createComment(array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		$postId = (int) ($args['post_id'] ?? 0);
		$post = $postId > 0 ? get_post($postId) : null;
		if (!$post instanceof \WP_Post || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
			return Toolkit::error('object_not_found', __('The post was not found.', 'llamly'), 404);
		}
		if (!user_can($userId, 'read_post', $postId)) {
			return Toolkit::error('forbidden', __('The connected user cannot read this post, so it cannot comment on it.', 'llamly'), 403);
		}
		$content = (string) ($args['content'] ?? '');
		if (trim($content) === '' || strlen($content) > 65535) {
			return Toolkit::error('invalid_arguments', __('The comment needs text of at most 65535 bytes.', 'llamly'), 400);
		}
		$parent = (int) ($args['parent'] ?? 0);
		if ($parent > 0) {
			$parentComment = get_comment($parent);
			if (!$parentComment instanceof \WP_Comment || (int) $parentComment->comment_post_ID !== $postId) {
				return Toolkit::error('invalid_arguments', __('The parent comment does not exist on this post.', 'llamly'), 400);
			}
		}
		$user = get_userdata($userId);
		$email = array_key_exists('author_email', $args) ? sanitize_email((string) $args['author_email']) : ($user ? (string) $user->user_email : '');
		if ($email !== '' && !is_email($email)) {
			return Toolkit::error('invalid_arguments', __('The author e-mail is not valid.', 'llamly'), 400);
		}
		$fields = [
			'comment_post_ID' => $postId,
			'comment_parent' => $parent,
			'comment_content' => $content,
			'comment_author' => array_key_exists('author', $args) ? sanitize_text_field((string) $args['author']) : ($user ? (string) $user->display_name : ''),
			'comment_author_email' => $email,
			'comment_author_url' => array_key_exists('author_url', $args) ? esc_url_raw((string) $args['author_url']) : ($user ? esc_url_raw((string) $user->user_url) : ''),
			'user_id' => $userId,
			'status' => (string) ($args['status'] ?? 'hold'),
		];
		return Toolkit::run('comment', $tool, 0, $args, $auth, ['op' => 'create', 'fields' => $fields], 'comment_created');
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function updateComment(array $args, array $auth, string $tool): mixed
	{
		$commentId = (int) ($args['comment_id'] ?? 0);
		$gate = self::gate($commentId, $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$fields = [];
		if (array_key_exists('content', $args)) {
			$content = (string) $args['content'];
			if (trim($content) === '' || strlen($content) > 65535) {
				return Toolkit::error('invalid_arguments', __('The comment needs text of at most 65535 bytes.', 'llamly'), 400);
			}
			$fields['comment_content'] = $content;
		}
		if (array_key_exists('author', $args)) {
			$fields['comment_author'] = sanitize_text_field((string) $args['author']);
		}
		if (array_key_exists('author_email', $args)) {
			$email = sanitize_email((string) $args['author_email']);
			if ($email !== '' && !is_email($email)) {
				return Toolkit::error('invalid_arguments', __('The author e-mail is not valid.', 'llamly'), 400);
			}
			$fields['comment_author_email'] = $email;
		}
		if (array_key_exists('author_url', $args)) {
			$fields['comment_author_url'] = esc_url_raw((string) $args['author_url']);
		}
		if (array_key_exists('status', $args)) {
			$fields['status'] = (string) $args['status'];
		}
		if (!$fields) {
			return Toolkit::error('invalid_arguments', __('Provide at least one field or a status to change.', 'llamly'), 400);
		}
		return Toolkit::run('comment', $tool, $commentId, $args, $auth, ['op' => 'update', 'fields' => $fields], 'comment_updated');
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function deleteComment(array $args, array $auth, string $tool): mixed
	{
		$commentId = (int) ($args['comment_id'] ?? 0);
		$force = !empty($args['force']);
		$comment = $commentId > 0 ? get_comment($commentId) : null;
		if ($comment instanceof \WP_Comment) {
			$gate = self::gate($commentId, $auth);
			if (is_wp_error($gate)) {
				return $gate;
			}
			$permanent = CommentObject::isPermanent(CommentObject::statusSlug((string) $comment->comment_approved), $force);
			if ($permanent && CommentObject::hasUnsnapshottedMeta($commentId)) {
				return Toolkit::error('not_reversible', __('This comment has protected metadata that cannot be kept for undo, so it cannot be deleted permanently. Move it to the trash instead.', 'llamly'), 409);
			}
		} else {
			// Already gone: only a permanent delete can have removed it. The engine replays the original result for a
			// repeated idempotency key and answers object_not_found for anything else.
			$permanent = true;
		}
		$intent = ['op' => $permanent ? 'delete' : 'trash', 'force' => $force];
		return Toolkit::run('comment', $tool, $commentId, $args, $auth, $intent, 'comment_deleted');
	}

	/** Existence plus the per-object capability (edit_comment), before anything reaches the engine. */
	private static function gate(int $commentId, array $auth): \WP_Comment|\WP_Error
	{
		$comment = $commentId > 0 ? get_comment($commentId) : null;
		if (!$comment instanceof \WP_Comment) {
			return Toolkit::error('object_not_found', __('The comment was not found.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], 'edit_comment', $commentId)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit this comment.', 'llamly'), 403);
		}
		return $comment;
	}
}
