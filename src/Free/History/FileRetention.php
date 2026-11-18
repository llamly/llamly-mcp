<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Core\Filesystem;

/**
 * File side of reversible media changes.
 *
 * Files are not transactional, so every file effect taken inside the Engine transaction is registered here and settled
 * by the transaction outcome:
 * - ROLLBACK (seen through the `query` filter before it runs): files created or placed by the failed attempt are
 *   removed and a retention directory created by it is discarded. Originals were never touched.
 * - COMMIT (Engine phase `after_commit`): originals of a removed attachment are unlinked only now, and a retention
 *   consumed by a restore is released.
 * A process that dies between COMMIT and settlement leaves a harmless extra copy (originals still in uploads, or a
 * retention directory without index row), never a loss. Orphan retention directories are swept after a day.
 *
 * Retention: uploads/llamly-mcp/retention/<32 random hex>/<n>.bin, hardlink with copy fallback, size + sha256 checked.
 * The directory carries .htaccess/web.config/index.html; nginx ignores .htaccess, so protection there relies on the
 * unguessable directory name, the absence of listings and the opaque blob names (no original file names or extensions).
 * The index lives in wp_options rows (llamly_free_mret_<operation id>) written inside the Engine transaction, so it
 * commits or rolls back together with the database change it belongs to.
 */
final class FileRetention
{
	public const OPTION_PREFIX = 'llamly_free_mret_';
	private const SUBDIR = 'llamly-mcp/retention';
	private const QUOTA_OPERATION = 50 * 1048576;
	private const QUOTA_TOTAL = 500 * 1048576;
	private const ORPHAN_AGE = DAY_IN_SECONDS;

	/** @var list<callable> */
	private static array $onRollback = [];
	/** @var list<callable> */
	private static array $onCommit = [];
	private static bool $booted = false;

	public static function boot(): void
	{
		if (self::$booted) {
			return;
		}
		self::$booted = true;
		add_filter('query', [self::class, 'watchQuery'], 1);
		add_action('llamly_free_mutation_phase', [self::class, 'onPhase'], 10, 2);
		add_action('llamly_free_snapshot_expired', [self::class, 'snapshotExpired'], 10, 4);
	}

	/** @internal query filter: a ROLLBACK undoes the file effects of the attempt before the database rolls back. */
	public static function watchQuery($query)
	{
		if ((self::$onRollback || self::$onCommit) && is_string($query) && preg_match('/^\s*ROLLBACK\b/i', $query)) {
			self::settle(false);
		}
		return $query;
	}

	/** @internal */
	public static function onPhase($phase, $operationId = ''): void
	{
		if ($phase === 'after_commit') {
			self::settle(true);
		}
	}

	/** Run the pending file effects for a committed (true) or rolled back (false) transaction. */
	public static function settle(bool $committed): void
	{
		$run = $committed ? self::$onCommit : array_reverse(self::$onRollback);
		self::$onCommit = [];
		self::$onRollback = [];
		foreach ($run as $effect) {
			try {
				$effect();
			} catch (\Throwable $error) {
				// Settlement is best effort by design: leftovers are extra copies, never lost files.
			}
		}
	}

	public static function pending(): bool
	{
		return (bool) (self::$onRollback || self::$onCommit);
	}

	/** @internal snapshot expiry purges the retention that belonged to the expired change. */
	public static function snapshotExpired($objectType, $operationId, $before = null, $after = null): void
	{
		if ($objectType === 'media' && is_string($operationId)) {
			self::purge($operationId);
		}
	}

	// ------------------------------------------------------------------ paths

	public static function basedir(): ?string
	{
		$uploads = wp_upload_dir(null, false);
		$base = is_array($uploads) && empty($uploads['error']) ? realpath((string) $uploads['basedir']) : false;
		return is_string($base) ? wp_normalize_path($base) : null;
	}

	/** Absolute path of an uploads-relative file, or null when the path could escape the uploads directory. */
	public static function absolute(string $rel): ?string
	{
		$base = self::basedir();
		if ($base === null || !self::validRel($rel)) {
			return null;
		}
		$path = $base . '/' . $rel;
		$check = file_exists($path) ? realpath($path) : realpath(dirname($path));
		if ($check !== false && strpos(wp_normalize_path($check) . '/', $base . '/') !== 0) {
			return null;
		}
		return $path;
	}

	public static function validRel(string $rel): bool
	{
		if ($rel === '' || strlen($rel) > 1024 || $rel[0] === '/' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false || preg_match('/[\x00-\x1f]/', $rel)) {
			return false;
		}
		foreach (explode('/', $rel) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return false;
			}
		}
		return strpos($rel, 'llamly-mcp/') !== 0;
	}

	/**
	 * Complete file set of an attachment from its raw meta values: attached file, sizes and their
	 * multi-MIME sources, full-size sources, original_image, backup sizes of edited images and audio/video thumbs.
	 *
	 * @return list<string>|null Sorted uploads-relative paths; null when a path is not safely inside uploads.
	 */
	public static function filesOf(string $attached, string $rawMeta, string $rawBackup): ?array
	{
		$base = self::basedir();
		if ($attached === '' || $base === null) {
			return $attached === '' ? [] : null;
		}
		$attached = wp_normalize_path($attached);
		if (strpos($attached, $base . '/') === 0) {
			$attached = substr($attached, strlen($base) + 1);
		}
		$dir = dirname($attached);
		$dir = $dir === '.' ? '' : $dir . '/';
		$files = [$attached];
		$meta = self::unserialize($rawMeta);
		$name = static function ($file) use ($dir): ?string {
			return is_string($file) && $file !== '' ? $dir . wp_basename($file) : null;
		};
		$sources = static function ($list) use ($name, &$files): void {
			foreach (is_array($list) ? $list : [] as $source) {
				if (is_array($source) && ($file = $name($source['file'] ?? null)) !== null) {
					$files[] = $file;
				}
			}
		};
		if (is_array($meta)) {
			foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
				if (is_array($size)) {
					if (($file = $name($size['file'] ?? null)) !== null) {
						$files[] = $file;
					}
					$sources($size['sources'] ?? null);
				}
			}
			$sources($meta['sources'] ?? null);
			foreach (['original_image', 'thumb'] as $key) {
				if (($file = $name($meta[$key] ?? null)) !== null) {
					$files[] = $file;
				}
			}
		}
		$backup = self::unserialize($rawBackup);
		foreach (is_array($backup) ? $backup : [] as $size) {
			if (is_array($size) && ($file = $name($size['file'] ?? null)) !== null) {
				$files[] = $file;
			}
		}
		$files = array_values(array_unique($files));
		sort($files, SORT_STRING);
		foreach ($files as $rel) {
			if (self::absolute($rel) === null) {
				return null;
			}
		}
		return $files;
	}

	/** @param list<string> $rels @return list<array{rel:string,size:int}> size -1 when missing or unreadable */
	public static function manifest(array $rels): array
	{
		$out = [];
		foreach ($rels as $rel) {
			$path = self::absolute($rel);
			clearstatcache(true, (string) $path);
			$size = $path !== null && is_file($path) && !is_link($path) && is_readable($path) ? filesize($path) : false;
			$out[] = ['rel' => $rel, 'size' => $size === false ? -1 : (int) $size];
		}
		return $out;
	}

	public static function sha(string $path): ?string
	{
		$hash = is_file($path) ? hash_file('sha256', $path) : false;
		return is_string($hash) ? $hash : null;
	}

	// ------------------------------------------------------------------ retention

	/**
	 * Retain every file of an attachment for $operationId before the database removes it. Runs inside the Engine
	 * transaction; any failure throws before the database is touched.
	 *
	 * @param list<string> $rels
	 * @return array<string, mixed> The retention entry.
	 */
	public static function retain(string $operationId, int $attachmentId, array $rels): array
	{
		global $wpdb;
		$quota = self::quota();
		$files = [];
		$bytes = 0;
		foreach ($rels as $rel) {
			$path = self::absolute($rel);
			if ($path === null || !is_file($path) || is_link($path) || !is_readable($path)) {
				throw new MutationFailure('media_files_unreadable', esc_html__('A file of this media item is missing or unreadable, so it cannot be kept for undo. Nothing was deleted.', 'llamly'), 409);
			}
			$size = (int) filesize($path);
			$sha = self::sha($path);
			if ($sha === null) {
				throw new MutationFailure('media_files_unreadable', esc_html__('A file of this media item could not be read. Nothing was deleted.', 'llamly'), 409);
			}
			$bytes += $size;
			$files[] = ['rel' => $rel, 'size' => $size, 'sha256' => $sha, 'source' => $path];
		}
		if ($bytes > $quota['operation']) {
			throw new MutationFailure('retention_quota_exceeded', esc_html__('This media item is larger than the undo retention allows for one change. Nothing was deleted.', 'llamly'), 413);
		}
		if (self::totalBytes() + $bytes > $quota['total']) {
			throw new MutationFailure('retention_quota_exceeded', esc_html__('The undo retention space for media is full. Nothing was deleted; wait for older changes to expire.', 'llamly'), 507);
		}
		$root = self::root(true);
		if ($root === null) {
			throw new MutationFailure('retention_unavailable', esc_html__('The protected retention directory could not be prepared. Nothing was deleted.', 'llamly'), 503);
		}
		$dirName = bin2hex(random_bytes(16));
		$dir = $root . '/' . $dirName;
		if (!wp_mkdir_p($dir)) {
			throw new MutationFailure('retention_unavailable', esc_html__('The retention directory could not be created. Nothing was deleted.', 'llamly'), 503);
		}
		self::$onRollback[] = static function () use ($dirName): void {
			self::removeDir($dirName);
		};
		$entryFiles = [];
		foreach ($files as $index => $file) {
			$blob = $index . '.bin';
			$target = $dir . '/' . $blob;
			$linked = @link($file['source'], $target);
			if (!$linked && !@copy($file['source'], $target)) {
				self::removeDir($dirName);
				throw new MutationFailure('retention_failed', esc_html__('A media file could not be copied to the undo retention. Nothing was deleted.', 'llamly'), 503);
			}
			clearstatcache(true, $target);
			if ((int) filesize($target) !== $file['size'] || self::sha($target) !== $file['sha256']) {
				self::removeDir($dirName);
				throw new MutationFailure('retention_failed', esc_html__('The retained copy of a media file did not verify. Nothing was deleted.', 'llamly'), 503);
			}
			$entryFiles[] = ['rel' => $file['rel'], 'size' => $file['size'], 'sha256' => $file['sha256'], 'blob' => $blob];
		}
		$entry = ['v' => 1, 'operation_id' => $operationId, 'attachment_id' => $attachmentId, 'dir' => $dirName, 'bytes' => $bytes, 'created' => time(), 'files' => $entryFiles];
		$name = self::optionName($operationId);
		$wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']);
		if ($wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => wp_json_encode($entry), 'autoload' => 'no'], ['%s', '%s', '%s']) !== 1) {
			self::removeDir($dirName);
			throw new MutationFailure('journal_unavailable', esc_html__('The retention index could not be stored. Nothing was deleted.', 'llamly'), 503);
		}
		return $entry;
	}

	/** @return array<string, mixed>|null The retention entry written by $operationId (raw row, no option cache). */
	public static function entry(string $operationId): ?array
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::optionName($operationId)));
		$entry = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($entry) && isset($entry['dir'], $entry['files']) && preg_match('/^[a-f0-9]{32}$/', (string) $entry['dir']) ? $entry : null;
	}

	/**
	 * Put retained files back at their original paths, all or nothing. Destinations that already hold identical
	 * content are accepted; different content is a conflict. Files are staged next to their destination, verified,
	 * and only then renamed into place. A failure removes everything this call placed.
	 *
	 * @param array<string, mixed> $entry
	 * @param list<array{rel:string,size:int}> $manifest Target manifest the files must match exactly.
	 * @return list<string> Paths placed by this call.
	 */
	public static function restoreFiles(array $entry, array $manifest): array
	{
		$root = self::root(false);
		$retained = [];
		foreach ((array) $entry['files'] as $file) {
			$retained[] = ['rel' => (string) $file['rel'], 'size' => (int) $file['size']];
		}
		$want = array_map(static fn(array $f): array => ['rel' => (string) $f['rel'], 'size' => (int) $f['size']], $manifest);
		usort($retained, static fn($a, $b) => strcmp($a['rel'], $b['rel']));
		usort($want, static fn($a, $b) => strcmp($a['rel'], $b['rel']));
		if ($retained !== $want) {
			throw new MutationFailure('undo_conflict', esc_html__('The retained files do not match the media item being restored.', 'llamly'), 409);
		}
		if ($root === null) {
			throw new MutationFailure('undo_unavailable', esc_html__('The retained media files are no longer available.', 'llamly'), 409);
		}
		$plan = [];
		foreach ((array) $entry['files'] as $file) {
			$blob = $root . '/' . $entry['dir'] . '/' . basename((string) $file['blob']);
			if (!is_file($blob) || (int) filesize($blob) !== (int) $file['size'] || self::sha($blob) !== (string) $file['sha256']) {
				throw new MutationFailure('undo_unavailable', esc_html__('A retained media file is missing or damaged, so the media item cannot be restored.', 'llamly'), 409);
			}
			$dest = self::absolute((string) $file['rel']);
			if ($dest === null) {
				throw new MutationFailure('undo_conflict', esc_html__('A media file path is no longer inside the uploads directory.', 'llamly'), 409);
			}
			if (file_exists($dest) || is_link($dest)) {
				if (is_link($dest) || self::sha($dest) !== (string) $file['sha256']) {
					throw new MutationFailure('undo_conflict', esc_html__('Another file now exists at the original path of a media file, so it was not overwritten.', 'llamly'), 409);
				}
				continue;
			}
			$plan[] = ['blob' => $blob, 'dest' => $dest, 'sha256' => (string) $file['sha256'], 'rel' => (string) $file['rel']];
		}

		$staged = [];
		$placed = [];
		try {
			foreach ($plan as $index => $item) {
				$dir = dirname($item['dest']);
				if (!wp_mkdir_p($dir)) {
					throw new MutationFailure('file_restore_failed', esc_html__('A media folder could not be recreated.', 'llamly'), 503);
				}
				$tmp = $dir . '/.llamly-tmp-' . bin2hex(random_bytes(8)) . '-' . $index;
				if (!@link($item['blob'], $tmp) && !@copy($item['blob'], $tmp)) {
					throw new MutationFailure('file_restore_failed', esc_html__('A media file could not be staged for restore.', 'llamly'), 503);
				}
				$staged[$index] = $tmp;
				if (self::sha($tmp) !== $item['sha256']) {
					throw new MutationFailure('file_restore_failed', esc_html__('A staged media file did not verify.', 'llamly'), 503);
				}
			}
			foreach ($plan as $index => $item) {
				if (file_exists($item['dest']) || is_link($item['dest'])) {
					throw new MutationFailure('undo_conflict', esc_html__('Another file appeared at the original path of a media file during restore.', 'llamly'), 409);
				}
				if (!Filesystem::direct()->move($staged[$index], $item['dest'])) {
					throw new MutationFailure('file_restore_failed', esc_html__('A media file could not be moved into place.', 'llamly'), 503);
				}
				unset($staged[$index]);
				$placed[] = $item['dest'];
				do_action('llamly_free_media_file_placed', $item['rel'], count($placed), count($plan));
			}
		} catch (\Throwable $error) {
			foreach (array_merge(array_values($staged), $placed) as $path) {
				wp_delete_file($path);
			}
			if ($error instanceof MutationFailure) {
				throw $error;
			}
			throw new MutationFailure('file_restore_failed', esc_html__('Restoring the media files failed; every file placed by this attempt was removed.', 'llamly'), 503);
		}
		self::$onRollback[] = static function () use ($placed): void {
			foreach ($placed as $path) {
				wp_delete_file($path);
			}
		};
		return $placed;
	}

	/** A restore consumed this retention: drop its index row now (transactional) and its files after commit. */
	public static function release(string $operationId): void
	{
		global $wpdb;
		$entry = self::entry($operationId);
		if ($entry === null) {
			return;
		}
		if ($wpdb->delete($wpdb->options, ['option_name' => self::optionName($operationId)], ['%s']) === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The retention index could not be updated.', 'llamly'), 503);
		}
		$dir = (string) $entry['dir'];
		self::$onCommit[] = static function () use ($dir): void {
			self::removeDir($dir);
		};
	}

	/**
	 * Unlink originals only after the removal committed. A file is skipped when its content no longer matches what was
	 * journaled (null sha256 = no check) or when another attachment now points at it.
	 *
	 * @param list<array{path:string,rel:string,sha256:?string}> $files
	 */
	public static function deleteAfterCommit(array $files): void
	{
		self::$onCommit[] = static function () use ($files): void {
			global $wpdb;
			foreach ($files as $file) {
				$path = (string) $file['path'];
				if (!is_file($path) || is_link($path)) {
					continue;
				}
				if ($file['sha256'] !== null && self::sha($path) !== $file['sha256']) {
					continue;
				}
				$shared = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s", (string) $file['rel']));
				if ($shared === 0) {
					wp_delete_file($path);
				}
			}
		};
	}

	/** Files created by a forward upload: removed if the transaction rolls back. @param list<string> $paths */
	public static function createdInTransaction(array $paths): void
	{
		self::$onRollback[] = static function () use ($paths): void {
			foreach ($paths as $path) {
				if (is_file($path) && !is_link($path)) {
					wp_delete_file($path);
				}
			}
		};
	}

	/** Remove the retention of an expired change (outside any transaction). */
	public static function purge(string $operationId): void
	{
		global $wpdb;
		$entry = self::entry($operationId);
		if ($entry !== null) {
			self::removeDir((string) $entry['dir']);
		}
		$wpdb->delete($wpdb->options, ['option_name' => self::optionName($operationId)], ['%s']);
	}

	/**
	 * Lazy sweep, run by the media tools outside a transaction: purge retentions whose change is gone or expired
	 * (also covers sites where the daily maintenance runs before the media domain is loaded), and remove orphan
	 * directories left by a crash between the file step and the commit.
	 */
	public static function sweep(): void
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like(self::OPTION_PREFIX) . '%'), ARRAY_A);
		$known = [];
		$table = \Llamly\Free\Core\Schema::tableNames()['operations'];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$entry = json_decode((string) $row['option_value'], true);
			$opId = is_array($entry) ? (string) ($entry['operation_id'] ?? '') : '';
			$op = $opId !== '' ? $wpdb->get_row($wpdb->prepare('SELECT status, expires_at FROM %i WHERE id = %s', $table, $opId), ARRAY_A) : null;
			$expired = !is_array($op) || $op['status'] !== 'committed' || ($op['expires_at'] !== null && strtotime($op['expires_at'] . ' UTC') <= time());
			if ($expired) {
				if (is_array($entry) && isset($entry['dir'])) {
					self::removeDir((string) $entry['dir']);
				}
				$wpdb->delete($wpdb->options, ['option_name' => (string) $row['option_name']], ['%s']);
				continue;
			}
			$known[(string) $entry['dir']] = true;
		}
		$root = self::root(false);
		foreach ($root !== null ? (array) scandir($root) : [] as $name) {
			if (is_string($name) && preg_match('/^[a-f0-9]{32}$/', $name) && !isset($known[$name]) && (int) filemtime($root . '/' . $name) < time() - self::ORPHAN_AGE) {
				self::removeDir($name);
			}
		}
	}

	/** @return array{operation:int,total:int} */
	public static function quota(): array
	{
		$quota = apply_filters('llamly_free_media_retention_quota', ['operation' => self::QUOTA_OPERATION, 'total' => self::QUOTA_TOTAL]);
		return [
			'operation' => max(1, (int) ($quota['operation'] ?? self::QUOTA_OPERATION)),
			'total' => max(1, (int) ($quota['total'] ?? self::QUOTA_TOTAL)),
		];
	}

	public static function totalBytes(): int
	{
		global $wpdb;
		$total = 0;
		$values = $wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::OPTION_PREFIX) . '%'));
		foreach (is_array($values) ? $values : [] as $value) {
			$entry = json_decode((string) $value, true);
			$total += is_array($entry) ? (int) ($entry['bytes'] ?? 0) : 0;
		}
		return $total;
	}

	public static function optionName(string $operationId): string
	{
		return self::OPTION_PREFIX . substr(preg_replace('/[^a-f0-9]/', '', strtolower($operationId)), 0, 64);
	}

	/** Retention root, created with its protections on demand. */
	public static function root(bool $create): ?string
	{
		$base = self::basedir();
		if ($base === null) {
			return null;
		}
		$root = $base . '/' . self::SUBDIR;
		if (!is_dir($root)) {
			if (!$create || !wp_mkdir_p($root)) {
				return null;
			}
		}
		if ($create) {
			$deny = "# Llamly undo retention: never served.\nOptions -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			$webConfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n";
			foreach ([dirname($root), $root] as $dir) {
				foreach (['.htaccess' => $deny, 'web.config' => $webConfig, 'index.html' => ''] as $file => $content) {
					if (!file_exists($dir . '/' . $file)) {
						@file_put_contents($dir . '/' . $file, $content);
					}
				}
			}
		}
		$real = realpath($root);
		return is_string($real) && strpos(wp_normalize_path($real) . '/', $base . '/') === 0 ? wp_normalize_path($real) : null;
	}

	private static function removeDir(string $dirName): void
	{
		$root = self::root(false);
		if ($root === null || !preg_match('/^[a-f0-9]{32}$/', $dirName)) {
			return;
		}
		$dir = $root . '/' . $dirName;
		$real = realpath($dir);
		if ($real === false || wp_normalize_path($real) !== $dir || is_link($dir)) {
			return;
		}
		foreach ((array) scandir($dir) as $name) {
			if (is_string($name) && $name !== '.' && $name !== '..' && is_file($dir . '/' . $name)) {
				wp_delete_file($dir . '/' . $name);
			}
		}
		Filesystem::direct()->rmdir($dir);
	}

	/** @return mixed */
	private static function unserialize(string $raw)
	{
		if ($raw === '' || !is_serialized($raw)) {
			return null;
		}
		return @unserialize($raw, ['allowed_classes' => false]);
	}
}
