<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\Core\Redactor;
use Llamly\Free\History\Adapter;
use Llamly\Free\History\Engine;
use Llamly\Free\History\FileRetention;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\RawRows;
use Llamly\Free\History\State;

/**
 * Media library attachments.
 *
 * State = full wp_posts row (retain scope for a same-ID reinsert) + raw post meta + term IDs + file manifest
 * [{rel,size}] + references {thumbnail_post_ids, site_icon, site_logo, theme_mods}. The hash covers the post columns, the media
 * meta keys, terms and the manifest; references are retain scope (restored and checked, not hashed).
 *
 * Intents: create (upload), update (title/caption/description/parent/alt), delete. A removal (delete, undo of an
 * upload, redo of a delete) keeps the originals until the transaction commits; the files of a delete and of an undone
 * upload are retained first so the inverse can put them back byte for byte (FileRetention).
 */
final class MediaObject implements Adapter
{
	public const MARKER = '_llamly_pending_operation';
	public const HASH_META = ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt', '_wp_attachment_backup_sizes', '_wp_attachment_context'];
	private const PUBLIC_STATUSES = ['publish', 'future', 'private'];
	/** Never accepted, whatever upload_mimes allows. Checked on every dot-separated part of the name. */
	public const DENIED_EXTENSIONS = [
		'svg', 'svgz', 'html', 'htm', 'shtml', 'xhtml', 'xht', 'xml', 'xsl', 'js', 'mjs', 'cjs', 'php', 'php3', 'php4', 'php5', 'php7',
		'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'swf', 'htaccess', 'exe', 'bat', 'cmd',
	];
	/** Exact MIME types refused as media (markup and script types). Office documents (…openxmlformats…+xml) are not in the list. */
	private const DENIED_MIME_TYPES = [
		'text/html', 'application/xhtml+xml', 'image/svg+xml', 'image/svg', 'application/xml', 'text/xml', 'text/xsl', 'application/xsl',
		'application/javascript', 'text/javascript', 'text/x-javascript', 'application/x-javascript', 'application/ecmascript', 'text/ecmascript',
		'application/x-httpd-php', 'application/x-httpd-php-source', 'application/x-httpd-phps', 'application/x-php', 'text/x-php',
		'application/php', 'text/php', 'application/x-sh', 'application/x-shellscript', 'text/x-shellscript',
	];
	/** Structured-syntax XML types that are documents, not markup the browser would run. */
	private const ALLOWED_XML_FAMILY = '/^application\/vnd\.(?:openxmlformats-|oasis\.opendocument\.)/';
	private const DENIED_NAMES = ['.htaccess', '.htpasswd', '.user.ini', 'web.config', 'php.ini'];

	/** @var array<int, true> attachments whose write changed site icon, logo or theme mods (options cache) in this request */
	private static array $optionsDirty = [];

	/** @var array<int, list<array{rel:string,size:int,sha256:string}>> created files of uploads applied in this request */
	private static array $created = [];

	public function __construct()
	{
		FileRetention::boot();
	}

	public function type(): string
	{
		return 'media';
	}

	public function prefix(): string
	{
		return 'm1';
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		if ($id < 1) {
			return null;
		}
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
		if (!is_array($row) || $wpdb->last_error !== '' || (string) $row['post_type'] !== 'attachment') {
			return null;
		}
		$meta = RawRows::captureMeta($wpdb->postmeta, 'post_id', $id, null, self::metaExclude(), $forUpdate);
		if ($meta === null) {
			return null;
		}
		$ttIds = $forUpdate
			? $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC FOR UPDATE", $id))
			: $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC", $id));
		if (!is_array($ttIds) || $wpdb->last_error !== '') {
			return null;
		}
		$terms = PostAdapter::termsOf(array_map('intval', $ttIds));
		if ($terms === null) {
			return null;
		}
		$rels = FileRetention::filesOf(self::metaValue($meta, '_wp_attached_file'), self::metaValue($meta, '_wp_attachment_metadata'), self::metaValue($meta, '_wp_attachment_backup_sizes'));
		$references = self::references($id, self::metaValue($meta, '_wp_attached_file'));
		if ($references === null) {
			return null;
		}
		return [
			'attachment_id' => $id,
			'row' => array_map('strval', $row),
			'meta' => $meta,
			'terms' => $terms,
			'files_valid' => $rels !== null,
			'files' => $rels === null ? [] : FileRetention::manifest($rels),
			'references' => $references,
		];
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return 'm1_' . Engine::ABSENT;
		}
		$fields = [];
		foreach (PostAdapter::COLUMNS as $column) {
			$value = $state['row'][$column] ?? '';
			$fields[$column] = in_array($column, PostAdapter::INT_FIELDS, true) ? (int) $value : (string) $value;
		}
		$meta = [];
		foreach ((array) ($state['meta'] ?? []) as $item) {
			if (in_array($item['key'], self::HASH_META, true)) {
				$meta[$item['key']][] = (string) $item['value'];
			}
		}
		ksort($meta, SORT_STRING);
		return State::hash('m1', [
			'attachment_id' => (int) ($state['attachment_id'] ?? 0),
			'fields' => $fields,
			'meta' => $meta,
			'terms' => $state['terms'] ?? [],
			'files_valid' => (bool) ($state['files_valid'] ?? false),
			'files' => $state['files'] ?? [],
		]);
	}

	// ------------------------------------------------------------------ forward

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		$op = (string) ($intent['op'] ?? '');
		if ($op === 'create') {
			return $this->upload($intent, $auth, $operationId);
		}
		$current = $this->capture($id, true);
		if ($current === null) {
			throw new MutationFailure('object_not_found', esc_html__('The media item was not found.', 'llamly'), 404);
		}
		if ($op === 'delete') {
			$this->remove($id, $current, $operationId, true, false, false);
			return $id;
		}
		if ($op !== 'update') {
			throw new MutationFailure('invalid_arguments', esc_html__('Unknown media operation.', 'llamly'), 400);
		}
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($fields) {
			$updated = wp_update_post(wp_slash(['ID' => $id] + $fields), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
			if ((int) $updated !== $id) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not update the media item.', 'llamly'), 500);
			}
		}
		if (array_key_exists('alt', $intent)) {
			$alt = (string) $intent['alt'];
			delete_post_meta($id, '_wp_attachment_image_alt');
			if ($alt !== '' && !add_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt))) {
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not store the alternative text.', 'llamly'), 500);
			}
		}
		clean_post_cache($id);
		return $id;
	}

	/** @param array<string, mixed> $intent @param array<string, mixed> $auth */
	private function upload(array $intent, array $auth, string $operationId): int
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$userId = (int) ($auth['user_id'] ?? 0);
		$staged = (string) ($auth['media_staged_file'] ?? '');
		$sha = (string) ($intent['sha256'] ?? '');
		if ($staged === '' || !is_file($staged) || FileRetention::sha($staged) !== $sha || (int) filesize($staged) !== (int) ($intent['size'] ?? -1)) {
			throw new MutationFailure('upload_unavailable', esc_html__('The uploaded data is no longer available. Send the upload again.', 'llamly'), 409);
		}
		$name = (string) ($intent['filename'] ?? '');
		$tmp = wp_tempnam($name);
		if (!$tmp || !@copy($staged, $tmp)) {
			throw new MutationFailure('upload_failed', esc_html__('The upload could not be prepared.', 'llamly'), 503);
		}
		// Defence in depth: the executor validated the bytes, but the copy the sideload consumes is checked again.
		$check = self::validateUpload($tmp, $name, $userId);
		if (is_wp_error($check) || $check['type'] !== (string) ($intent['mime'] ?? '') || $check['name'] !== $name) {
			wp_delete_file($tmp);
			throw new MutationFailure('invalid_file_type', esc_html(is_wp_error($check) ? $check->get_error_message() : __('The file type changed during upload.', 'llamly')), 400);
		}
		$file = ['name' => $name, 'type' => $check['type'], 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) filesize($tmp)];
		$moved = wp_handle_sideload($file, ['test_form' => false, 'test_size' => true, 'mimes' => self::allowedMimes($userId)]);
		if (!is_array($moved) || isset($moved['error']) || empty($moved['file'])) {
			wp_delete_file($tmp);
			throw new MutationFailure('upload_rejected', esc_html(is_array($moved) && isset($moved['error']) ? Redactor::text((string) $moved['error']) : __('WordPress rejected the upload.', 'llamly')), 400);
		}
		FileRetention::createdInTransaction([(string) $moved['file']]);
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$attachment = [
			'post_mime_type' => (string) $moved['type'],
			'guid' => (string) $moved['url'],
			'post_title' => (string) ($fields['post_title'] ?? ''),
			'post_content' => (string) ($fields['post_content'] ?? ''),
			'post_excerpt' => (string) ($fields['post_excerpt'] ?? ''),
			'post_status' => 'inherit',
			'post_author' => $userId,
			'meta_input' => [self::MARKER => $operationId],
		];
		$id = wp_insert_attachment(wp_slash($attachment), (string) $moved['file'], (int) ($fields['post_parent'] ?? 0), true);
		if (is_wp_error($id) || (int) $id < 1) {
			throw new MutationFailure('mutation_failed', esc_html(is_wp_error($id) ? $id->get_error_message() : __('WordPress did not create the media item.', 'llamly')), 500);
		}
		$id = (int) $id;
		$metadata = wp_generate_attachment_metadata($id, (string) $moved['file']);
		if (is_array($metadata) && $metadata) {
			wp_update_attachment_metadata($id, $metadata);
		}
		$after = $this->capture($id);
		if ($after === null || !$after['files_valid']) {
			throw new MutationFailure('mutation_failed', esc_html__('The uploaded media item could not be read back.', 'llamly'), 500);
		}
		$created = [];
		$paths = [];
		foreach ($after['files'] as $file) {
			$path = FileRetention::absolute($file['rel']);
			if ($path !== null) {
				$paths[] = $path;
			}
			$created[] = ['rel' => $file['rel'], 'size' => $file['size'], 'sha256' => (string) ($path !== null ? FileRetention::sha($path) : '')];
		}
		FileRetention::createdInTransaction($paths);
		self::$created[$id] = $created;
		$alt = (string) ($intent['alt'] ?? '');
		if ($alt !== '' && !add_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt))) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not store the alternative text.', 'llamly'), 500);
		}
		clean_post_cache($id);
		return $id;
	}

	/**
	 * Remove an attachment: checks, retention, database delete with the file unlinks suppressed, references cleared,
	 * originals unlinked only after commit.
	 *
	 * @param array<string, mixed> $current
	 */
	private function remove(int $id, array $current, string $operationId, bool $retain, bool $undoOfUpload, bool $inverse): void
	{
		global $wpdb;
		$conflict = $inverse ? 'undo_conflict' : 'media_in_use';
		$comments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id));
		$children = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type <> 'revision'", $id));
		if ($comments > 0 || $children > 0) {
			throw new MutationFailure(esc_html($conflict), esc_html__('Comments or child items hang from this media item, so removing it would lose them.', 'llamly'), 409);
		}
		if (!$current['files_valid']) {
			throw new MutationFailure('media_files_unreadable', esc_html__('A file path of this media item is outside the uploads directory, so it is not handled by Llamly.', 'llamly'), 409);
		}
		$rels = array_column($current['files'], 'rel');
		foreach ($current['files'] as $file) {
			if ((int) $file['size'] < 0) {
				throw new MutationFailure('media_files_unreadable', esc_html__('A file of this media item is missing or unreadable, so it cannot be kept for undo. Nothing was deleted.', 'llamly'), 409);
			}
		}
		if ($rels) {
			$shared = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id <> %d AND meta_value IN (" . implode(',', array_fill(0, count($rels), '%s')) . ')',
				array_merge([$id], $rels)
			));
			if ($shared > 0) {
				throw new MutationFailure(esc_html($conflict), esc_html__('Another media item uses the same file, so it cannot be removed safely.', 'llamly'), 409);
			}
		}
		if ($undoOfUpload) {
			if (self::hasReferences($current['references']) || self::contentUses($id, $current, false) > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('The uploaded media item is now used by content, widgets, the site icon, logo, header or background, so the upload cannot be undone.', 'llamly'), 409);
			}
		}

		$shas = [];
		if ($retain) {
			$entry = FileRetention::retain($operationId, $id, $rels);
			foreach ($entry['files'] as $file) {
				$shas[$file['rel']] = $file['sha256'];
			}
		}
		// Cleared explicitly (WordPress only does it for the active theme, through _delete_attachment_theme_mod), so the
		// journaled references are exactly what the inverse puts back.
		$refs = self::normalizeReferences($current['references'], $id);
		if ($refs['site_icon'] || $refs['site_logo'] || $refs['theme_mods'] !== null) {
			self::$optionsDirty[$id] = true;
		}
		if ($refs['site_icon']) {
			// Core options: Site Icon and Site Logo (the attachment stops being referenced when it is deleted).
			update_option('site_icon', '0');
		}
		if ($refs['site_logo']) {
			delete_option('site_logo');
		}
		if ($refs['theme_mods'] !== null) {
			self::writeThemeMods($refs['theme_mods']['stylesheet'], array_fill_keys(array_keys($refs['theme_mods']['values']), null));
		}
		$keep = static function () {
			return '';
		};
		add_filter('wp_delete_file', $keep, PHP_INT_MAX);
		try {
			$deleted = wp_delete_attachment($id, true);
		} finally {
			remove_filter('wp_delete_file', $keep, PHP_INT_MAX);
		}
		$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id));
		if (!$deleted || $left > 0) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not delete the media item.', 'llamly'), 500);
		}
		$files = [];
		foreach ($rels as $rel) {
			$path = FileRetention::absolute($rel);
			if ($path !== null) {
				$files[] = ['path' => $path, 'rel' => $rel, 'sha256' => $shas[$rel] ?? null];
			}
		}
		FileRetention::deleteAfterCommit($files);
		clean_post_cache($id);
	}

	// ------------------------------------------------------------------ inverse

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		global $wpdb;
		$operation = Engine::operation($operationId);
		$undoOf = is_array($operation) ? (string) ($operation['undo_of'] ?? '') : '';
		$redoOf = is_array($operation) ? (string) ($operation['redo_of'] ?? '') : '';
		if ($target === null) {
			if ($current === null) {
				return $id;
			}
			$undoOfUpload = false;
			if ($undoOf !== '') {
				$original = Engine::operation($undoOf);
				$undoOfUpload = is_array($original) && in_array((string) $original['tool'], ['wp_upload_media', 'wp_upload_media_finish'], true);
				if ($undoOfUpload) {
					$this->assertCreatedFiles($original, $current);
				}
			}
			// A redo closes the undo cycle (it cannot be undone again), so nothing needs to be retained for it.
			$this->remove($id, $current, $operationId, $redoOf === '', $undoOfUpload, true);
			return $id;
		}

		if ($current === null) {
			$source = $undoOf !== '' ? $undoOf : $redoOf;
			$entry = $source !== '' ? FileRetention::entry($source) : null;
			if ($entry === null || (int) $entry['attachment_id'] !== $id) {
				throw new MutationFailure('undo_unavailable', esc_html__('The retained files of this media item are no longer available, so it cannot be restored.', 'llamly'), 409);
			}
			$taken = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id));
			if ($taken > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('Another item now uses the original identifier, so it cannot be restored.', 'llamly'), 409);
			}
			$this->assertReferencesFree($id, (array) $target['references']);
			FileRetention::restoreFiles($entry, (array) $target['files']);
			RawRows::reinsert($wpdb->posts, 'ID', (array) $target['row']);
			RawRows::restoreMeta($wpdb->postmeta, 'post_id', $id, (array) $target['meta'], null, self::metaExclude(), 'post_meta');
			$this->restoreTerms($id, (array) $target['terms']);
			$this->relink($id, (array) $target['references']);
			FileRetention::release($source);
			clean_post_cache($id);
			return $id;
		}

		if ($current['files'] !== $target['files'] || $current['files_valid'] !== $target['files_valid']) {
			throw new MutationFailure('undo_conflict', esc_html__('The files of this media item changed, so its details cannot be restored.', 'llamly'), 409);
		}
		$row = (array) $target['row'];
		unset($row['ID']);
		if ($wpdb->update($wpdb->posts, $row, ['ID' => $id]) === false) {
			throw new MutationFailure('journal_unavailable', esc_html__('The media row could not be restored.', 'llamly'), 503);
		}
		RawRows::restoreMeta($wpdb->postmeta, 'post_id', $id, (array) $target['meta'], null, self::metaExclude(), 'post_meta');
		$this->restoreTerms($id, (array) $target['terms']);
		clean_post_cache($id);
		return $id;
	}

	/** The undo of an upload removes only what that upload created. @param array<string, mixed> $original @param array<string, mixed> $current */
	private function assertCreatedFiles(array $original, array $current): void
	{
		$result = json_decode((string) ($original['result_data'] ?? ''), true);
		$created = is_array($result) ? (array) ($result['artifacts']['created_files'] ?? []) : [];
		$createdRels = array_column($created, 'rel');
		foreach ($current['files'] as $file) {
			if (!in_array($file['rel'], $createdRels, true)) {
				throw new MutationFailure('undo_conflict', esc_html__('The media item now has files the upload did not create, so the upload cannot be undone.', 'llamly'), 409);
			}
		}
	}

	/** @param array<string, mixed> $refs */
	private function assertReferencesFree(int $id, array $refs): void
	{
		global $wpdb;
		$refs = self::normalizeReferences($refs, $id);
		foreach ((array) ($refs['thumbnail_post_ids'] ?? []) as $postId) {
			$now = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id' LIMIT 1", (int) $postId));
			if ($now !== null && (int) $now !== $id && (int) $now > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('A post that used this media item as featured image now has another featured image.', 'llamly'), 409);
			}
		}
		if (!empty($refs['site_icon'])) {
			$icon = (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'site_icon'");
			if ($icon > 0 && $icon !== $id) {
				throw new MutationFailure('undo_conflict', esc_html__('The site icon was changed after this media item was removed.', 'llamly'), 409);
			}
		}
		if ($refs['site_logo']) {
			$logo = (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'site_logo'");
			if ($logo > 0 && $logo !== $id) {
				throw new MutationFailure('undo_conflict', esc_html__('The site logo was changed after this media item was removed.', 'llamly'), 409);
			}
		}
		if ($refs['theme_mods'] !== null) {
			$mods = self::readThemeMods($refs['theme_mods']['stylesheet']);
			foreach ($refs['theme_mods']['values'] as $key => $serialized) {
				if (is_array($mods) && array_key_exists($key, $mods) && $mods[$key] !== '' && $mods[$key] !== 0 && $mods[$key] !== false && serialize($mods[$key]) !== $serialized) {
					throw new MutationFailure('undo_conflict', esc_html__('The logo, header or background image of the theme was changed after this media item was removed.', 'llamly'), 409);
				}
			}
		}
	}

	/** @param array<string, mixed> $refs */
	private function relink(int $id, array $refs): void
	{
		global $wpdb;
		$refs = self::normalizeReferences($refs, $id);
		foreach ((array) ($refs['thumbnail_post_ids'] ?? []) as $postId) {
			$postId = (int) $postId;
			$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $postId));
			if ($exists < 1) {
				continue;
			}
			$wpdb->delete($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => '_thumbnail_id'], ['%d', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
			if ($wpdb->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $id], ['%d', '%s', '%s']) !== 1) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('A featured image link could not be restored.', 'llamly'), 503);
			}
			wp_cache_delete($postId, 'post_meta');
		}
		if (!empty($refs['site_icon']) || $refs['site_logo'] || $refs['theme_mods'] !== null) {
			self::$optionsDirty[$id] = true;
		}
		if (!empty($refs['site_icon'])) {
			// Core options: Site Icon and Site Logo (undo puts the journaled references back).
			update_option('site_icon', (string) $id);
		}
		if ($refs['site_logo']) {
			update_option('site_logo', $id);
		}
		if ($refs['theme_mods'] !== null) {
			$values = [];
			foreach ($refs['theme_mods']['values'] as $key => $serialized) {
				$value = @unserialize((string) $serialized, ['allowed_classes' => ['stdClass']]);
				if ($value === false && $serialized !== serialize(false)) {
					throw new MutationFailure('journal_unavailable', esc_html__('A journaled theme setting could not be read.', 'llamly'), 503);
				}
				$values[$key] = $value;
			}
			self::writeThemeMods($refs['theme_mods']['stylesheet'], $values);
		}
	}

	/** @param array<string, list<int>> $terms */
	private function restoreTerms(int $id, array $terms): void
	{
		global $wpdb;
		$wanted = [];
		foreach ($terms as $taxonomy => $termIds) {
			foreach ((array) $termIds as $termId) {
				$ttId = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id = %d", (string) $taxonomy, (int) $termId));
				if ($ttId < 1) {
					throw new MutationFailure('undo_conflict', esc_html__('A term used by this media item no longer exists.', 'llamly'), 409);
				}
				$wanted[] = $ttId;
			}
		}
		$existing = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d FOR UPDATE", $id)));
		$removed = array_values(array_diff($existing, $wanted));
		$added = array_values(array_diff($wanted, $existing));
		foreach ($removed as $ttId) {
			if ($wpdb->delete($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId], ['%d', '%d']) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('Media terms could not be restored.', 'llamly'), 503);
			}
		}
		foreach ($added as $ttId) {
			if ($wpdb->insert($wpdb->term_relationships, ['object_id' => $id, 'term_taxonomy_id' => $ttId, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
				throw new MutationFailure('journal_unavailable', esc_html__('Media terms could not be restored.', 'llamly'), 503);
			}
		}
		foreach (array_merge($removed, $added) as $ttId) {
			$taxonomy = (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $ttId));
			if ($taxonomy !== '') {
				wp_update_term_count_now([$ttId], $taxonomy);
			}
		}
		clean_object_term_cache($id, 'attachment');
	}

	// ------------------------------------------------------------------ policy

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'create') {
			if ((string) $current['row']['post_mime_type'] !== (string) ($intent['mime'] ?? '') || !$current['files_valid'] || !$current['files']) {
				return false;
			}
			foreach ($fields as $key => $value) {
				if ((string) ($current['row'][$key] ?? '') !== (string) $value) {
					return false;
				}
			}
			$meta = self::unserializeMeta(self::metaValue($current['meta'], '_wp_attachment_metadata'));
			$attached = self::metaValue($current['meta'], '_wp_attached_file');
			$original = is_array($meta) && !empty($meta['original_image']) ? (dirname($attached) === '.' ? '' : dirname($attached) . '/') . wp_basename((string) $meta['original_image']) : $attached;
			$path = FileRetention::absolute($original);
			return $path !== null && FileRetention::sha($path) === (string) ($intent['sha256'] ?? '')
				&& self::metaValue($current['meta'], '_wp_attachment_image_alt') === (string) ($intent['alt'] ?? '');
		}
		if ($op !== 'update' || $before === null) {
			return false;
		}
		foreach ($fields as $key => $value) {
			$now = $current['row'][$key] ?? null;
			if ($now === null || (string) $now !== (string) $value) {
				return false;
			}
		}
		if (array_key_exists('alt', $intent) && self::metaValue($current['meta'], '_wp_attachment_image_alt') !== (string) $intent['alt']) {
			return false;
		}
		$strip = static function (array $state) use ($fields, $intent): array {
			foreach (array_merge(array_keys($fields), ['post_modified', 'post_modified_gmt']) as $key) {
				unset($state['row'][$key]);
			}
			if (array_key_exists('alt', $intent)) {
				$state['meta'] = array_values(array_filter($state['meta'], static fn(array $m): bool => $m['key'] !== '_wp_attachment_image_alt'));
			}
			return $state;
		};
		return $this->fingerprint($strip($before)) === $this->fingerprint($strip($current));
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$public = false;
		if (in_array($op, ['undo', 'redo'], true)) {
			foreach ([$before, $after] as $state) {
				$public = $public || ($state !== null && self::usedPublicly($state));
			}
			$destructive = $after === null && $before !== null;
		} else {
			$public = $before !== null && self::usedPublicly($before);
			$parent = (int) ($intent['fields']['post_parent'] ?? 0);
			$public = $public || ($parent > 0 && self::isPublished($parent));
			$destructive = $op === 'delete';
		}
		return [
			'destructive' => $destructive,
			'public' => $public,
			'sensitive' => false,
			'external_effects' => $public ? ['CDN, page and image caches and third-party copies of the file are not undone.'] : [],
		];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		$post = $id > 0 ? get_post($id) : null;
		if ($post instanceof \WP_Post && $post->post_type === 'attachment') {
			$removes = $after === null || $before === null;
			return user_can($userId, 'edit_post', $id) && (!$removes || user_can($userId, 'delete_post', $id));
		}
		$state = $after ?? $before;
		$author = (int) ($state['row']['post_author'] ?? 0);
		return user_can($userId, 'upload_files') && ($author === $userId || user_can($userId, 'edit_others_posts'));
	}

	public function label(?array $state): string
	{
		$title = trim((string) ($state['row']['post_title'] ?? ''));
		if ($title !== '') {
			return $title;
		}
		$file = self::metaValue((array) ($state['meta'] ?? []), '_wp_attached_file');
		return $file !== '' ? wp_basename($file) : __('(untitled)', 'llamly');
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
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'attachment' AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 2",
			self::MARKER,
			$operationId
		));
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		delete_post_meta($id, self::MARKER, $operationId);
	}

	/** Called by the engine after the final cache pass for this attachment. */
	public function forgetRequestState(int $id): void
	{
		unset(self::$optionsDirty[$id]);
	}

	public function flushCaches(int $id): void
	{
		global $wpdb;
		PatternObject::flushPostCaches($id);
		// The options cache is only dropped when the write changed the site icon, logo or theme mods.
		if (!empty(self::$optionsDirty[$id])) {
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
			wp_cache_delete('site_icon', 'options');
			wp_cache_delete('site_logo', 'options');
			$stylesheet = (string) get_option('stylesheet');
			if ($stylesheet !== '') {
				wp_cache_delete('theme_mods_' . $stylesheet, 'options');
			}
		}
		$posts = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 200", (string) $id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		foreach ($posts as $postId) {
			wp_cache_delete($postId, 'post_meta');
		}
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$result = ['attachment_id' => $id];
		$op = (string) ($intent['op'] ?? '');
		if ($after !== null) {
			$result['url'] = (string) wp_get_attachment_url($id);
			$result['mime_type'] = (string) $after['row']['post_mime_type'];
		}
		if ($op === 'create' && $after !== null) {
			$created = self::$created[$id] ?? null;
			if ($created === null) {
				$created = [];
				foreach ($after['files'] as $file) {
					$path = FileRetention::absolute($file['rel']);
					$created[] = ['rel' => $file['rel'], 'size' => $file['size'], 'sha256' => (string) ($path !== null ? FileRetention::sha($path) : '')];
				}
			}
			$result['artifacts'] = ['created_files' => $created];
			$result['generated_sizes'] = max(0, count($created) - 1);
		} elseif ($op === 'update') {
			$names = ['post_title' => 'title', 'post_excerpt' => 'caption', 'post_content' => 'description', 'post_parent' => 'post_parent'];
			$changed = [];
			foreach (array_keys((array) ($intent['fields'] ?? [])) as $field) {
				$changed[] = $names[$field] ?? $field;
			}
			if (array_key_exists('alt', $intent)) {
				$changed[] = 'alt_text';
			}
			$result['changed_fields'] = $changed;
		} elseif ($op === 'delete') {
			$result['deleted'] = true;
			$result['retained_files'] = $before !== null ? count($before['files']) : 0;
		}
		return $result;
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Validate an upload on disk: name rules, denied extensions on every part of the name, the real type through
	 * wp_check_filetype_and_ext() against the user's allowed types, a markup sniff for non-images and the size limit.
	 *
	 * @return array{name:string,ext:string,type:string,size:int,sha256:string}|\WP_Error
	 */
	public static function validateUpload(string $path, string $filename, int $userId): array|\WP_Error
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$invalid = static fn(string $message): \WP_Error => new \WP_Error('invalid_file_type', $message, ['status' => 400]);
		$refused = self::refusedName($filename);
		if ($refused !== null) {
			return $invalid($refused);
		}
		$name = sanitize_file_name($filename);
		$size = is_file($path) ? (int) filesize($path) : 0;
		if ($size < 1) {
			return $invalid(__('The file is empty.', 'llamly'));
		}
		if ($size > (int) wp_max_upload_size()) {
			return new \WP_Error('file_too_large', sprintf(/* translators: %s: maximum upload size, formatted. */ __('The file is larger than the upload limit of %s.', 'llamly'), size_format(wp_max_upload_size())), ['status' => 413]);
		}
		$check = wp_check_filetype_and_ext($path, $name, self::allowedMimes($userId));
		if (!empty($check['proper_filename'])) {
			$name = (string) $check['proper_filename'];
			$denied = self::deniedName($name);
			if ($denied !== null) {
				return $invalid($denied);
			}
		}
		$ext = strtolower((string) ($check['ext'] ?? ''));
		$type = strtolower((string) ($check['type'] ?? ''));
		if ($ext === '' || $type === '' || in_array($ext, self::DENIED_EXTENSIONS, true) || self::isDeniedMime($type)) {
			return $invalid(__('This file type is not allowed or its content does not match its extension.', 'llamly'));
		}
		if (strpos($type, 'image/') !== 0) {
			$head = (string) file_get_contents($path, false, null, 0, 4096);
			if (preg_match('/<\?php|<\?=|<script|<html|<svg|<!doctype\s+html/i', $head)) {
				return $invalid(__('The file contains markup or code and is not accepted as media.', 'llamly'));
			}
		}
		return ['name' => $name, 'ext' => $ext, 'type' => $type, 'size' => $size, 'sha256' => (string) FileRetention::sha($path)];
	}

	/** Whether a MIME type is refused: the exact list, any other `+xml` type, and `application/x-httpd-php*`; Office and OpenDocument types are not. */
	public static function isDeniedMime(string $type): bool
	{
		$type = strtolower(trim((string) preg_replace('/\s*;.*$/', '', $type)));
		if ($type === '') {
			return false;
		}
		if (in_array($type, self::DENIED_MIME_TYPES, true) || str_starts_with($type, 'application/x-httpd-php')) {
			return true;
		}
		return str_ends_with($type, '+xml') && !preg_match(self::ALLOWED_XML_FAMILY, $type);
	}

	/**
	 * Why a file name is refused before any byte is read (folders, hidden or server files, a denied extension also as a
	 * double extension, before or after sanitize_file_name), or null. Used by validateUpload and by the upload start.
	 */
	public static function refusedName(string $filename): ?string
	{
		if (!preg_match('/^[^\/\\\\\x00-\x1f]{1,200}$/', $filename) || $filename !== wp_basename($filename)) {
			return __('Use a plain file name of 1 to 200 characters without folders.', 'llamly');
		}
		return self::deniedName($filename) ?? self::deniedName(sanitize_file_name($filename));
	}

	private static function deniedName(string $name): ?string
	{
		$lower = strtolower(trim($name));
		if ($lower === '' || $lower[0] === '.' || in_array($lower, self::DENIED_NAMES, true)) {
			return __('Hidden files and server configuration files are not accepted.', 'llamly');
		}
		$parts = explode('.', $lower);
		if (count($parts) < 2) {
			return __('The file name needs an extension.', 'llamly');
		}
		foreach (array_slice($parts, 1) as $part) {
			if (in_array(trim($part), self::DENIED_EXTENSIONS, true)) {
				return __('Executable, script, markup and SVG files (also as a double extension) are not accepted.', 'llamly');
			}
		}
		return null;
	}

	/** @return array<string, string> upload_mimes for the user, minus every denied extension. */
	public static function allowedMimes(int $userId): array
	{
		$mimes = get_allowed_mime_types($userId > 0 ? $userId : null);
		$out = [];
		foreach ($mimes as $exts => $type) {
			$keep = array_values(array_filter(explode('|', strtolower((string) $exts)), static fn(string $e): bool => !in_array($e, self::DENIED_EXTENSIONS, true)));
			if ($keep && !self::isDeniedMime((string) $type)) {
				$out[implode('|', $keep)] = (string) $type;
			}
		}
		return $out;
	}

	/** Whether the attachment belongs to, or is used by, published content (or the site icon/logo). @param array<string, mixed> $state */
	public static function usedPublicly(array $state): bool
	{
		global $wpdb;
		$id = (int) ($state['attachment_id'] ?? 0);
		$parent = (int) ($state['row']['post_parent'] ?? 0);
		if ($parent > 0 && self::isPublished($parent)) {
			return true;
		}
		$refs = self::normalizeReferences((array) ($state['references'] ?? []), $id);
		if ($refs['site_icon'] || $refs['site_logo'] || $refs['theme_mods'] !== null) {
			return true;
		}
		$thumbs = array_map('intval', (array) ($refs['thumbnail_post_ids'] ?? []));
		if ($thumbs) {
			$published = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (" . implode(',', array_fill(0, count($thumbs), '%d')) . ") AND post_status IN ('publish','future','private')", $thumbs));
			if ($published > 0) {
				return true;
			}
		}
		return self::contentUses($id, $state, true) > 0;
	}

	/**
	 * Places that reference the attachment by ID or by file path outside its tracked references: post content, Elementor
	 * data of those posts, widgets (widget_* options, classic and block) and theme mods of any theme.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function contentUses(int $id, array $state, bool $publishedOnly): int
	{
		global $wpdb;
		if ($id < 1) {
			return 0;
		}
		$idRegex = 'wp-image-' . $id . '([^0-9]|$)|"id":"?' . $id . '([^0-9]|$)|attachment_id";i:' . $id . ';|"attachment_id":"?' . $id . '([^0-9]|$)|custom_logo";i:' . $id . ';';
		$attached = self::metaValue((array) ($state['meta'] ?? []), '_wp_attached_file');
		$stem = $attached !== '' ? (string) preg_replace('/(-scaled)?\.[A-Za-z0-9]+$/', '', $attached) : '';
		if (strlen($stem) >= 3) {
			// The file path counts only when followed by its extension or a suffix of the same file (size, -scaled, -rotated,
			// an edit): "2026/10/hero" must not match another upload named "2026/10/hero-1.jpg" (LLP-433).
			$path = '(' . preg_quote($stem) . '|' . preg_quote(str_replace('/', '\/', $stem)) . ')(-[0-9]+x[0-9]+|-scaled|-rotated|-e[0-9]{10,})*[.][A-Za-z0-9]+';
			$idRegex .= '|' . $path;
		}
		// Posts other than revisions, attachments and menu items; published ones only when $publishedOnly.
		$count = $publishedOnly
			? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type NOT IN ('revision','attachment','nav_menu_item') AND p.post_status IN ('publish','future','private') AND (p.post_content REGEXP %s)", $idRegex))
			: (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type NOT IN ('revision','attachment','nav_menu_item') AND p.post_status NOT IN ('auto-draft','inherit') AND (p.post_content REGEXP %s)", $idRegex));
		$count += $publishedOnly
			? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_type NOT IN ('revision','attachment','nav_menu_item') AND p.post_status IN ('publish','future','private') AND (m.meta_value REGEXP %s)", $idRegex))
			: (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_type NOT IN ('revision','attachment','nav_menu_item') AND p.post_status NOT IN ('auto-draft','inherit') AND (m.meta_value REGEXP %s)", $idRegex));
		// Widgets and theme mods are site-wide, so any reference counts as public use.
		$count += (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND (option_value REGEXP %s)",
			$wpdb->esc_like('widget_') . '%',
			$wpdb->esc_like('theme_mods_') . '%',
			$idRegex
		));
		return $count;
	}

	private static function isPublished(int $postId): bool
	{
		global $wpdb;
		$status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $postId));
		return in_array((string) $status, self::PUBLIC_STATUSES, true);
	}

	/** Theme mods WordPress clears when the attachment is deleted (_delete_attachment_theme_mod). */
	private const THEME_MOD_KEYS = ['custom_logo', 'header_image', 'header_image_data', 'background_image'];

	/**
	 * @return array{thumbnail_post_ids:list<int>,site_icon:bool,site_logo:bool,theme_mods:?array{stylesheet:string,values:array<string,string>}}|null
	 *         theme_mods.values holds the serialized value of each mod of the active theme that points at the attachment.
	 */
	private static function references(int $id, string $attached): ?array
	{
		global $wpdb;
		$thumbs = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s ORDER BY post_id ASC", (string) $id));
		if (!is_array($thumbs) || $wpdb->last_error !== '') {
			return null;
		}
		$icon = (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'site_icon'");
		$siteLogo = (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'site_logo'");
		$stylesheet = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'stylesheet'");
		$themeMods = null;
		$mods = $stylesheet !== '' ? self::readThemeMods($stylesheet) : null;
		if (is_array($mods)) {
			$suffix = $attached !== '' ? '/' . ltrim($attached, '/') : '';
			$isUrl = static fn($value): bool => $suffix !== '' && is_string($value) && $value !== '' && substr($value, -strlen($suffix)) === $suffix;
			$values = [];
			if ((int) ($mods['custom_logo'] ?? 0) === $id) {
				$values['custom_logo'] = serialize($mods['custom_logo']);
			}
			$data = $mods['header_image_data'] ?? null;
			$dataId = is_object($data) ? (int) ($data->attachment_id ?? 0) : (is_array($data) ? (int) ($data['attachment_id'] ?? 0) : 0);
			$dataUrl = is_object($data) ? ($data->url ?? null) : (is_array($data) ? ($data['url'] ?? null) : null);
			if ($isUrl($mods['header_image'] ?? null) || $dataId === $id || $isUrl($dataUrl)) {
				foreach (['header_image', 'header_image_data'] as $key) {
					if (array_key_exists($key, $mods)) {
						$values[$key] = serialize($mods[$key]);
					}
				}
			}
			if ($isUrl($mods['background_image'] ?? null)) {
				$values['background_image'] = serialize($mods['background_image']);
			}
			if ($values) {
				ksort($values, SORT_STRING);
				$themeMods = ['stylesheet' => $stylesheet, 'values' => $values];
			}
		}
		return ['thumbnail_post_ids' => array_map('intval', $thumbs), 'site_icon' => $icon === $id, 'site_logo' => $siteLogo === $id, 'theme_mods' => $themeMods];
	}

	/**
	 * Journals written before theme mods were tracked carry custom_logo = stylesheet name; read them as theme_mods.
	 *
	 * @param array<string, mixed> $refs
	 * @return array{thumbnail_post_ids:list<int>,site_icon:bool,site_logo:bool,theme_mods:?array{stylesheet:string,values:array<string,string>}}
	 */
	private static function normalizeReferences(array $refs, int $id = 0): array
	{
		$themeMods = is_array($refs['theme_mods'] ?? null) && is_string($refs['theme_mods']['stylesheet'] ?? null) && is_array($refs['theme_mods']['values'] ?? null)
			? ['stylesheet' => $refs['theme_mods']['stylesheet'], 'values' => array_intersect_key(array_map('strval', $refs['theme_mods']['values']), array_flip(self::THEME_MOD_KEYS))]
			: null;
		if ($themeMods === null && is_string($refs['custom_logo'] ?? null) && $refs['custom_logo'] !== '') {
			$themeMods = ['stylesheet' => $refs['custom_logo'], 'values' => ['custom_logo' => serialize($id)]];
		}
		if ($themeMods !== null && (!$themeMods['values'] || !preg_match('/^[A-Za-z0-9._\/-]{1,150}$/', $themeMods['stylesheet']))) {
			$themeMods = null;
		}
		return [
			'thumbnail_post_ids' => array_map('intval', (array) ($refs['thumbnail_post_ids'] ?? [])),
			'site_icon' => !empty($refs['site_icon']),
			'site_logo' => !empty($refs['site_logo']),
			'theme_mods' => $themeMods,
		];
	}

	/** @param array<string, mixed> $refs */
	private static function hasReferences(array $refs): bool
	{
		$refs = self::normalizeReferences($refs);
		return $refs['thumbnail_post_ids'] || $refs['site_icon'] || $refs['site_logo'] || $refs['theme_mods'] !== null;
	}

	/** @return array<string, mixed>|null Raw theme_mods_<stylesheet> row (no option cache); stdClass allowed for header_image_data. */
	private static function readThemeMods(string $stylesheet): ?array
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'theme_mods_' . $stylesheet));
		if (!is_string($raw) || !is_serialized($raw)) {
			return null;
		}
		$mods = @unserialize($raw, ['allowed_classes' => ['stdClass']]);
		return is_array($mods) ? $mods : null;
	}

	/** Set (or, with null, remove) theme mods of one theme, keeping every other mod as stored. @param array<string, mixed> $values */
	private static function writeThemeMods(string $stylesheet, array $values): void
	{
		$option = 'theme_mods_' . $stylesheet;
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete($option, 'options');
		$mods = self::readThemeMods($stylesheet) ?? [];
		foreach ($values as $key => $value) {
			if (!in_array($key, self::THEME_MOD_KEYS, true)) {
				continue;
			}
			if ($value === null) {
				unset($mods[$key]);
			} else {
				$mods[$key] = $value;
			}
		}
		// Core option: theme_mods_{stylesheet}, only the journaled image keys of the theme (THEME_MOD_KEYS).
		update_option($option, $mods);
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete($option, 'options');
	}

	/** @param list<array{key:string,value:string}> $meta */
	private static function metaValue(array $meta, string $key): string
	{
		foreach ($meta as $item) {
			if ($item['key'] === $key) {
				return (string) $item['value'];
			}
		}
		return '';
	}

	/** @return mixed */
	private static function unserializeMeta(string $raw)
	{
		return $raw !== '' && is_serialized($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
	}

	private static function metaExclude(): string
	{
		// Secrets never enter the journal (contract rule 2); volatile keys are rewritten by WordPress or Llamly.
		$sensitive = substr(Redactor::SENSITIVE_KEY, 1, strrpos(Redactor::SENSITIVE_KEY, '/') - 1);
		return '/^(?:_llamly_pending_operation|_edit_lock|_edit_last)$|' . $sensitive . '/i';
	}
}
