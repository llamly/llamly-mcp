<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- drops only Llamly backup tables ({prefix}llamly_free_*_bak_v*).

namespace Llamly\Free\Core;

use Llamly\Free\History\MutationRunner;

final class Maintenance
{
	public const HOOK = 'llamly_free_daily_maintenance';

	public static function registerHooks(): void
	{
		add_action(self::HOOK, [self::class, 'run']);
		self::schedule();
	}

	public static function schedule(): void
	{
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
		}
	}

	public static function run(): void
	{
		self::pruneOrphanBackups();
		if (get_option(self::OPTION_LABEL_BACKFILL, null) !== null) {
			self::backfillOperationLabels();
		}
		$settings = Settings::get();
		if (empty($settings['_valid'])) {
			return;
		}

		AuditLog::clearExpired((int) $settings['audit_retention_days']);
		// Load every domain first so their snapshot-expiry listeners (e.g. media file retention) run during the prune.
		\Llamly\Free\History\Engine::adapters();
		MutationRunner::pruneExpiredSnapshots();
		self::pruneTransient((int) $settings['journal_retention_days']);
		self::revokeStaleDoctorKeys();
		\Llamly\Free\Tools\MediaParts::sweep();
	}

	/** Bounded cleanup: at most 1000 rows per table per run. Never touches applying, recovery_required or committed operations. */
	private static function pruneTransient(int $journalDays): void
	{
		global $wpdb;
		$t = Schema::tableNames();
		$day = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
		$week = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
		$month = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
		$journal = gmdate('Y-m-d H:i:s', time() - max(1, $journalDays) * DAY_IN_SECONDS);

		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s) LIMIT 1000', $t['oauth_codes'], $day, $day));
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at < %s OR (revoked_at IS NOT NULL AND revoked_at < %s) LIMIT 1000', $t['oauth_tokens'], $week, $week));
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE revoked_at IS NOT NULL AND revoked_at < %s LIMIT 1000', $t['oauth_grants'], $month));
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE window_start < %s LIMIT 1000', $t['rate_limits'], $day));
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s) LIMIT 1000', $t['permits'], $day, $day));
		$wpdb->query($wpdb->prepare(
			"DELETE FROM %i WHERE status = 'aborted' AND updated_at < %s
			AND NOT EXISTS (SELECT 1 FROM %i v WHERE v.pending_op = %i.id) LIMIT 1000",
			$t['operations'],
			$journal,
			$t['object_versions'],
			$t['operations']
		));
	}

	/** Revokes Doctor probe keys (label set by Admin\Doctor::TEMPORARY_KEY_LABEL) that survived a crashed run. */
	public static function revokeStaleDoctorKeys(): void
	{
		global $wpdb;
		$table = Schema::tableNames()['credentials'];
		$wpdb->query($wpdb->prepare(
			"UPDATE %i SET revoked_at = %s WHERE kind = 'api_key' AND label = %s AND revoked_at IS NULL AND created_at < %s",
			$table,
			gmdate('Y-m-d H:i:s'),
			'Temporary Llamly Doctor probe',
			gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS)
		));
	}

	/**
	 * After an upgrade that made a fresh backup, drops the _bak_v<N> copies older than $old, so only the immediately
	 * previous backup stays. Called only when this upgrade created a backup. Only backups of Llamly tables are touched.
	 */
	public static function pruneBackups(int $old): void
	{
		global $wpdb;
		if ($old <= 0) {
			return;
		}
		$live = array_values(Schema::tableNames());
		$found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'llamly_free_') . '%' . $wpdb->esc_like('_bak_v') . '%'));
		foreach ((array) $found as $name) {
			if (!preg_match('/^(.+)_bak_v(\d+)$/', (string) $name, $m) || !in_array($m[1], $live, true) || (int) $m[2] >= $old) {
				continue;
			}
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $name));
		}
	}

	/** Drops half-written backup copies (<table>_bak_v<N>_tmp) left by a crashed upgrade, once they are over a day old. */
	public static function pruneOrphanBackups(): void
	{
		global $wpdb;
		$live = array_values(Schema::tableNames());
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT TABLE_NAME AS name, CREATE_TIME AS created FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
			$wpdb->esc_like($wpdb->prefix . 'llamly_free_') . '%' . $wpdb->esc_like('_bak_v') . '%' . $wpdb->esc_like('_tmp')
		), ARRAY_A);
		foreach ((array) $rows as $row) {
			$name = (string) $row['name'];
			if (!preg_match('/^(.+)_bak_v\d+_tmp$/', $name, $m) || !in_array($m[1], $live, true)) {
				continue;
			}
			$created = $row['created'] ? strtotime((string) $row['created'] . ' UTC') : false;
			if ($created !== false && $created < time() - DAY_IN_SECONDS) {
				$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $name));
			}
		}
	}

	public const OPTION_LABEL_BACKFILL = 'llamly_free_label_backfill';

	/**
	 * Fills operations.label (schema v9) for the 1,000 most recent operations, 500 rows per round. Best effort and
	 * resumable: the option holds how many of those rows are done; the daily maintenance resumes it if a request died.
	 * The titles are extracted in SQL (a few hundred characters per row), so a journal row of any size never reaches PHP.
	 * Labels are stored as English source text, never translated; the admin translates when it displays them.
	 * Older rows keep NULL. Nothing here can fail the upgrade or the MCP request.
	 */
	public static function backfillOperationLabels(int $limit = 1000, int $batch = 500): void
	{
		global $wpdb;
		$table = Schema::tableNames()['operations'];
		try {
			$done = (int) get_option(self::OPTION_LABEL_BACKFILL, 0);
			update_option(self::OPTION_LABEL_BACKFILL, (string) $done, false);
			while ($done < $limit) {
				$ids = $wpdb->get_col($wpdb->prepare(
					'SELECT id FROM %i ORDER BY created_at DESC, seq DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					min($batch, $limit - $done),
					$done
				));
				if (!is_array($ids) || $ids === []) {
					break;
				}
				// Each title comes from the after-state (or the before-state when there is none) or from the intent. JSON_VALID
				// inside CASE: a row with invalid JSON yields NULL instead of failing the whole batch (MySQL 8 errors on it).
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT id, object_type,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.fields.post_title')), 200) END AS post_title,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.fields.post_name')), 200) END AS post_name,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.row.post_title')), 200) END AS row_title,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.name')), 200) END AS name,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.row.display_name')), 200) END AS display_name,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.row.user_login')), 200) END AS user_login,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.row.comment_author')), 200) END AS author,
					CASE WHEN JSON_VALID(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, ''))) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(NULLIF(after_data, ''), '{}'), NULLIF(before_data, '')), '$.row.comment_content')), 200) END AS content,
					CASE WHEN JSON_VALID(intended_data) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(intended_data, '$.fields.post_title')), 200) END AS i_title,
					CASE WHEN JSON_VALID(intended_data) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(intended_data, '$.fields.name')), 200) END AS i_name,
					CASE WHEN JSON_VALID(intended_data) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(intended_data, '$.fields.display_name')), 200) END AS i_display,
					CASE WHEN JSON_VALID(intended_data) THEN LEFT(JSON_UNQUOTE(JSON_EXTRACT(intended_data, '$.fields.user_login')), 200) END AS i_login
					FROM %i WHERE label IS NULL AND id IN (" . implode(',', array_fill(0, count($ids), '%s')) . ')',
					array_merge([$table], $ids)
				), ARRAY_A);
				foreach (is_array($rows) ? $rows : [] as $row) {
					$label = self::operationLabel($row);
					if ($label !== null) {
						$wpdb->query($wpdb->prepare('UPDATE %i SET label = %s WHERE id = %s', $table, $label, (string) $row['id']));
					}
				}
				$done += count($ids);
				update_option(self::OPTION_LABEL_BACKFILL, (string) $done, false);
			}
			delete_option(self::OPTION_LABEL_BACKFILL);
		} catch (\Throwable $e) {
			return;
		}
	}

	/**
	 * English source text only (the same msgids the adapters translate), chosen from the SQL-extracted fields.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function operationLabel(array $row): ?string
	{
		// A request that never ran has no captured state: its journaled intent names the object instead.
		$intent = ['post_title' => 'i_title', 'name' => 'i_name', 'display_name' => 'i_display', 'user_login' => 'i_login'];
		$f = static function (string $k) use ($row, $intent): string {
			$value = trim((string) ($row[$k] ?? ''));
			return $value === '' && isset($intent[$k]) ? trim((string) ($row[$intent[$k]] ?? '')) : $value;
		};
		$type = (string) $row['object_type'];
		$label = null;
		switch ($type) {
			case 'post':
				$label = $f('post_title') !== '' ? $f('post_title') : '(untitled)';
				break;
			case 'pattern':
				$label = $f('post_title') !== '' ? $f('post_title') : '(untitled pattern)';
				break;
			case 'template':
				$label = $f('post_title') !== '' ? $f('post_title') : ($f('post_name') !== '' ? $f('post_name') : '(template)');
				break;
			case 'media':
				$label = $f('row_title') !== '' ? $f('row_title') : '(untitled)';
				break;
			case 'menu':
				$label = $f('name') !== '' ? $f('name') : '(menu)';
				break;
			case 'term':
				$label = $f('name') !== '' ? $f('name') : '(unnamed term)';
				break;
			case 'option':
				$label = $f('name') !== '' ? $f('name') : 'Site option';
				break;
			case 'global_styles':
				$label = 'Global styles';
				break;
			case 'user':
				$name = $f('display_name');
				$login = $f('user_login');
				$label = $name !== '' && $name !== $login ? $name . ' (' . $login . ')' : ($login !== '' ? $login : '(user)');
				break;
			case 'comment':
				$text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($f('content'))));
				$text = function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 60, '…') : substr($text, 0, 60);
				$label = trim(($f('author') !== '' ? $f('author') . ': ' : '') . $text);
				$label = $label !== '' ? $label : '(comment)';
				break;
			default:
				return null;
		}
		return self::shorten($label);
	}

	private static function shorten(string $text): ?string
	{
		$text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
		if ($text === '') {
			return null;
		}
		$text = Redactor::text($text);
		return function_exists('mb_strimwidth') ? mb_strimwidth($text, 0, 200, '…') : substr($text, 0, 200);
	}
}
