<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named locks and the option rows of open uploads are read live: a cached copy would let two parts land as the same index.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- parts are streamed to and from a private folder of the server's temporary directory (outside the site), as wp_upload_media stages its file; WP_Filesystem may be FTP-based and cannot write there.

namespace Llamly\Free\Tools;

use Llamly\Free\Core\Filesystem;
use Llamly\Free\History\Adapters\MediaObject;
use Llamly\Free\History\Engine;

/**
 * Upload of a file the agent has on its own disk, without base64 in the MCP call (LLP-433), or in base64 parts for a
 * file larger than one MCP request (LLP-430). Never from a URL: the bytes come from the agent. Flow:
 * wp_upload_media_start (name, type, total size, sha256) answers with a one-time upload link; the agent PUTs the raw
 * file to it (curl --data-binary, outside the MCP call) or sends base64 parts with wp_upload_media_part; then
 * wp_upload_media_finish checks the size and sha256 and runs the same checks and the same journaled media write as
 * wp_upload_media (one change, one undo).
 *
 * The upload link (llamly/v1/upload/<id>?token=...) carries no password, key or cookie: a 256-bit random token, stored
 * only as its sha256, works once, for LINK_TTL, for that upload only, while its user can still upload_files, and only
 * for a body of exactly the declared size and sha256. The bytes go to the same private folder as the parts.
 *
 * Start and part change nothing on the site and never enter the history: they keep the bytes in a private folder of
 * the server's temporary directory (outside the site; refused when the temporary directory is inside it) and the upload
 * record in an option (llamly_free_upl_<id>). The total is capped by wp_max_upload_size(), and an unfinished upload
 * expires after TTL; one whose link expired with no bytes received is abandoned, and one whose link received the file
 * must be finished within LINK_TTL. A scheduled event, the daily maintenance and every new start remove expired and
 * abandoned ones.
 *
 * Two limits per user (LLP-436), one per risk. Started uploads still waiting for their bytes cost no disk and are
 * abandoned when their link expires: up to MAX_PENDING of them, so an agent can start one upload per image of a page
 * before running the curl commands. Uploads holding bytes occupy the private folder until their finish: their declared
 * sizes add up to at most STAGED_FILES times wp_max_upload_size() (stagedLimit()), checked at the start and again when
 * the first bytes arrive (by the link or by a part), so many small files pass and the disk cap stays where it was.
 */
final class MediaParts
{
	public const PART_BYTES = 524288;
	/** Started uploads with no bytes yet, per user. */
	public const MAX_PENDING = 20;
	/** Uploads holding bytes, per user, add up to at most this many files of the site upload limit. */
	public const STAGED_FILES = 3;
	public const TTL = 6 * HOUR_IN_SECONDS;
	/** An upload whose finish waits for a human approval is kept as long as the approval can wait (7 days). */
	public const TTL_APPROVAL = 8 * DAY_IN_SECONDS;
	public const OPTION_PREFIX = 'llamly_free_upl_';
	public const HOOK_EXPIRE = 'llamly_free_upload_parts_expire';
	/** How long an upload link works, and how long an upload whose link received the file waits for its finish. */
	public const LINK_TTL = 15 * MINUTE_IN_SECONDS;
	public const ROUTE = '/upload/(?P<id>[a-f0-9]{32})';
	private const DIR = 'llamly-upload-parts';

	/** Whether the upload tools are offered: always since 1.1 (LLP-433), with or without an add-on module. */
	public static function available(): bool
	{
		return true;
	}

	public static function registerHooks(): void
	{
		add_action(self::HOOK_EXPIRE, [self::class, 'expire']);
		add_action('rest_api_init', [self::class, 'registerRoute']);
	}

	/** Catalog domain: the three upload tools, registered after every 1.0 tool. */
	public static function register(): void
	{
		$text = static fn(int $max): array => ['type' => 'string', 'maxLength' => $max];
		$id = ['type' => 'integer', 'minimum' => 1];
		$sha = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'sha256 of the whole file, lowercase hex (sha256sum).'];
		$uploadId = ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'upload_id returned by wp_upload_media_start.'];
		Toolkit::write('wp_upload_media_start', sprintf('Upload a file from your own disk to the media library without base64 (a photo, an image of the page you are building, a video). Send its name, MIME type, size in bytes and sha256. The answer has upload_url and upload_command: run that curl command with the path of your file, then call wp_upload_media_finish with the upload_id. The PUT to upload_url is the second step of this Llamly MCP upload, not another way into the site: the Llamly MCP server itself receives the raw bytes there, outside the JSON of the call (the link needs no password, works once and expires in %1$d minutes). Use this whenever you can run curl. Without a terminal, send the bytes in base64 instead: wp_upload_media for a file that fits one call, or wp_upload_media_part (up to %2$s per part, in order from index 0) for a larger one. No URLs of other sites. Nothing changes on the site until the finish. Total limit: %3$s (the site upload limit). Per user: up to %4$d started uploads still waiting for their file (each one frees its place when its link expires), and unfinished uploads that hold their file up to %5$s in total; an unfinished upload expires after %6$d hours.', (int) (self::LINK_TTL / MINUTE_IN_SECONDS), size_format(self::PART_BYTES), size_format(wp_max_upload_size()), self::MAX_PENDING, size_format(self::stagedLimit()), (int) (self::TTL / HOUR_IN_SECONDS)), 'media', 'upload_files', [
			'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[^/\\\\]{1,200}$'],
			'mime_type' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[a-z]+/[a-z0-9.+-]+$', 'description' => 'MIME type of the file (image/jpeg, video/mp4). It must match the extension and, at the finish, the real content.'],
			'size' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Total size of the file in bytes.'],
			'sha256' => $sha,
		], ['filename', 'mime_type', 'size', 'sha256'], false, [self::class, 'start']);

		Toolkit::write('wp_upload_media_part', sprintf('Send one part of an upload started with wp_upload_media_start, when you cannot use its upload_url: strict base64 of up to %s of the file, with index 0, 1, 2... in order. Sending the same last part again is harmless. A part out of order fails with part_out_of_order and the index to send next.', size_format(self::PART_BYTES)), 'media', 'upload_files', [
			'upload_id' => $uploadId,
			'index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
			'data' => ['type' => 'string', 'minLength' => 4, 'maxLength' => self::partBase64(), 'description' => 'Strict base64 of this part (no data: prefix, no line breaks).'],
		], ['upload_id', 'index', 'data'], false, [self::class, 'part']);

		Toolkit::write('wp_upload_media_finish', sprintf('Finish an upload started with wp_upload_media_start, after its upload_url received the file (or after the last part): the size and sha256 are checked, and the file goes through the same checks as wp_upload_media (real type; SVG, HTML, scripts, PHP and double extensions are refused). It enters the media library as one upload with one undo and returns attachment_id and url. Finish within %d minutes of the upload. Optional title, alt_text, caption, description and post_id as in wp_upload_media. A wrong sha256 or file type discards the upload.', (int) (self::LINK_TTL / MINUTE_IN_SECONDS)), 'media', 'upload_files', [
			'upload_id' => $uploadId,
			'title' => $text(500),
			'alt_text' => $text(1000),
			'caption' => $text(10000),
			'description' => $text(65535),
			'post_id' => $id + ['description' => 'Attach to this post (needs edit rights on it).'],
		], ['upload_id'], false, [self::class, 'finish']);
	}

	/** @return list<\Llamly\Free\History\Adapter> The media writes go through MediaTools' adapter. */
	public static function adapters(): array
	{
		return [];
	}

	public static function registerRoute(): void
	{
		register_rest_route('llamly/v1', self::ROUTE, [
			'methods' => 'PUT',
			'callback' => [self::class, 'receive'],
			// Only the link authorizes the PUT: no cookie, nonce or Authorization header is read or needed.
			'permission_callback' => [self::class, 'linkPermission'],
			'args' => [],
		]);
	}

	/** Base64 characters of the largest part. */
	public static function partBase64(): int
	{
		return (int) (4 * ceil(self::PART_BYTES / 3));
	}

	// ------------------------------------------------------------------ wp_upload_media_start

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function start(array $args, array $auth, string $tool): mixed
	{
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'upload_files')) {
			return Toolkit::error('forbidden', __('The connected user cannot upload files.', 'llamly'), 403);
		}
		$key = Engine::idempotencyKey($args['idempotency_key'] ?? null);
		if (is_wp_error($key)) {
			return $key;
		}
		self::sweep();
		// One start at a time per user: the replay lookup, the open-upload count and the new record go together.
		$userLock = 'u' . $userId;
		if (!self::lock($userLock)) {
			return Toolkit::error('lock_timeout', __('Another upload of this user is starting. Try again.', 'llamly'), 409);
		}
		try {
			return self::startLocked($args, $auth, $userId, (string) $key);
		} finally {
			self::unlock($userLock);
		}
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	private static function startLocked(array $args, array $auth, int $userId, string $key): mixed
	{
		$keyHash = self::keyHash($auth, $key);
		foreach (self::records() as $id => $record) {
			if (($record['key'] ?? '') === $keyHash && ($record['status'] ?? '') === 'open') {
				// Only the hash of the first link was kept: a replay before any byte arrived gets a new link.
				$link = !self::holdsBytes($record) ? self::issueLink($id, $record) : [];
				return self::describe($id, $record) + $link + ['replayed' => true];
			}
		}
		$filename = (string) ($args['filename'] ?? '');
		$name = sanitize_file_name($filename);
		$type = wp_check_filetype($name, MediaObject::allowedMimes($userId));
		$declared = strtolower((string) ($args['mime_type'] ?? ''));
		if ($name === '' || MediaObject::refusedName($filename) !== null || empty($type['ext']) || empty($type['type']) || MediaObject::isDeniedMime((string) $type['type'])) {
			return Toolkit::error('invalid_file_type', __('This file type is not allowed for uploads.', 'llamly'), 400);
		}
		if ($declared !== strtolower((string) $type['type'])) {
			/* translators: 1: MIME type sent by the agent, 2: MIME type of the file extension. */
			return Toolkit::error('type_mismatch', sprintf(__('mime_type %1$s does not match the file extension (%2$s).', 'llamly'), $declared, (string) $type['type']), 400);
		}
		$size = (int) ($args['size'] ?? 0);
		if ($size < 1 || !preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($args['sha256'] ?? '')))) {
			return Toolkit::error('invalid_arguments', __('size must be the file size in bytes (1 or more) and sha256 its lowercase hex sha256.', 'llamly'), 400);
		}
		if ($size > (int) wp_max_upload_size()) {
			/* translators: 1: file size, 2: site upload limit. */
			return Toolkit::error('file_too_large', sprintf(__('The file is %1$s; the upload limit of this site is %2$s.', 'llamly'), size_format($size), size_format(wp_max_upload_size())), 413);
		}
		$open = self::openOf($userId);
		// Abandoned ones still listed (the sweep is bounded) no longer count: their link expired.
		$pending = array_filter($open, static fn(array $r): bool => !self::holdsBytes($r) && !self::abandoned($r));
		if (count($pending) >= self::MAX_PENDING) {
			$at = gmdate('Y-m-d\TH:i:s\Z', (int) min(array_map(static fn(array $r): int => (int) $r['link_expires'], $pending)));
			/* translators: 1: maximum number of started uploads without their file, 2: time (UTC) when the first link expires. */
			return new \WP_Error('too_many_uploads', sprintf(__('Limit of started uploads reached: this user already has %1$d uploads waiting for their file. Run their upload_command (or send their parts) and finish them, or wait until their links expire (the first one at %2$s).', 'llamly'), self::MAX_PENDING, $at), ['status' => 429, 'limit' => 'pending', 'retry_at' => $at]);
		}
		$staged = self::stagedError(array_filter($open, [self::class, 'holdsBytes']), $size, 'start');
		if ($staged !== null) {
			return $staged;
		}
		$base = self::baseDir(true);
		if (is_wp_error($base)) {
			return $base;
		}
		if (!empty($args['dry_run'])) {
			return ['dry_run' => true, 'filename' => $name, 'mime_type' => $declared, 'size' => $size, 'parts' => (int) ceil($size / self::PART_BYTES), 'part_bytes' => self::PART_BYTES];
		}
		$id = bin2hex(random_bytes(16));
		$fs = Filesystem::direct();
		if (!$fs->mkdir($base . '/' . $id, 0700)) {
			return Toolkit::error('upload_failed', __('The upload could not be staged on the server.', 'llamly'), 503);
		}
		$record = [
			'user' => $userId,
			'credential' => (string) ($auth['credential_key'] ?? ''),
			'key' => $keyHash,
			'filename' => $name,
			'mime' => $declared,
			'size' => $size,
			'sha256' => strtolower((string) $args['sha256']),
			'parts' => 0,
			'received' => 0,
			'last_part' => '',
			'status' => 'open',
			'created' => time(),
			'expires' => time() + self::TTL,
			'link' => '',
			'link_expires' => 0,
		];
		add_option(self::OPTION_PREFIX . $id, $record, '', false);
		wp_schedule_single_event((int) $record['expires'] + MINUTE_IN_SECONDS, self::HOOK_EXPIRE, [$id]);
		$link = self::issueLink($id, $record);
		return self::describe($id, $record) + $link;
	}

	/**
	 * A new one-time upload link for an open upload with no bytes yet: the token is returned once and kept only as its
	 * sha256. The curl command carries the link and nothing else (no password, key or cookie).
	 *
	 * @param array<string, mixed> $record updated in place and saved
	 * @return array{upload_url:string,upload_url_expires_at:string,upload_command:string,upload_next:string}
	 */
	private static function issueLink(string $id, array &$record): array
	{
		$token = bin2hex(random_bytes(32));
		$record['link'] = hash('sha256', $token);
		$record['link_expires'] = time() + self::LINK_TTL;
		update_option(self::OPTION_PREFIX . $id, $record, false);
		$url = add_query_arg('token', $token, rest_url('llamly/v1/upload/' . $id));
		$file = str_replace("'", "'\\''", (string) $record['filename']);
		return [
			'upload_url' => $url,
			'upload_url_expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $record['link_expires']),
			// The path after @ is the file on your disk: run it in the folder of the file or put its full path there.
			'upload_command' => "curl -sS -X PUT -H 'Content-Type: application/octet-stream' --data-binary @'" . $file . "' '" . $url . "'",
			'upload_next' => 'Run upload_command: it is the second step of this Llamly MCP upload (the Llamly MCP server receives the file at upload_url and answers with JSON received: true). Then call wp_upload_media_finish with this upload_id.',
		];
	}

	// ------------------------------------------------------------------ wp_upload_media_part

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function part(array $args, array $auth, string $tool): mixed
	{
		$id = (string) ($args['upload_id'] ?? '');
		$record = self::owned($id, $auth);
		if (is_wp_error($record)) {
			return $record;
		}
		$data = $args['data'] ?? null;
		if (!is_string($data) || strlen($data) % 4 !== 0 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $data)) {
			return Toolkit::error('invalid_arguments', __('data must be strict base64 of the part bytes (no URL, data: prefix or line breaks).', 'llamly'), 400);
		}
		$bytes = base64_decode($data, true);
		unset($data, $args['data']);
		if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::PART_BYTES) {
			/* translators: %s: maximum size of one part. */
			return Toolkit::error('invalid_arguments', sprintf(__('Each part must be valid base64 of 1 byte to %s.', 'llamly'), size_format(self::PART_BYTES)), 400);
		}
		if (!empty($args['dry_run'])) {
			return ['dry_run' => true, 'upload_id' => $id, 'index' => (int) ($args['index'] ?? 0), 'bytes' => strlen($bytes)];
		}
		if (!self::lock($id)) {
			return Toolkit::error('lock_timeout', __('Another part of this upload is being saved. Send this part again.', 'llamly'), 409);
		}
		try {
			$record = self::owned($id, $auth);
			if (is_wp_error($record)) {
				return $record;
			}
			$index = (int) ($args['index'] ?? -1);
			$sha = hash('sha256', $bytes);
			$parts = (int) $record['parts'];
			if ($index === $parts - 1 && hash_equals((string) $record['last_part'], $sha)) {
				// The same last part again (a retry after a lost answer): nothing to add.
				return self::describe($id, $record) + ['index' => $index, 'replayed' => true];
			}
			if ($index !== $parts) {
				/* translators: 1: part index sent, 2: index expected next. */
				return new \WP_Error('part_out_of_order', sprintf(__('Part %1$d is out of order: send part %2$d next (parts start at 0 and go in order).', 'llamly'), $index, $parts), ['status' => 409, 'expected_index' => $parts]);
			}
			if ((int) $record['received'] + strlen($bytes) > (int) $record['size']) {
				/* translators: %s: total size declared at the start. */
				return Toolkit::error('size_exceeded', sprintf(__('This part goes past the total size declared at the start (%s).', 'llamly'), size_format((int) $record['size'])), 413);
			}
			$reserved = self::reserve($id, $record, 'part');
			if ($reserved !== null) {
				return $reserved;
			}
			$path = self::dir($id) . '/' . $index . '.part';
			if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
				wp_delete_file($path);
				if ($parts === 0) {
					// No bytes kept: the reservation of the first part is given back.
					unset($record['staging']);
					update_option(self::OPTION_PREFIX . $id, $record, false);
				}
				return Toolkit::error('upload_failed', __('The part could not be saved on the server.', 'llamly'), 503);
			}
			Filesystem::direct()->chmod($path, 0600);
			$record['parts'] = $parts + 1;
			$record['received'] = (int) $record['received'] + strlen($bytes);
			$record['last_part'] = $sha;
			// The upload goes by parts now: its link stops working.
			$record['link'] = '';
			update_option(self::OPTION_PREFIX . $id, $record, false);
			return self::describe($id, $record) + ['index' => $index];
		} finally {
			self::unlock($id);
		}
	}

	// ------------------------------------------------------------------ wp_upload_media_finish

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function finish(array $args, array $auth, string $tool): mixed
	{
		$id = (string) ($args['upload_id'] ?? '');
		if (!self::lock($id)) {
			return Toolkit::error('lock_timeout', __('This upload is being finished by another call. Try again.', 'llamly'), 409);
		}
		try {
			$record = self::owned($id, $auth, true);
			if (is_wp_error($record)) {
				return $record;
			}
			$keyHash = self::keyHash($auth, (string) ($args['idempotency_key'] ?? ''));
			if (($record['status'] ?? '') === 'finished') {
				if (!hash_equals((string) ($record['finish_key'] ?? ''), $keyHash)) {
					return new \WP_Error('upload_finished', __('This upload was already finished. Its media item is in the result of the first finish call.', 'llamly'), ['status' => 409]);
				}
				// Same key: the Engine answers with the stored result of that change.
				return Toolkit::run('media', $tool, 0, $args, $auth, (array) $record['intent'], 'media_uploaded');
			}
			if ((int) $record['received'] !== (int) $record['size']) {
				/* translators: 1: bytes received, 2: total size declared at the start. */
				return new \WP_Error('upload_incomplete', sprintf(__('Only %1$s of %2$s arrived. Send the remaining parts before finishing.', 'llamly'), size_format((int) $record['received']), size_format((int) $record['size'])), ['status' => 409, 'expected_index' => (int) $record['parts']]);
			}
			$staged = self::dir($id) . '/joined.upload';
			if (!self::join($id, (int) $record['parts'], $staged)) {
				wp_delete_file($staged);
				return Toolkit::error('upload_failed', __('The parts could not be joined on the server.', 'llamly'), 503);
			}
			$sha = (string) hash_file('sha256', $staged);
			if (!hash_equals((string) $record['sha256'], $sha)) {
				self::remove($id);
				return Toolkit::error('checksum_mismatch', __('The sha256 of the joined file does not match the one sent at the start. The upload was discarded; start again.', 'llamly'), 422);
			}
			$check = MediaObject::validateUpload($staged, (string) $record['filename'], (int) $auth['user_id']);
			if (!is_wp_error($check) && strtolower((string) $check['type']) !== (string) $record['mime']) {
				/* translators: 1: MIME type found in the file, 2: MIME type declared at the start. */
				$check = new \WP_Error('type_mismatch', sprintf(__('The file content is %1$s, not the %2$s declared at the start.', 'llamly'), (string) $check['type'], (string) $record['mime']), ['status' => 400]);
			}
			if (is_wp_error($check)) {
				self::remove($id);
				return $check;
			}
			$result = MediaTools::uploadStaged($staged, $check, $args, $auth, $tool);
			if (is_wp_error($result)) {
				$data = $result->get_error_data();
				if (is_array($data) && ($data['requires'] ?? '') === 'human') {
					// Kept for the repeat after the approval, as long as the approval can wait.
					$record['expires'] = max((int) $record['expires'], time() + self::TTL_APPROVAL);
					update_option(self::OPTION_PREFIX . $id, $record, false);
					wp_schedule_single_event((int) $record['expires'] + MINUTE_IN_SECONDS, self::HOOK_EXPIRE, [$id]);
				}
				return $result;
			}
			if (empty($args['dry_run']) && is_array($result) && ($result['status'] ?? '') === 'committed') {
				self::removeFiles($id);
				$record['status'] = 'finished';
				$record['finish_key'] = $keyHash;
				$record['intent'] = MediaTools::lastIntent();
				update_option(self::OPTION_PREFIX . $id, $record, false);
			}
			return $result;
		} finally {
			wp_delete_file(self::dir($id) . '/joined.upload');
			self::unlock($id);
		}
	}

	// ------------------------------------------------------------------ PUT llamly/v1/upload/<id>?token=...

	/**
	 * The link is the only credential of the PUT. Unknown upload, wrong token, or a link already used: 404 (the same
	 * answer, so a link reveals nothing about other uploads). Expired link: 410. User without upload_files: 403.
	 */
	public static function linkPermission(\WP_REST_Request $request): bool|\WP_Error
	{
		$record = self::linkRecord((string) $request['id'], (string) $request->get_param('token'));
		return is_wp_error($record) ? $record : true;
	}

	/** Receives the raw file of a link: exactly the declared size and sha256, into the private folder, once. */
	public static function receive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$id = (string) $request['id'];
		if (!self::lock($id)) {
			return Toolkit::error('lock_timeout', __('This upload is busy. Try the same command again.', 'llamly'), 409);
		}
		try {
			$record = self::linkRecord($id, (string) $request->get_param('token'));
			if (is_wp_error($record)) {
				return $record;
			}
			// Over the limit of bytes held, the link stays valid: the same command works after other uploads finish.
			$reserved = self::reserve($id, $record, 'link');
			if ($reserved !== null) {
				return $reserved;
			}
			// The link works once: from here on, any answer leaves it used. The finish must follow within LINK_TTL.
			$record['link'] = '';
			$record['expires'] = min((int) $record['expires'], time() + self::LINK_TTL);
			update_option(self::OPTION_PREFIX . $id, $record, false);
			$body = $request->get_body();
			$size = (int) $record['size'];
			if (strlen($body) !== $size) {
				self::remove($id);
				/* translators: 1: bytes received, 2: size declared at the start. */
				return Toolkit::error('size_mismatch', sprintf(__('The upload link received %1$s bytes; the start declared %2$s bytes. The upload was discarded; start again with wp_upload_media_start.', 'llamly'), number_format_i18n(strlen($body)), number_format_i18n($size)), 400);
			}
			$sha = hash('sha256', $body);
			if (!hash_equals((string) $record['sha256'], $sha)) {
				self::remove($id);
				return Toolkit::error('checksum_mismatch', __('The sha256 of the received file does not match the one sent at the start. The upload was discarded; start again with wp_upload_media_start.', 'llamly'), 422);
			}
			$path = self::dir($id) . '/0.part';
			if (!is_dir(self::dir($id)) || @file_put_contents($path, $body, LOCK_EX) !== $size) {
				self::remove($id);
				return Toolkit::error('upload_failed', __('The file could not be saved on the server. Start again with wp_upload_media_start.', 'llamly'), 503);
			}
			unset($body);
			Filesystem::direct()->chmod($path, 0600);
			$record['parts'] = 1;
			$record['received'] = $size;
			$record['last_part'] = $sha;
			// The finish must follow: the staged file is kept for LINK_TTL, not for the whole TTL.
			$record['expires'] = min((int) $record['expires'], time() + self::LINK_TTL);
			update_option(self::OPTION_PREFIX . $id, $record, false);
			wp_clear_scheduled_hook(self::HOOK_EXPIRE, [$id]);
			wp_schedule_single_event((int) $record['expires'] + MINUTE_IN_SECONDS, self::HOOK_EXPIRE, [$id]);
			return new \WP_REST_Response([
				'received' => true,
				'upload_id' => $id,
				'size' => $size,
				'sha256' => $sha,
				'next' => sprintf('Call wp_upload_media_finish with upload_id %s before %s.', $id, gmdate('Y-m-d\TH:i:s\Z', (int) $record['expires'])),
			], 200);
		} finally {
			self::unlock($id);
		}
	}

	/** @return array<string, mixed>|\WP_Error the open upload this link token belongs to */
	private static function linkRecord(string $id, string $token): array|\WP_Error
	{
		$notFound = new \WP_Error('upload_not_found', __('This upload link does not exist or was already used. Start again with wp_upload_media_start.', 'llamly'), ['status' => 404]);
		$record = preg_match('/^[a-f0-9]{32}$/', $id) && preg_match('/^[a-f0-9]{64}$/', $token) ? self::record($id) : null;
		if ($record === null || ($record['status'] ?? '') !== 'open' || (string) ($record['link'] ?? '') === '' || !hash_equals((string) $record['link'], hash('sha256', $token))) {
			return $notFound;
		}
		if ((int) ($record['link_expires'] ?? 0) <= time() || (int) $record['expires'] <= time()) {
			return new \WP_Error('upload_link_expired', __('This upload link expired. Start again with wp_upload_media_start.', 'llamly'), ['status' => 410]);
		}
		if (!user_can((int) $record['user'], 'upload_files')) {
			return new \WP_Error('forbidden', __('The user of this upload can no longer upload files.', 'llamly'), ['status' => 403]);
		}
		return $record;
	}

	// ------------------------------------------------------------------ cleanup

	/** @internal Scheduled at the expiry of each upload. */
	public static function expire($id): void
	{
		$id = (string) $id;
		$record = self::record($id);
		if ($record !== null && (int) ($record['expires'] ?? 0) <= time()) {
			self::remove($id);
		}
	}

	/** Removes expired uploads (records and files) and part folders that lost their record. Bounded. */
	public static function sweep(): void
	{
		foreach (array_slice(self::records(), 0, 200, true) as $id => $record) {
			if ((int) ($record['expires'] ?? 0) <= time() || self::abandoned($record)) {
				self::remove($id);
			}
		}
		$base = self::baseDir();
		if (is_wp_error($base) || !is_dir($base)) {
			return;
		}
		foreach (array_slice((array) scandir($base), 0, 500) as $entry) {
			if (preg_match('/^[a-f0-9]{32}$/', (string) $entry) && self::record((string) $entry) === null && filemtime($base . '/' . $entry) < time() - HOUR_IN_SECONDS) {
				self::removeFiles((string) $entry);
			}
		}
	}

	/**
	 * An open upload whose link expired before any byte arrived (by the link or by parts), or whose first bytes were let
	 * in (staging) more than LINK_TTL ago and never saved (a failed write or a request that died).
	 */
	private static function abandoned(array $record): bool
	{
		if (($record['status'] ?? '') !== 'open' || (int) ($record['received'] ?? 0) > 0 || (int) ($record['parts'] ?? 0) > 0) {
			return false;
		}
		if (!empty($record['staging'])) {
			return (int) $record['staging'] <= time() - self::LINK_TTL;
		}
		return (int) ($record['link_expires'] ?? 0) > 0 && (int) $record['link_expires'] <= time();
	}

	/** Whether an upload holds bytes in the private folder, or is about to (its first bytes were let in). */
	private static function holdsBytes(array $record): bool
	{
		return (int) ($record['received'] ?? 0) > 0 || (int) ($record['parts'] ?? 0) > 0 || !empty($record['staging']);
	}

	/** Total of the declared sizes the uploads of one user that hold bytes may reach. */
	public static function stagedLimit(): int
	{
		return self::STAGED_FILES * (int) wp_max_upload_size();
	}

	/** @return array<string, array<string, mixed>> the open, unexpired uploads of a user */
	private static function openOf(int $userId): array
	{
		return array_filter(self::records(), static fn(array $r): bool => (int) ($r['user'] ?? 0) === $userId && ($r['status'] ?? '') === 'open' && (int) ($r['expires'] ?? 0) > time());
	}

	/**
	 * too_many_uploads when the bytes held by $staged plus $size would pass stagedLimit(), else null. $when says what the
	 * agent repeats: the start, the same upload command, or the same part.
	 *
	 * @param array<string, array<string, mixed>> $staged
	 */
	private static function stagedError(array $staged, int $size, string $when): ?\WP_Error
	{
		$held = (int) array_sum(array_map(static fn(array $r): int => (int) $r['size'], $staged));
		if ($held + $size <= self::stagedLimit()) {
			return null;
		}
		$at = $staged === [] ? '' : gmdate('Y-m-d\TH:i:s\Z', (int) min(array_map(static fn(array $r): int => (int) $r['expires'], $staged)));
		/* translators: 1: bytes held by the unfinished uploads of this user, 2: size of this file, 3: limit of bytes held, 4: how many files of the site upload limit that is. */
		$message = sprintf(__('Limit of file bytes held reached: the unfinished uploads of this user that hold their file add up to %1$s, and this file (%2$s) would take them past %3$s (%4$d times the site upload limit).', 'llamly'), size_format($held), size_format($size), size_format(self::stagedLimit()), self::STAGED_FILES);
		$then = match ($when) {
			/* translators: %s: time (UTC) when the first unfinished upload expires. */
			'link' => sprintf(__('Finish them with wp_upload_media_finish, then run the same upload command again while its link is valid (or wait until they expire, the first one at %s).', 'llamly'), $at),
			/* translators: %s: time (UTC) when the first unfinished upload expires. */
			'part' => sprintf(__('Finish them with wp_upload_media_finish, then send this part again (or wait until they expire, the first one at %s).', 'llamly'), $at),
			/* translators: %s: time (UTC) when the first unfinished upload expires. */
			default => sprintf(__('Finish them with wp_upload_media_finish, or wait until they expire (the first one at %s).', 'llamly'), $at),
		};
		return new \WP_Error('too_many_uploads', $message . ' ' . $then, ['status' => 429, 'limit' => 'bytes', 'retry_at' => $at]);
	}

	/**
	 * Before the first bytes of an upload are kept (link or part 0): under the start lock of its user, the bytes already
	 * held by the user's other uploads plus this size must fit stagedLimit(). On success the record is marked as holding
	 * bytes (staging) and saved, so a concurrent start, link or part counts it. The caller holds the upload's own lock.
	 *
	 * @param array<string, mixed> $record updated in place and saved
	 */
	private static function reserve(string $id, array &$record, string $when): ?\WP_Error
	{
		if (self::holdsBytes($record)) {
			return null;
		}
		$userId = (int) $record['user'];
		$userLock = 'u' . $userId;
		if (!self::lock($userLock)) {
			return Toolkit::error('lock_timeout', __('Another upload of this user is starting. Try again.', 'llamly'), 409);
		}
		try {
			$others = array_filter(self::openOf($userId), [self::class, 'holdsBytes']);
			unset($others[$id]);
			$error = self::stagedError($others, (int) $record['size'], $when);
			if ($error !== null) {
				return $error;
			}
			$record['staging'] = time();
			update_option(self::OPTION_PREFIX . $id, $record, false);
			return null;
		} finally {
			self::unlock($userLock);
		}
	}

	/** Uninstall: every upload record and every part folder of this site. */
	public static function purgeAll(): void
	{
		foreach (array_keys(self::records()) as $id) {
			self::remove($id);
		}
		$base = self::baseDir();
		if (!is_wp_error($base) && is_dir($base)) {
			foreach ((array) scandir($base) as $entry) {
				if (preg_match('/^[a-f0-9]{32}$/', (string) $entry)) {
					self::removeFiles((string) $entry);
				}
			}
			Filesystem::direct()->rmdir($base);
		}
	}

	// ------------------------------------------------------------------ internals

	/** @return array<string, mixed>|\WP_Error */
	private static function owned(string $id, array $auth, bool $finished = false): array|\WP_Error
	{
		$record = preg_match('/^[a-f0-9]{32}$/', $id) ? self::record($id) : null;
		if ($record === null || (int) $record['user'] !== (int) ($auth['user_id'] ?? 0) || !hash_equals((string) $record['credential'], (string) ($auth['credential_key'] ?? ''))) {
			return new \WP_Error('upload_not_found', __('This upload does not exist, expired, or belongs to another connection. Start again with wp_upload_media_start.', 'llamly'), ['status' => 404]);
		}
		if ((int) $record['expires'] <= time()) {
			self::remove($id);
			return new \WP_Error('upload_expired', __('This upload expired before it was finished. Start again with wp_upload_media_start.', 'llamly'), ['status' => 410]);
		}
		if (!$finished && ($record['status'] ?? '') !== 'open') {
			return new \WP_Error('upload_finished', __('This upload was already finished.', 'llamly'), ['status' => 409]);
		}
		return $record;
	}

	/** @return array<string, mixed>|null */
	private static function record(string $id): ?array
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_PREFIX . $id));
		$value = is_string($raw) ? maybe_unserialize($raw) : null;
		return is_array($value) ? $value : null;
	}

	/** @return array<string, array<string, mixed>> */
	private static function records(): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like(self::OPTION_PREFIX) . '%'), ARRAY_A);
		$out = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$id = substr((string) $row['option_name'], strlen(self::OPTION_PREFIX));
			$value = maybe_unserialize((string) $row['option_value']);
			if (preg_match('/^[a-f0-9]{32}$/', $id) && is_array($value)) {
				$out[$id] = $value;
			}
		}
		return $out;
	}

	/** @param array<string, mixed> $record @return array<string, mixed> */
	private static function describe(string $id, array $record): array
	{
		$size = (int) $record['size'];
		return [
			'upload_id' => $id,
			'filename' => (string) $record['filename'],
			'mime_type' => (string) $record['mime'],
			'size' => $size,
			'part_bytes' => self::PART_BYTES,
			'parts_expected' => (int) max(1, ceil($size / self::PART_BYTES)),
			'next_index' => (int) $record['parts'],
			'received_bytes' => (int) $record['received'],
			'remaining_bytes' => max(0, $size - (int) $record['received']),
			'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $record['expires']),
		];
	}

	/**
	 * Folder for the parts in the server's temporary directory; refused when that directory is inside the site. Only a
	 * start creates it ($create); the cleanup and every other step only use it when it is already there.
	 */
	private static function baseDir(bool $create = false): string|\WP_Error
	{
		$temp = realpath(untrailingslashit(get_temp_dir()));
		$unavailable = new \WP_Error('upload_parts_unavailable', __('Uploads in parts need a temporary directory outside the website folder (WP_TEMP_DIR or the system one), and this server has none. Use wp_upload_media for small files.', 'llamly'), ['status' => 503]);
		if ($temp === false || !is_dir($temp) || !wp_is_writable($temp)) {
			return $unavailable;
		}
		$temp = trailingslashit(wp_normalize_path($temp));
		// The site folders are only compared with the temporary directory, never used to build a path.
		foreach ([ABSPATH, WP_CONTENT_DIR] as $site) {
			$real = realpath($site);
			if ($real !== false && strpos($temp, trailingslashit(wp_normalize_path($real))) === 0) {
				return $unavailable;
			}
		}
		// The name carries a site secret, so another user of a shared temporary directory cannot prepare it in advance;
		// an existing folder must still be a real folder of this process with mode 0700.
		$base = $temp . self::DIR . '-' . substr(wp_hash(home_url() . '|' . get_current_blog_id(), 'auth'), 0, 24);
		if (!file_exists($base) && !is_link($base) && (!$create || !Filesystem::direct()->mkdir($base, 0700))) {
			return $unavailable;
		}
		clearstatcache(true, $base);
		$stat = @lstat($base);
		$owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
		if (is_link($base) || !is_dir($base) || !is_array($stat) || (int) $stat['uid'] !== (int) $owner || ((int) $stat['mode'] & 0777) !== 0700) {
			return $unavailable;
		}
		return $base;
	}

	private static function dir(string $id): string
	{
		$base = self::baseDir();
		return (is_wp_error($base) ? '' : $base) . '/' . $id;
	}

	private static function join(string $id, int $parts, string $target): bool
	{
		$out = @fopen($target, 'wb');
		if ($out === false) {
			return false;
		}
		$ok = true;
		for ($i = 0; $i < $parts && $ok; $i++) {
			$in = @fopen(self::dir($id) . '/' . $i . '.part', 'rb');
			$ok = $in !== false && stream_copy_to_stream($in, $out) !== false;
			if ($in !== false) {
				fclose($in);
			}
		}
		fclose($out);
		Filesystem::direct()->chmod($target, 0600);
		return $ok;
	}

	private static function remove(string $id): void
	{
		self::removeFiles($id);
		delete_option(self::OPTION_PREFIX . $id);
		wp_clear_scheduled_hook(self::HOOK_EXPIRE, [$id]);
	}

	private static function removeFiles(string $id): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
			return;
		}
		$dir = self::dir($id);
		if ($dir === '/' . $id || !is_dir($dir) || is_link($dir)) {
			return;
		}
		foreach ((array) scandir($dir) as $file) {
			if (preg_match('/^(?:\d+\.part|joined\.upload)$/', (string) $file)) {
				wp_delete_file($dir . '/' . $file);
			}
		}
		Filesystem::direct()->rmdir($dir);
	}

	private static function keyHash(array $auth, string $key): string
	{
		return hash('sha256', (int) ($auth['user_id'] ?? 0) . '|' . (string) ($auth['credential_key'] ?? '') . '|' . $key);
	}

	private static function lock(string $id): bool
	{
		global $wpdb;
		return preg_match('/^(?:[a-f0-9]{32}|u\d+)$/', $id) === 1 && (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', 'llamly:upl:' . $id)) === 1;
	}

	private static function unlock(string $id): void
	{
		global $wpdb;
		if (preg_match('/^(?:[a-f0-9]{32}|u\d+)$/', $id)) {
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'llamly:upl:' . $id));
		}
	}
}
