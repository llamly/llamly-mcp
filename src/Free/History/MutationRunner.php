<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;

final class MutationRunner
{
	private const ISOLATION_LEVELS = ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];

	/** @var array<string, callable> */
	private static array $executors = [];
	private static bool $modulesMerged = false;

	/** A database drop-in (wp-content/db.php) replaces the WordPress connection; Query Monitor's own drop-in is allowed. */
	public static function dbDropinActive(): bool
	{
		global $wpdb;
		if (!function_exists('get_dropins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return isset(get_dropins()['db.php']) && !is_a($wpdb, 'QM_DB');
	}

	public static function writeEnabled(): bool
	{
		return defined('LLAMLY_FREE_MUTATIONS_VERIFIED') && LLAMLY_FREE_MUTATIONS_VERIFIED === true;
	}

	/**
	 * Executors are the trusted base that applies effects. Only Llamly Free classes may provide one;
	 * registering a tool never grants write authority by itself.
	 */
	public static function registerExecutor(string $name, callable $executor): bool
	{
		self::bootExecutors();
		if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name) || isset(self::$executors[$name])
			|| !is_array($executor) || !is_string($executor[0] ?? null) || strpos($executor[0], 'Llamly\\Free\\') !== 0
			|| !self::isOwnCode($executor)
		) {
			return false;
		}
		self::$executors[$name] = $executor;
		return true;
	}

	private static function isOwnCode(array $executor): bool
	{
		try {
			$file = (new \ReflectionMethod((string) $executor[0], (string) ($executor[1] ?? '')))->getFileName();
		} catch (\ReflectionException $error) {
			return false;
		}
		$root = trailingslashit(wp_normalize_path(LLAMLY_FREE_ROOT)) . 'src/Free/';
		return is_string($file) && strpos(wp_normalize_path($file), $root) === 0;
	}

	public static function hasExecutor(string $name): bool
	{
		self::bootExecutors();
		return isset(self::$executors[$name]);
	}

	public static function execute(string $executor, string $tool, array $arguments, array $auth): mixed
	{
		self::bootExecutors();
		if (!isset(self::$executors[$executor])) {
			return self::error('tool_not_found', __('The requested write tool has no registered executor.', 'llamly'), 404);
		}
		if (!self::writeEnabled()) {
			return self::notReady();
		}
		return (self::$executors[$executor])($arguments, $auth, $tool);
	}

	private static function bootExecutors(): void
	{
		if (!self::$executors) {
			self::$executors = [
				'post.create' => [self::class, 'createPost'],
				'post.update' => [self::class, 'updatePost'],
			];
		}
		// Add-on module executors ("<module id>.<name>") join once; they write only through their WriteGateway.
		if (!self::$modulesMerged && \Llamly\Free\Modules\ModuleRegistry::ready()) {
			self::$modulesMerged = true;
			self::$executors += \Llamly\Free\Modules\ModuleRegistry::executors();
		}
	}

	/** Kept for the original post executor names; the post tools own validation and intent building. */
	public static function createPost(array $arguments, array $auth): mixed
	{
		return \Llamly\Free\Tools\PostTools::createPost($arguments, $auth);
	}

	public static function updatePost(array $arguments, array $auth): mixed
	{
		return \Llamly\Free\Tools\PostTools::updatePost($arguments, $auth);
	}

	/** Issue a single-use administrator approval for an Activity undo/redo, bound to the current expected state. */
	public static function approveInverse(string $operationId, string $action, int $actorUserId): array|\WP_Error
	{
		return Engine::approveInverse($operationId, $action === 'redo' ? 'redo' : 'undo', $actorUserId);
	}

	public static function resolveRecovery(string $operationId, int $actorUserId): array|\WP_Error
	{
		return Engine::resolveRecovery($operationId, $actorUserId);
	}

	/** Called by the authenticated Activity UI. */
	public static function undo(string $operationId, int $actorUserId, string $permit = ''): mixed
	{
		return Engine::inverse($operationId, 'undo', Engine::adminContext($actorUserId), $permit);
	}

	/** Called by the authenticated Activity UI. */
	public static function redo(string $operationId, int $actorUserId, string $permit = ''): mixed
	{
		return Engine::inverse($operationId, 'redo', Engine::adminContext($actorUserId), $permit);
	}

	/** @return array{items:list<array<string,mixed>>,total:int} */
	public static function listOperations(int $page = 1, int $perPage = 25, array $filters = []): array
	{
		return Engine::listOperations($page, $perPage, $filters);
	}

	public static function pruneExpiredSnapshots(): bool
	{
		return Engine::pruneExpiredSnapshots();
	}

	/** @return true|\WP_Error */
	public static function preflight(array $domainTables = []): bool|\WP_Error
	{
		global $wpdb;
		$settings = Settings::fresh();
		if (empty($settings['_valid']) || empty($settings['enabled']) || empty($settings['journal_enabled'])) {
			return self::error('journal_unavailable', __('Llamly or its required journal is paused.', 'llamly'), 503);
		}
		if ((int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Schema::OPTION_VERSION)) !== Schema::VERSION) {
			return self::error('journal_unavailable', __('The Llamly journal schema is not current.', 'llamly'), 503);
		}
		// Requests that waited for the pre-D1 approval policy never run under the new one (idempotent, once per site).
		PolicyMigration::maybeRun();
		if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
			return self::error('journal_unavailable', __('The active database adapter cannot guarantee a single transaction connection.', 'llamly'), 503);
		}
		if (self::dbDropinActive()) {
			return self::error('db_dropin_unsupported', self::dropinMessage(), 503);
		}
		$tables = self::requiredTables($domainTables);
		$snapshot = self::snapshot($tables);
		if ($snapshot['tables_error']) {
			return self::error('journal_unavailable', __('The database storage engines of the Llamly tables could not be verified.', 'llamly'), 503);
		}
		$operations = strtolower(Schema::tableNames()['operations']);
		foreach ($tables as $name) {
			$status = $snapshot['tables'][strtolower($name)] ?? null;
			if ($status === null || strtolower($status['engine']) !== 'innodb') {
				return self::tableError($name, $status === null ? '' : $status['engine']);
			}
			if (strtolower($name) === $operations && !in_array(strtoupper($status['row_format']), ['DYNAMIC', 'COMPRESSED'], true)) {
				return self::error('journal_unavailable', __('The journal table row format cannot support its unique idempotency key.', 'llamly'), 503);
			}
		}
		if ($snapshot['vars_error']) {
			return self::error('journal_unavailable', __('The database cluster mode and durability settings could not be verified.', 'llamly'), 503);
		}
		if (strtoupper($snapshot['vars']['wsrep_on'] ?? '') === 'ON') {
			return self::error('galera_unsupported', self::galeraMessage(), 503);
		}
		$flush = $snapshot['vars']['innodb_flush_log_at_trx_commit'] ?? '';
		if ($flush !== '1' && $flush !== '2') {
			return self::error('durability_unavailable', self::durabilityMessage($flush), 503);
		}
		return true;
	}

	/** @var array<string, array<string, mixed>> */
	private static array $snapshots = [];
	private static ?string $isolationVariable = null;

	/** Forget the per-request database snapshot (tests change global variables inside one process). */
	public static function resetPreflightCache(): void
	{
		self::$snapshots = [];
	}

	/** @return list<string> */
	private static function requiredTables(array $domainTables): array
	{
		global $wpdb;
		return array_values(array_unique(array_merge([
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->term_relationships,
		], array_values(Schema::tableNames()), array_values($domainTables))));
	}

	/**
	 * Two verification queries, cached for the request: table engines in one information_schema read, and the global
	 * variables the journal depends on in one SHOW.
	 *
	 * @param list<string> $tables
	 * @return array{tables:array<string,array{engine:string,row_format:string}>,tables_error:bool,vars:array<string,string>,vars_error:bool}
	 */
	private static function snapshot(array $tables): array
	{
		global $wpdb;
		$key = md5(implode('|', $tables));
		if (isset(self::$snapshots[$key])) {
			return self::$snapshots[$key];
		}
		$found = [];
		$rows = $wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME, ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(', ', array_fill(0, count($tables), '%s')) . ')', $tables), ARRAY_A);
		$tablesError = $wpdb->last_error !== '' || !is_array($rows);
		foreach (is_array($rows) ? $rows : [] as $row) {
			$row = array_change_key_case($row, CASE_LOWER);
			$found[strtolower((string) ($row['table_name'] ?? ''))] = ['engine' => (string) ($row['engine'] ?? ''), 'row_format' => (string) ($row['row_format'] ?? '')];
		}
		$vars = [];
		$varRows = $wpdb->get_results("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('wsrep_on','innodb_flush_log_at_trx_commit','max_allowed_packet')", ARRAY_A);
		$varsError = $wpdb->last_error !== '' || !is_array($varRows);
		foreach (is_array($varRows) ? $varRows : [] as $row) {
			$vars[strtolower((string) ($row['Variable_name'] ?? ''))] = (string) ($row['Value'] ?? '');
		}
		return self::$snapshots[$key] = ['tables' => $found, 'tables_error' => $tablesError, 'vars' => $vars, 'vars_error' => $varsError];
	}

	/** Current session transaction isolation as "REPEATABLE READ"-style text, or null when it cannot be read. */
	public static function sessionIsolation(): ?string
	{
		global $wpdb;
		$names = self::$isolationVariable !== null ? [self::$isolationVariable] : ['transaction_isolation', 'tx_isolation'];
		$previous = $wpdb->suppress_errors(true);
		try {
			foreach ($names as $name) {
				// A session variable name cannot be bound: one literal query per name of the closed list above.
				$value = $name === 'tx_isolation'
					? $wpdb->get_var('SELECT @@SESSION.tx_isolation')
					: $wpdb->get_var('SELECT @@SESSION.transaction_isolation');
				if ($wpdb->last_error === '' && is_string($value) && $value !== '') {
					self::$isolationVariable = $name;
					return strtoupper(str_replace('-', ' ', $value));
				}
			}
		} finally {
			$wpdb->suppress_errors($previous);
		}
		return null;
	}

	/**
	 * Put the session on REPEATABLE READ for the journal transactions and report the level to restore afterwards.
	 *
	 * @return string|\WP_Error The previous level (one of the four standard names).
	 */
	public static function enterRepeatableRead(): string|\WP_Error
	{
		global $wpdb;
		$previous = self::sessionIsolation();
		if ($previous === null || !in_array($previous, self::ISOLATION_LEVELS, true)) {
			return self::error('isolation_unavailable', self::isolationMessage($previous ?? __('unreadable', 'llamly')), 503);
		}
		if ($previous === 'REPEATABLE READ') {
			return $previous;
		}
		$suppressed = $wpdb->suppress_errors(true);
		$set = $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$wpdb->suppress_errors($suppressed);
		$now = $set === false ? null : self::sessionIsolation();
		if ($now !== 'REPEATABLE READ') {
			self::restoreIsolation($previous);
			return self::error('isolation_unavailable', self::isolationMessage($previous), 503);
		}
		return $previous;
	}

	/**
	 * SET SESSION TRANSACTION ISOLATION LEVEL for one of self::ISOLATION_LEVELS. A level cannot be bound, so each one
	 * is its own literal statement; anything else is refused without a query.
	 */
	private static function setIsolation(string $level): int|bool
	{
		global $wpdb;
		return match ($level) {
			'READ UNCOMMITTED' => $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED'),
			'READ COMMITTED' => $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'),
			'REPEATABLE READ' => $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'),
			'SERIALIZABLE' => $wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE'),
			default => false,
		};
	}

	/** Restore the level saved by enterRepeatableRead(); the value is checked against a closed list before use. */
	public static function restoreIsolation(string $previous): void
	{
		global $wpdb;
		if ($previous === 'REPEATABLE READ' || !in_array($previous, self::ISOLATION_LEVELS, true)) {
			return;
		}
		$suppressed = $wpdb->suppress_errors(true);
		self::setIsolation($previous);
		$wpdb->suppress_errors($suppressed);
	}

	/**
	 * One row per host requirement for the Doctor, built from the same snapshot the write preflight uses.
	 *
	 * @return list<array{id:string,label:string,status:string,value:string,detail:string,host:?string,code:?string}>
	 */
	public static function requirements(array $domainTables = []): array
	{
		global $wpdb;
		$tables = self::requiredTables($domainTables);
		$snapshot = self::snapshot($tables);
		$rows = [];

		$bad = [];
		$evidence = [];
		foreach ($tables as $name) {
			$status = $snapshot['tables'][strtolower($name)] ?? null;
			$engine = $status === null ? __('missing', 'llamly') : $status['engine'];
			$evidence[] = $name . '=' . $engine;
			if ($status === null || strtolower($engine) !== 'innodb') {
				$bad[] = $name . ' (' . $engine . ')';
			}
		}
		$rows[] = [
			'id' => 'req_innodb',
			'label' => __('InnoDB tables', 'llamly'),
			'status' => $snapshot['tables_error'] ? 'fail' : ($bad ? 'fail' : 'pass'),
			'value' => $snapshot['tables_error'] ? __('unreadable', 'llamly') : ($bad ? implode(', ', $bad) : 'InnoDB'),
			'detail' => $snapshot['tables_error']
				? __('The storage engines could not be read from information_schema.', 'llamly')
				: ($bad
					/* translators: %s: comma-separated tables with their engine. */
					? sprintf(__('These tables are not InnoDB: %s.', 'llamly'), implode(', ', $bad))
					: __('Every content, metadata and journal table uses InnoDB.', 'llamly')),
			'host' => $bad ? __('Ask your host to convert each listed table with ALTER TABLE <table> ENGINE=InnoDB.', 'llamly') : null,
			'code' => $bad ? 'table_not_innodb' : null,
			'evidence' => $evidence,
		];

		$session = self::sessionIsolation();
		$canSet = false;
		if ($session !== null && in_array($session, self::ISOLATION_LEVELS, true)) {
			// Prove the SET is permitted: switch to the other common level, read it back, then restore the original.
			$probe = $session === 'READ COMMITTED' ? 'REPEATABLE READ' : 'READ COMMITTED';
			$suppressed = $wpdb->suppress_errors(true);
			$set = self::setIsolation($probe);
			$wpdb->suppress_errors($suppressed);
			$canSet = $set !== false && self::sessionIsolation() === $probe;
			$restore = $wpdb->suppress_errors(true);
			self::setIsolation($session);
			$wpdb->suppress_errors($restore);
			$canSet = $canSet && self::sessionIsolation() === $session;
		}
		$rows[] = [
			'id' => 'req_isolation',
			'label' => __('Transaction isolation', 'llamly'),
			'status' => $canSet ? 'pass' : 'fail',
			'value' => ($session ?? __('unreadable', 'llamly')) . ($canSet ? '' : ' (' . __('cannot be changed', 'llamly') . ')'),
			'detail' => $canSet
				? __('Llamly sets REPEATABLE READ for each write transaction and restores the previous level afterwards, so the host default does not need to change.', 'llamly')
				: __('Llamly could not switch this database session between isolation levels, which every write needs.', 'llamly'),
			'host' => $canSet ? null : __('Ask your host to allow SET SESSION TRANSACTION ISOLATION LEVEL for the WordPress database user.', 'llamly'),
			'code' => $canSet ? null : 'isolation_unavailable',
		];

		$flush = $snapshot['vars_error'] ? '' : ($snapshot['vars']['innodb_flush_log_at_trx_commit'] ?? '');
		$flushStatus = $flush === '1' ? 'pass' : ($flush === '2' ? 'warn' : 'fail');
		$rows[] = [
			'id' => 'req_flush',
			'label' => __('Durable commits', 'llamly'),
			'status' => $flushStatus,
			'value' => 'innodb_flush_log_at_trx_commit=' . ($flush === '' ? __('unreadable', 'llamly') : $flush),
			'detail' => $flush === '1'
				? __('InnoDB flushes the log at every commit.', 'llamly')
				: ($flush === '2'
					? __('InnoDB writes the log at every commit but flushes it to disk about once a second; an operating-system crash or power loss (not just a MySQL restart) can lose up to about one second of whole transactions, including journal entries. Effects outside the database, such as media files, can survive that loss.', 'llamly')
					: self::durabilityMessage($flush)),
			'host' => $flush === '1' ? null : ($flush === '2'
				? __('For full durability, ask your host to set innodb_flush_log_at_trx_commit=1. Value 2 is accepted.', 'llamly')
				: __('Ask your host to set innodb_flush_log_at_trx_commit to 1 (or 2).', 'llamly')),
			'code' => $flushStatus === 'fail' ? 'durability_unavailable' : null,
		];

		$wsrep = $snapshot['vars_error'] ? null : strtoupper($snapshot['vars']['wsrep_on'] ?? '');
		$galera = $wsrep === 'ON';
		$rows[] = [
			'id' => 'req_galera',
			'label' => __('Galera cluster', 'llamly'),
			'status' => $wsrep === null ? 'fail' : ($galera ? 'fail' : 'pass'),
			'value' => 'wsrep_on=' . ($wsrep === null ? __('unreadable', 'llamly') : ($wsrep === '' ? __('not set', 'llamly') : $wsrep)),
			'detail' => $galera ? self::galeraMessage() : __('The database is not running in Galera cluster mode.', 'llamly'),
			'host' => $galera ? __('Ask your host to run this site on a non-clustered database.', 'llamly') : null,
			'code' => $galera ? 'galera_unsupported' : null,
		];

		$dropin = self::dbDropinActive();
		$rows[] = [
			'id' => 'req_dropin',
			'label' => __('Database drop-in', 'llamly'),
			'status' => $dropin ? 'fail' : 'pass',
			'value' => $dropin ? 'wp-content/db.php' : __('none', 'llamly'),
			'detail' => $dropin ? self::dropinMessage() : __('No custom wp-content/db.php drop-in is installed.', 'llamly'),
			'host' => $dropin ? __('Remove wp-content/db.php (or ask your host to), then rerun the Doctor.', 'llamly') : null,
			'code' => $dropin ? 'db_dropin_unsupported' : null,
		];

		$packet = $snapshot['vars_error'] ? null : (int) ($snapshot['vars']['max_allowed_packet'] ?? 0);
		$packetOk = $packet !== null && $packet >= 16 * MB_IN_BYTES;
		$rows[] = [
			'id' => 'req_max_packet',
			'label' => __('Maximum packet size', 'llamly'),
			'status' => $packetOk ? 'pass' : 'warn',
			'value' => 'max_allowed_packet=' . ($packet === null || $packet === 0 ? __('unreadable', 'llamly') : (string) $packet),
			'detail' => $packetOk
				? __('The database accepts packets large enough for journal snapshots.', 'llamly')
				: __('Large changes (big pages) can exceed this packet size and be refused.', 'llamly'),
			'host' => $packetOk ? null : __('Ask your host to raise max_allowed_packet to at least 16M.', 'llamly'),
			'code' => $packetOk ? null : 'max_allowed_packet_low',
		];
		return $rows;
	}

	private static function tableError(string $table, string $engine): \WP_Error
	{
		$message = $engine === ''
			/* translators: %s: database table name. */
			? sprintf(__('The table %s was not found, so Llamly cannot verify it uses InnoDB. Ask your host to restore it, or reactivate Llamly to recreate it.', 'llamly'), $table)
			/* translators: 1: database table name, 2: its storage engine. */
			: sprintf(__('The table %1$s uses the %2$s storage engine, but Llamly needs InnoDB for transactions. Ask your host to run ALTER TABLE %1$s ENGINE=InnoDB.', 'llamly'), $table, $engine);
		return self::error('table_not_innodb', $message, 503);
	}

	private static function galeraMessage(): string
	{
		return __('The database variable wsrep_on is ON (Galera cluster), which Llamly does not support for writes. Ask your host to run this site on a non-clustered database.', 'llamly');
	}

	private static function dropinMessage(): string
	{
		return __('A custom database drop-in (wp-content/db.php) is installed, so Llamly cannot guarantee that one transaction stays on one connection. Remove it, or ask your host to.', 'llamly');
	}

	private static function durabilityMessage(string $flush): string
	{
		/* translators: %s: current value of the MySQL variable, or "unreadable". */
		return sprintf(__('The database variable innodb_flush_log_at_trx_commit is %s, so commits are not durable. Ask your host to set it to 1 (or 2).', 'llamly'), $flush === '' ? __('unreadable', 'llamly') : $flush);
	}

	private static function isolationMessage(string $current): string
	{
		/* translators: %s: current session transaction isolation level. */
		return sprintf(__('Llamly could not switch the database session to REPEATABLE READ (transaction_isolation is %s). Ask your host to allow SET SESSION TRANSACTION ISOLATION LEVEL for the WordPress database user.', 'llamly'), $current);
	}

	private static function notReady(): \WP_Error
	{
		return Engine::error('write_not_ready', __('Writing is paused until concurrency and journal proof is complete.', 'llamly'), 503);
	}

	private static function error(string $code, string $message, int $status): \WP_Error
	{
		return Engine::error($code, $message, $status);
	}
}
