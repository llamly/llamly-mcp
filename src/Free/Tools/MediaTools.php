<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Core\Filesystem;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\History\Adapters\MediaObject;
use Llamly\Free\History\Engine;
use Llamly\Free\History\FileRetention;

/**
 * Media library tools. Uploads take strict base64 only (never a URL); the bytes
 * are validated here and again by the adapter before the sideload. Reads never return server paths.
 */
final class MediaTools
{
	private const CATEGORY = 'media';

	public static function register(): void
	{
		FileRetention::boot();
		$id = ['type' => 'integer', 'minimum' => 1];
		$text = static fn(int $max, string $description = ''): array => ['type' => 'string', 'maxLength' => $max] + ($description !== '' ? ['description' => $description] : []);
		$date = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'];

		Toolkit::read('wp_list_media', 'List media library items the connected user can edit, with URL, type, size, alt text and revision_hash. Never returns server paths.', self::CATEGORY, 'upload_files', [
			'search' => $text(200),
			'mime_type' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[a-z]+(/[a-z0-9.+-]+)?$', 'description' => 'Full type (image/png) or group (image).'],
			'post_parent' => ['type' => 'integer', 'minimum' => 1],
			'unattached' => ['type' => 'boolean'],
			'missing_alt' => ['type' => 'boolean'],
			'date_after' => $date,
			'date_before' => $date,
			'orderby' => ['type' => 'string', 'enum' => ['date', 'title', 'ID'], 'default' => 'date'],
			'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
		] + Toolkit::pageProps(), [], [self::class, 'listMedia']);

		Toolkit::write('wp_upload_media', sprintf('Upload one file to the media library from base64 data (no URLs). The real type is checked; SVG, HTML, scripts, PHP and double extensions are refused. Image sizes are generated. Undo removes only the files this upload created and refuses if the item is in use. Size limit: %s of file per request; the text fields (title, alt_text, caption, description) travel in the same request, so for long texts upload first and then call wp_update_media. With the optional approval mode on, an upload attached to a published post waits for a human in wp-admin. A file over the limit fails with invalid_arguments (the message states the maximum); a request body over the transport limit fails with request_too_large.', size_format(self::maxUploadBytes())), self::CATEGORY, 'upload_files', [
			'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[^/\\\\]{1,200}$'],
			'data' => ['type' => 'string', 'minLength' => 4, 'maxLength' => self::maxBase64(), 'description' => sprintf('Strict base64 of the file bytes (no data: prefix, no line breaks). Limit: %s of file (the smaller of the site upload limit and what one MCP request can carry).', size_format(self::maxUploadBytes()))],
			'title' => $text(500),
			'alt_text' => $text(1000),
			'caption' => $text(10000),
			'description' => $text(65535),
			'post_id' => $id + ['description' => 'Attach to this post (needs edit rights on it).'],
		], ['filename', 'data'], false, [self::class, 'uploadMedia']);

		Toolkit::write('wp_update_media', 'Change the title, caption, description, alt text or parent post of a media item. Files are not touched. With the optional approval mode on, changing an item used on the site or attached to a published post waits for a human in wp-admin.', self::CATEGORY, 'upload_files', [
			'attachment_id' => $id,
			'title' => $text(500),
			'alt_text' => $text(1000),
			'caption' => $text(10000),
			'description' => $text(65535),
			'post_parent' => $id + ['description' => 'Attach to this post (needs edit rights on it).'],
		], ['attachment_id'], true, [self::class, 'updateMedia']);

		Toolkit::write('wp_delete_media', 'Delete a media item and its files permanently. The files are kept in a protected retention area until the change expires, so wp_undo_change restores the same ID, files, featured images, site icon and logo. With safe mode on, needs confirm: true; ask the user before sending it. With the optional approval mode on, deleting an item used on the site waits for a human in wp-admin.', self::CATEGORY, 'upload_files', [
			'attachment_id' => $id,
		], ['attachment_id'], true, [self::class, 'deleteMedia']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new MediaObject()];
	}

	/** Sum of the maxLength of filename, title, alt_text, caption and description (200 + 500 + 1000 + 10000 + 65535). */
	public const TEXT_FIELDS_MAX = 77235;

	/**
	 * JSON envelope reserved next to the base64 data: every text field at its maximum (ASCII) plus about 2% of `\/`
	 * escapes, the llamly_run wrapper and the keys (2 KB), plus 2% of the body for the escapes inside the base64; rounded up to 1 KB.
	 */
	public static function envelopeReserve(): int
	{
		$body = \Llamly\Free\Mcp\Protocol::MAX_BODY_BYTES;
		return (int) (ceil((self::TEXT_FIELDS_MAX * 1.02 + 2048 + $body * 0.02) / 1024) * 1024);
	}

	/** Largest file one MCP call can carry: the site upload limit, capped by the transport body limit minus the envelope (base64 grows 4/3). */
	public static function maxUploadBytes(): int
	{
		return max(1, min((int) wp_max_upload_size(), intdiv((\Llamly\Free\Mcp\Protocol::MAX_BODY_BYTES - self::envelopeReserve()) * 3, 4)));
	}

	private static function maxBase64(): int
	{
		return (int) (4 * ceil(self::maxUploadBytes() / 3));
	}

	// ------------------------------------------------------------------ wp_list_media

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function listMedia(array $args, array $auth): array|\WP_Error
	{
		$definition = ToolRegistry::get('wp_list_media');
		$authorized = $definition ? Authorization::requireTool($auth, $definition) : Toolkit::error('tool_not_found', 'The requested tool is not registered.', 404);
		if (is_wp_error($authorized)) {
			return $authorized;
		}
		$userId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$query = [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $perPage,
			'paged' => $page,
			'orderby' => (string) ($args['orderby'] ?? 'date'),
			'order' => (string) ($args['order'] ?? 'DESC'),
			'ignore_sticky_posts' => true,
		];
		if (!user_can($userId, 'edit_others_posts')) {
			$query['author'] = $userId;
		}
		if (!empty($args['search'])) {
			$query['s'] = (string) $args['search'];
		}
		if (!empty($args['mime_type'])) {
			$query['post_mime_type'] = (string) $args['mime_type'];
		}
		if (!empty($args['post_parent'])) {
			$parent = (int) $args['post_parent'];
			if (!user_can($userId, 'edit_post', $parent)) {
				return Toolkit::error('forbidden', __('The connected user cannot edit that post.', 'llamly'), 403);
			}
			$query['post_parent'] = $parent;
		} elseif (!empty($args['unattached'])) {
			$query['post_parent'] = 0;
		}
		if (!empty($args['missing_alt'])) {
			$query['meta_query'] = ['relation' => 'OR', ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'], ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=']]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
		}
		$dates = [];
		if (!empty($args['date_after'])) {
			$dates['after'] = (string) $args['date_after'];
		}
		if (!empty($args['date_before'])) {
			$dates['before'] = (string) $args['date_before'];
		}
		if ($dates) {
			$query['date_query'] = [$dates + ['inclusive' => true]];
		}
		$wpQuery = new \WP_Query($query);
		$adapter = Engine::adapter('media');
		$items = [];
		foreach ($wpQuery->posts as $post) {
			$aid = (int) $post->ID;
			if (!user_can($userId, 'edit_post', $aid)) {
				continue;
			}
			$meta = wp_get_attachment_metadata($aid);
			$meta = is_array($meta) ? $meta : [];
			$file = (string) get_post_meta($aid, '_wp_attached_file', true);
			$path = $file !== '' ? FileRetention::absolute($file) : null;
			$size = isset($meta['filesize']) ? (int) $meta['filesize'] : ($path !== null && is_file($path) ? (int) filesize($path) : null);
			$items[] = [
				'attachment_id' => $aid,
				'title' => (string) $post->post_title,
				'filename' => $file !== '' ? wp_basename($file) : '',
				'url' => (string) wp_get_attachment_url($aid),
				'mime_type' => (string) $post->post_mime_type,
				'filesize' => $size,
				'width' => isset($meta['width']) ? (int) $meta['width'] : null,
				'height' => isset($meta['height']) ? (int) $meta['height'] : null,
				'alt_text' => (string) get_post_meta($aid, '_wp_attachment_image_alt', true),
				'caption' => (string) $post->post_excerpt,
				'description' => (string) $post->post_content,
				'post_parent' => (int) $post->post_parent,
				'date' => str_replace(' ', 'T', (string) $post->post_date_gmt) . 'Z',
				'revision_hash' => $adapter ? $adapter->fingerprint($adapter->capture($aid)) : null,
			];
		}
		return Toolkit::listResult($items, (int) $wpQuery->found_posts, $page, $perPage);
	}

	// ------------------------------------------------------------------ wp_upload_media

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function uploadMedia(array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'upload_files')) {
			return Toolkit::error('forbidden', __('The connected user cannot upload files.', 'llamly'), 403);
		}
		$parent = (int) ($args['post_id'] ?? 0);
		if ($parent > 0) {
			$gate = self::parentGate($parent, $userId);
			if (is_wp_error($gate)) {
				return $gate;
			}
		}
		$data = $args['data'] ?? null;
		if (is_string($data) && strlen($data) > self::maxBase64()) {
			/* translators: %s: maximum file size per MCP upload. */
			return Toolkit::error('file_too_large', sprintf(__('The file is larger than one MCP upload allows (%s).', 'llamly'), size_format(self::maxUploadBytes())), 413);
		}
		if (!is_string($data) || strlen($data) % 4 !== 0 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $data)) {
			return Toolkit::error('invalid_arguments', __('data must be strict base64 of the file bytes (no URL, data: prefix or line breaks) within the upload limit.', 'llamly'), 400);
		}
		$bytes = base64_decode($data, true);
		unset($data, $args['data']);
		if (!is_string($bytes) || $bytes === '') {
			return Toolkit::error('invalid_arguments', __('data is not valid base64.', 'llamly'), 400);
		}
		if (strlen($bytes) > self::maxUploadBytes()) {
			$size = strlen($bytes);
			unset($bytes);
			/* translators: 1: file size, 2: maximum file size per MCP upload. */
			return Toolkit::error('file_too_large', sprintf(__('The file is %1$s; one MCP upload is limited to %2$s.', 'llamly'), size_format($size), size_format(self::maxUploadBytes())), 413);
		}
		$staged = trailingslashit(get_temp_dir()) . 'llamly-media-' . bin2hex(random_bytes(16)) . '.upload';
		if (@file_put_contents($staged, $bytes, LOCK_EX) !== strlen($bytes)) {
			wp_delete_file($staged);
			return Toolkit::error('upload_failed', __('The upload could not be staged on the server.', 'llamly'), 503);
		}
		unset($bytes);
		Filesystem::direct()->chmod($staged, 0600);
		try {
			$check = MediaObject::validateUpload($staged, (string) ($args['filename'] ?? ''), $userId);
			if (is_wp_error($check)) {
				return $check;
			}
			return self::uploadStaged($staged, $check, $args, $auth, $tool);
		} finally {
			wp_delete_file($staged);
		}
	}

	/** @var array<string, mixed> Intent of the last staged upload of this request (MediaParts keeps it for a replay). */
	private static array $lastIntent = [];

	/** @return array<string, mixed> */
	public static function lastIntent(): array
	{
		return self::$lastIntent;
	}

	/**
	 * The journaled media create of a validated file on disk (wp_upload_media and wp_upload_media_finish). The caller
	 * owns $staged and removes it.
	 *
	 * @param array{name:string,ext:string,type:string,size:int,sha256:string} $check MediaObject::validateUpload() of $staged
	 * @param array<string, mixed> $args @param array<string, mixed> $auth
	 */
	public static function uploadStaged(string $staged, array $check, array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'upload_files')) {
			return Toolkit::error('forbidden', __('The connected user cannot upload files.', 'llamly'), 403);
		}
		$parent = (int) ($args['post_id'] ?? 0);
		if ($parent > 0) {
			$gate = self::parentGate($parent, $userId);
			if (is_wp_error($gate)) {
				return $gate;
			}
		}
		$fields = [
			'post_title' => array_key_exists('title', $args) ? sanitize_text_field((string) $args['title']) : sanitize_text_field(preg_replace('/\.[^.]+$/', '', $check['name'])),
			'post_excerpt' => array_key_exists('caption', $args) ? wp_kses_post((string) $args['caption']) : '',
			'post_content' => array_key_exists('description', $args) ? wp_kses_post((string) $args['description']) : '',
			'post_parent' => $parent,
		];
		$intent = [
			'op' => 'create',
			'filename' => $check['name'],
			'mime' => $check['type'],
			'size' => $check['size'],
			'sha256' => $check['sha256'],
			'fields' => $fields,
			'alt' => array_key_exists('alt_text', $args) ? sanitize_text_field((string) $args['alt_text']) : '',
		];
		if (empty($args['dry_run'])) {
			FileRetention::sweep();
		}
		self::$lastIntent = $intent;
		// The bytes reach the adapter through the request context, never through the journaled intent (only their sha256).
		return Toolkit::run('media', $tool, 0, $args, $auth + ['media_staged_file' => $staged], $intent, 'media_uploaded');
	}

	// ------------------------------------------------------------------ wp_update_media

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function updateMedia(array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		$id = (int) ($args['attachment_id'] ?? 0);
		$gate = self::attachmentGate($id, $userId, 'edit_post');
		if (is_wp_error($gate)) {
			return $gate;
		}
		$fields = [];
		if (array_key_exists('title', $args)) {
			$fields['post_title'] = sanitize_text_field((string) $args['title']);
		}
		if (array_key_exists('caption', $args)) {
			$fields['post_excerpt'] = wp_kses_post((string) $args['caption']);
		}
		if (array_key_exists('description', $args)) {
			$fields['post_content'] = wp_kses_post((string) $args['description']);
		}
		if (array_key_exists('post_parent', $args)) {
			$parent = (int) $args['post_parent'];
			if ($parent === $id) {
				return Toolkit::error('invalid_arguments', __('A media item cannot be its own parent.', 'llamly'), 400);
			}
			$parentGate = self::parentGate($parent, $userId);
			if (is_wp_error($parentGate)) {
				return $parentGate;
			}
			$fields['post_parent'] = $parent;
		}
		$intent = ['op' => 'update', 'fields' => $fields];
		if (array_key_exists('alt_text', $args)) {
			$intent['alt'] = sanitize_text_field((string) $args['alt_text']);
		}
		if (!$fields && !array_key_exists('alt', $intent)) {
			return Toolkit::error('invalid_arguments', __('Provide at least one field to change.', 'llamly'), 400);
		}
		return Toolkit::run('media', $tool, $id, $args, $auth, $intent, 'media_updated');
	}

	// ------------------------------------------------------------------ wp_delete_media

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function deleteMedia(array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		$id = (int) ($args['attachment_id'] ?? 0);
		$post = $id > 0 ? get_post($id) : null;
		if ($post instanceof \WP_Post) {
			$gate = self::attachmentGate($id, $userId, 'delete_post');
			if (is_wp_error($gate)) {
				return $gate;
			}
		}
		// Already gone: the engine replays the stored result for the same idempotency key, object_not_found otherwise.
		if (empty($args['dry_run'])) {
			FileRetention::sweep();
		}
		return Toolkit::run('media', $tool, $id, $args, $auth, ['op' => 'delete'], 'media_deleted');
	}

	// ------------------------------------------------------------------ gates

	private static function attachmentGate(int $id, int $userId, string $cap): bool|\WP_Error
	{
		$post = $id > 0 ? get_post($id) : null;
		if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
			return Toolkit::error('object_not_found', __('The media item was not found.', 'llamly'), 404);
		}
		if (!user_can($userId, 'edit_post', $id) || ($cap !== 'edit_post' && !user_can($userId, $cap, $id))) {
			return Toolkit::error('forbidden', __('The connected user cannot change this media item.', 'llamly'), 403);
		}
		return true;
	}

	private static function parentGate(int $parent, int $userId): bool|\WP_Error
	{
		$post = get_post($parent);
		if (!$post instanceof \WP_Post || in_array($post->post_type, ['attachment', 'revision', 'nav_menu_item'], true) || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
			return Toolkit::error('object_not_found', __('The post to attach to was not found.', 'llamly'), 404);
		}
		if (!user_can($userId, 'edit_post', $parent)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit the post to attach to.', 'llamly'), 403);
		}
		return true;
	}
}
