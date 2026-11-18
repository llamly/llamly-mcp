<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;

/**
 * One-time move from the old approval policy (every public, sensitive or destructive agent change waited for a human)
 * to the D1 policy (approval is an optional queue, off by default). Requests that were waiting for the old policy are
 * never executed by the new one: they become `approval_superseded` and the agent must send them again.
 *
 * The flag is separate from the schema version, so it also covers sites whose schema is already current.
 */
final class PolicyMigration
{
	public const FLAG = 'llamly_free_policy_migrated';

	/** Runs at most once per site; safe to call on every write. */
	public static function maybeRun(): void
	{
		if (get_option(self::FLAG, null) !== null) {
			return;
		}
		$settings = Settings::fresh();
		if (empty($settings['_valid'])) {
			return;
		}
		// With the queue on, the old waiting requests are still valid human requests; the Engine decides on retry.
		if (empty($settings['require_approval'])) {
			global $wpdb;
			if ($wpdb->query('START TRANSACTION') === false) {
				return;
			}
			$done = self::supersedePending('policy_migration');
			if ($done === false || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
				$wpdb->query('ROLLBACK');
				return;
			}
		}
		// add_option is atomic: a concurrent run that lost the race changes nothing a second time.
		add_option(self::FLAG, gmdate('c'), '', true);
	}

	/**
	 * Mark every request waiting for human approval as superseded. The caller owns the transaction.
	 *
	 * @return int|false Number of requests cancelled, false when the table could not be written.
	 */
	public static function supersedePending(string $reason): int|false
	{
		global $wpdb;
		$table = Schema::tableNames()['operations'];
		$ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE status = 'aborted' AND error_code = 'awaiting_approval'", $table));
		if ($wpdb->last_error !== '') {
			return false;
		}
		if (!$ids) {
			return 0;
		}
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET error_code = 'approval_superseded', updated_at = %s WHERE status = 'aborted' AND error_code = 'awaiting_approval'",
			$table,
			gmdate('Y-m-d H:i:s')
		));
		if ($updated === false || $wpdb->last_error !== '') {
			return false;
		}
		$user = get_current_user_id();
		if (!AuditLog::append('approval_superseded', ['reason' => sanitize_key($reason), 'count' => count($ids), 'operation_ids' => array_slice(array_map('strval', $ids), 0, 50)], $user > 0 ? $user : null, null, $user > 0 ? 'admin:' . $user : null)) {
			return false;
		}
		return count($ids);
	}
}
