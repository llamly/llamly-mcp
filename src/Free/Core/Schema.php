<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- creates Llamly tables and drops only the retired ones ({prefix}llamly_free_*).

namespace Llamly\Free\Core;

final class Schema
{
	public const VERSION = 10;
	public const OPTION_VERSION = 'llamly_free_schema_version';

	/**
	 * Upgrade steps, one per schema version, and whether each one is destructive.
	 *
	 * Criterion: a step is ADDITIVE when it only adds (CREATE TABLE, ADD COLUMN, ADD KEY), fills NULLs, or backfills a
	 * column it just created. It is DESTRUCTIVE when it rewrites a value that already exists, changes or drops a column
	 * or key, or deletes rows. The pre-upgrade backup (<table>_bak_v<old>) is mandatory only when the path old -> VERSION
	 * contains a destructive step (see requiresBackup()); an additive-only upgrade makes no copy, so a large table does
	 * not delay it. Every new version MUST add its entry here. Every UPDATE in install() carries "result IS NULL", so
	 * re-running it never rewrites an existing value.
	 *
	 * v4 and v7 fill audit.result only where it is NULL (v7 added the "*_failed" -> error rule, still NULL-only):
	 * additive. No released step rewrites an existing value, so today no upgrade path needs a backup.
	 * v10 drops the tables of a feature that left the plugin (RETIRED_TABLES). It never touches a kept table, and the
	 * backup only protects kept tables, so it does not count as destructive here.
	 *
	 * The filter lets tests declare a future destructive step; it is read only when LLAMLY_FREE_TESTING is defined.
	 *
	 * @var array<int, array{destructive: bool, note: string}>
	 */
	public const STEPS = [
		2 => ['destructive' => false, 'note' => 'initial tables'],
		3 => ['destructive' => false, 'note' => 'permits table'],
		4 => ['destructive' => false, 'note' => 'audit per-request columns; audit.result filled where NULL'],
		5 => ['destructive' => false, 'note' => 'journal engine columns'],
		6 => ['destructive' => false, 'note' => 'generation columns'],
		7 => ['destructive' => false, 'note' => 'v7 tables (retired in v10); legacy *_failed audit rows filled where NULL'],
		8 => ['destructive' => false, 'note' => 'operations.seq'],
		9 => ['destructive' => false, 'note' => 'operations.label, listing keys, label backfill of the new column'],
		10 => ['destructive' => false, 'note' => 'retired tables, their leftovers and their settings dropped'],
	];

	/**
	 * Tables (suffixes after {prefix}llamly_free_) of the database restore points, which 1.0.0 created and this version no
	 * longer has. install() drops them, their upgrade backups and the work tables a restore left behind; uninstall (Core\Uninstall) too.
	 */
	private const RETIRED_TABLES = ['checkpoints', 'checkpoint_chunks', 'checkpoint_restores', 'checkpoint_state'];

	/** Prefixes (after {prefix}llamly_free_) of the staging and pre-swap work tables of a database restore. */
	private const RETIRED_WORK_PREFIXES = ['rs_', 'ro_'];

	/** Settings keys of the retired feature. */
	private const RETIRED_SETTINGS = ['checkpoint_limit', 'checkpoint_quota_mb'];

	/** True when upgrading from $old to VERSION passes through a destructive step. */
	public static function requiresBackup(int $old): bool
	{
		$steps = self::STEPS;
		if (defined('LLAMLY_FREE_TESTING') && LLAMLY_FREE_TESTING) {
			/** @var array<int, array{destructive: bool}> $steps */
			$steps = apply_filters('llamly_free_schema_steps', $steps);
		}
		foreach ($steps as $version => $step) {
			if ($version > $old && $version <= self::VERSION && !empty($step['destructive'])) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string, string> */
	public static function tableNames(): array
	{
		global $wpdb;
		$prefix = $wpdb->prefix . 'llamly_free_';
		return [
			'credentials' => $prefix . 'credentials',
			'oauth_clients' => $prefix . 'oauth_clients',
			'oauth_grants' => $prefix . 'oauth_grants',
			'oauth_codes' => $prefix . 'oauth_codes',
			'oauth_tokens' => $prefix . 'oauth_tokens',
			'operations' => $prefix . 'operations',
			'object_versions' => $prefix . 'object_versions',
			'permits' => $prefix . 'permits',
			'audit' => $prefix . 'audit',
			'rate_limits' => $prefix . 'rate_limits',
		];
	}

	public static function install(): bool
	{
		global $wpdb;
		$tables = self::tableNames();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$queries = self::queries($tables, $charset);
		foreach ($queries as $query) {
			dbDelta($query);
		}

		foreach ($tables as $table) {
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
			if ($found !== $table) {
				return false;
			}
			$status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
			if (!$status || strtolower((string) $status->Engine) !== 'innodb') {
				return false;
			}
		}

		foreach ($queries as $query) {
			if (!self::verifyDefinition($query)) {
				return false;
			}
		}

		// v4 added the per-request columns; give older audit rows a result so the Logs filters count them.
		$audit = $tables['audit'];
		if ($wpdb->query($wpdb->prepare("UPDATE %i SET result = 'denied' WHERE result IS NULL AND event IN ('auth_failed', 'tool_denied')", $audit)) === false
			|| $wpdb->query($wpdb->prepare("UPDATE %i SET result = 'error' WHERE result IS NULL AND event LIKE %s", $audit, '%\\_failed')) === false
			|| $wpdb->query($wpdb->prepare("UPDATE %i SET result = 'ok' WHERE result IS NULL", $audit)) === false
		) {
			return false;
		}

		if (!Settings::installDefaults() || !self::dropRetired() || !Settings::dropKeys(self::RETIRED_SETTINGS)) {
			return false;
		}
		update_option(self::OPTION_VERSION, self::VERSION, false);
		return (int) get_option(self::OPTION_VERSION, 0) === self::VERSION;
	}

	/**
	 * Drops the retired tables, their upgrade backups ({table}_bak_v*) and the restore work tables ({prefix}llamly_free_rs_*,
	 * _ro_*). Only names under {prefix}llamly_free_ that match these exact patterns; no kept table is touched.
	 */
	public static function dropRetired(): bool
	{
		global $wpdb;
		$prefix = $wpdb->prefix . 'llamly_free_';
		$patterns = [];
		foreach (self::RETIRED_TABLES as $suffix) {
			$patterns[] = $wpdb->esc_like($prefix . $suffix);
			$patterns[] = $wpdb->esc_like($prefix . $suffix . '_bak_v') . '%';
		}
		foreach (self::RETIRED_WORK_PREFIXES as $work) {
			$patterns[] = $wpdb->esc_like($prefix . $work) . '%';
		}
		$kept = array_values(self::tableNames());
		foreach ($patterns as $pattern) {
			foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern)) as $table) {
				$table = (string) $table;
				if (in_array($table, $kept, true)) {
					continue;
				}
				if ($wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)) === false) {
					return false;
				}
			}
		}
		return true;
	}

	/** Verifies every declared column and every declared key (PRIMARY, UNIQUE and plain) of one CREATE TABLE statement against the live table. */
	private static function verifyDefinition(string $query): bool
	{
		global $wpdb;
		if (!preg_match('/^CREATE TABLE\s+(\S+)\s*\((.*)\)\s*ENGINE=/s', trim($query), $match)) {
			return false;
		}
		$table = $match[1];

		$columns = [];
		$keys = [];
		foreach (preg_split('/\R/', $match[2]) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)/i', $line, $key)) {
				$keys['PRIMARY'] = self::splitColumns($key[1]);
			} elseif (preg_match('/^(?:UNIQUE\s+)?KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $key)) {
				$keys[$key[1]] = self::splitColumns($key[2]);
			} elseif (preg_match('/^(\w+)\s/', $line, $column)) {
				$columns[] = $column[1];
			}
		}

		$liveColumns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 0);
		if (!is_array($liveColumns) || $wpdb->last_error !== '') {
			return false;
		}
		$liveColumns = array_map('strtolower', array_map('strval', $liveColumns));
		foreach ($columns as $column) {
			if (!in_array(strtolower($column), $liveColumns, true)) {
				return false;
			}
		}

		$rows = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return false;
		}
		$live = [];
		foreach ($rows as $row) {
			$live[(string) $row['Key_name']][(int) $row['Seq_in_index']] = strtolower((string) $row['Column_name']);
		}
		foreach ($keys as $name => $keyColumns) {
			if (!isset($live[$name])) {
				return false;
			}
			ksort($live[$name]);
			if (array_values($live[$name]) !== $keyColumns) {
				return false;
			}
		}
		return true;
	}

	/** @return list<string> */
	private static function splitColumns(string $list): array
	{
		return array_map(static fn (string $column): string => strtolower(trim($column)), explode(',', $list));
	}

	/** @param array<string, string> $tables
	 *  @return list<string>
	 */
	private static function queries(array $tables, string $charset): array
	{
		return [
			"CREATE TABLE {$tables['credentials']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				kind varchar(24) NOT NULL,
				label varchar(120) NOT NULL,
				prefix varchar(24) NOT NULL,
				secret_hash char(64) NOT NULL,
				scope_mode varchar(16) NOT NULL,
				allowed_tools longtext NOT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY prefix (prefix),
				KEY user_status (user_id, revoked_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['oauth_clients']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(96) NOT NULL,
				client_secret_hash char(64) NULL,
				user_id bigint(20) unsigned NULL,
				name varchar(160) NOT NULL,
				redirect_uris longtext NOT NULL,
				created_at datetime NOT NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id),
				KEY user_revoked (user_id, revoked_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['oauth_codes']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code_hash char(64) NOT NULL,
				grant_id char(36) NOT NULL,
				client_id varchar(96) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				redirect_uri text NOT NULL,
				code_challenge char(128) NOT NULL,
				resource varchar(255) NOT NULL,
				scopes longtext NOT NULL,
				state_value varchar(512) NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code_hash (code_hash),
				KEY expiry (expires_at),
				KEY client_user (client_id, user_id)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['oauth_grants']} (
				id char(36) NOT NULL,
				client_id varchar(96) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				resource varchar(255) NOT NULL,
				scopes longtext NOT NULL,
				created_at datetime NOT NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				KEY subject (client_id, user_id),
				KEY user_revoked (user_id, revoked_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['oauth_tokens']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				token_type varchar(16) NOT NULL,
				grant_id char(36) NOT NULL,
				family_id char(36) NOT NULL,
				parent_id bigint(20) unsigned NULL,
				client_id varchar(96) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				resource varchar(255) NOT NULL,
				scopes longtext NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				last_used_at datetime NULL,
				consumed_at datetime NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY family (family_id),
				KEY subject (client_id, user_id, token_type),
				KEY expiry (expires_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['operations']} (
				id char(36) NOT NULL,
				blog_id bigint(20) unsigned NOT NULL,
				credential_id bigint(20) unsigned NULL,
				credential_key varchar(191) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				idempotency_key varchar(128) NOT NULL,
				request_hash char(64) NOT NULL,
				tool varchar(96) NOT NULL,
				object_type varchar(48) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL,
				before_data longtext NOT NULL,
				after_data longtext NULL,
				intended_data longtext NULL,
				result_data longtext NULL,
				error_code varchar(64) NULL,
				proof longtext NULL,
				lock_name varchar(64) NULL,
				conn_id bigint(20) unsigned NULL,
				before_hash char(68) NOT NULL,
				after_hash char(68) NULL,
				undo_of char(36) NULL,
				redo_of char(36) NULL,
				batch_id char(36) NULL,
				generation bigint(20) unsigned NOT NULL DEFAULT 0,
				seq bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				expires_at datetime NULL,
				label varchar(200) NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY credential_idempotency (credential_key, idempotency_key),
				KEY status_created (status, created_at),
				KEY credential_created (credential_key, created_at),
				KEY object_status (object_type, object_id, status),
				KEY user_created (user_id, created_at),
				KEY created_seq (created_at, seq),
				KEY batch_id (batch_id),
				KEY undo_of (undo_of),
				KEY redo_of (redo_of)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['object_versions']} (
				object_type varchar(48) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				version bigint(20) unsigned NOT NULL DEFAULT 0,
				pending_op char(36) NULL,
				last_op char(36) NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (object_type, object_id),
				KEY pending_op (pending_op)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['permits']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				permit_hash char(64) NOT NULL,
				credential_id bigint(20) unsigned NULL,
				credential_key varchar(191) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				tool varchar(96) NOT NULL,
				object_type varchar(48) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				expected_hash char(64) NOT NULL,
				issued_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime NULL,
				operation_id varchar(36) NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY permit_hash (permit_hash),
				KEY owner_expiry (credential_key, expires_at),
				KEY operation_id (operation_id)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['audit']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NULL,
				credential_id bigint(20) unsigned NULL,
				credential_key varchar(191) NULL,
				event varchar(96) NOT NULL,
				result varchar(16) NULL,
				tool varchar(64) NULL,
				duration_ms int(10) unsigned NULL,
				ip varchar(45) NULL,
				context longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_created (event, created_at),
				KEY credential_created (credential_key, created_at),
				KEY result_created (result, created_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$tables['rate_limits']} (
				subject varchar(191) NOT NULL,
				window_start datetime NOT NULL,
				hits int(10) unsigned NOT NULL,
				PRIMARY KEY  (subject),
				KEY window_start (window_start)
			) ENGINE=InnoDB {$charset};",
		];
	}
}
