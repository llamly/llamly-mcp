<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- creates, upgrades, backs up or drops only Llamly tables ({prefix}llamly_free_*).

namespace Llamly\Free\Core;

use Llamly\Free\Admin\AdminPage;
use Llamly\Free\Admin\AdminController;
use Llamly\Free\Auth\OAuthServer;
use Llamly\Free\Auth\WellKnown;
use Llamly\Free\History\EditorGuard;
use Llamly\Free\Mcp\Endpoint;

final class Plugin
{
	private const LOCK_NAME = 'llamly_free_schema_upgrade';
	private const OPTION_LAST_ATTEMPT = 'llamly_free_upgrade_last_attempt';
	private const OPTION_SKIP_BACKUP = 'llamly_free_upgrade_skip_backup';
	private const RETRY_AFTER_FAILURE = 10 * MINUTE_IN_SECONDS;
	public const SKIP_BACKUP_ACTION = 'llamly_free_upgrade_skip_backup';

	private static bool $booted = false;
	private static bool $runtimeRegistered = false;
	/** First schema version with the object_versions table the EditorGuard reads. */
	private const EDITOR_GUARD_MIN_SCHEMA = 8;

	public static function activate(): void
	{
		if (!Schema::install()) {
			wp_die(
				esc_html__('Llamly could not create or verify its database tables. The plugin was not enabled.', 'llamly'),
				esc_html__('Llamly activation failed', 'llamly'),
				['back_link' => true]
			);
		}
		Maintenance::schedule();
		WellKnown::addRewriteRules();
		flush_rewrite_rules();
	}

	public static function deactivate(): void
	{
		wp_clear_scheduled_hook(Maintenance::HOOK);
		wp_unschedule_hook(\Llamly\Free\Tools\MediaParts::HOOK_EXPIRE);
	}

	public static function boot(): void
	{
		if (self::$booted) {
			return;
		}
		self::$booted = true;
		add_action('change_locale', [self::class, 'onChangeLocale']);
		AdminPage::registerHooks();
		AdminController::registerHooks();
		WellKnown::registerHooks();
		OAuthServer::registerHooks();
		// Add-ons learn that Llamly is active (version, module API) and hook llamly_free_register_modules from here.
		\Llamly\Free\Modules\ModuleRegistry::announce();

		$storedSchema = (int) get_option(Schema::OPTION_VERSION, 0);
		if ($storedSchema >= self::EDITOR_GUARD_MIN_SCHEMA) {
			// The guard only reads object_versions, which exists since schema v8 and has the same columns in v9, so it
			// stays on while the upgrade is pending (a stale editor save of an agent-edited post must still get 409).
			// Below v8 the table may be missing: the guard stays off until registerRuntime() runs after the upgrade.
			EditorGuard::registerHooks();
		}
		if ($storedSchema !== Schema::VERSION) {
			add_action('admin_init', [self::class, 'maybeUpgrade'], 1);
			add_action('admin_notices', [self::class, 'schemaNotice']);
			add_action('admin_post_' . self::SKIP_BACKUP_ACTION, [self::class, 'skipBackupAndUpgrade']);
			// Until the schema is current the MCP endpoint answers 503 + Retry-After instead of a 404.
			add_action('rest_api_init', [Endpoint::class, 'registerUpgradeRoute']);
			if (self::shouldAutoUpgrade()) {
				add_action('init', [self::class, 'autoUpgrade'], 5);
			}
			return;
		}

		self::registerRuntime();
	}

	public static function runtimeRegistered(): bool
	{
		return self::$runtimeRegistered;
	}

	/** Everything that needs a verified schema. Idempotent; also called by the upgrade once the schema is current. */
	public static function registerRuntime(): void
	{
		if (self::$runtimeRegistered) {
			return;
		}
		self::$runtimeRegistered = true;
		Maintenance::registerHooks();
		\Llamly\Free\Tools\MediaParts::registerHooks();
		Abilities::registerHooks();
		EditorGuard::registerHooks();
		add_action('rest_api_init', [Endpoint::class, 'registerRoutes']);
		add_action('rest_api_init', [OAuthServer::class, 'registerRoutes']);
		if (did_action('init') > 0) {
			// Upgraded during init: the early init callbacks already passed, so run them now.
			ToolRegistry::boot();
			return;
		}
		add_action('init', [ToolRegistry::class, 'boot'], 1);
	}

	/** Cron, WP-CLI and requests to the Llamly REST namespace migrate without an admin visit. */
	private static function shouldAutoUpgrade(): bool
	{
		if ((function_exists('wp_doing_cron') && wp_doing_cron()) || defined('WP_CLI')) {
			return true;
		}
		$uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		return str_contains($uri, '/llamly/v1/') || str_contains($uri, 'rest_route=/llamly/v1') || str_contains($uri, 'rest_route=%2Fllamly%2Fv1');
	}

	/**
	 * Translations come from WordPress.org language packs (translate.wordpress.org) in WP_LANG_DIR; the package ships
	 * only the .pot. WordPress's just-in-time loader handles normal requests; this explicit load is used when the
	 * locale changes inside a request (reloadDomain()).
	 */
	public static function loadTextdomain(string $locale = ''): void
	{
		$locale = $locale !== '' ? $locale : determine_locale();
		if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $locale)) {
			return;
		}
		$pack = WP_LANG_DIR . '/plugins/llamly-' . $locale . '.mo';
		if (is_readable($pack)) {
			load_textdomain('llamly', $pack, $locale);
		}
	}

	/**
	 * The OAuth consent screen is a front-end page, where WordPress uses the site language. It speaks to one signed-in
	 * administrator, so it follows that user's admin language (Profile > Language), like the Llamly admin does.
	 * The domain is unloaded as not reloadable, so WordPress's just-in-time loader cannot bring the site language back:
	 * a user language without a translation file (English, the source language) shows the original strings.
	 */
	public static function useUserLocale(): string
	{
		$locale = is_user_logged_in() ? get_user_locale() : determine_locale();
		if ($locale !== determine_locale()) {
			self::reloadDomain($locale);
		}
		return $locale;
	}

	/**
	 * switch_to_locale() / restore_previous_locale() (the MCP layer answers in English, the admin keeps the user's
	 * language) fire change_locale: the llamly domain follows, like the OAuth screen does with useUserLocale().
	 */
	public static function onChangeLocale(string $locale): void
	{
		self::reloadDomain($locale);
	}

	private static function reloadDomain(string $locale): void
	{
		unload_textdomain('llamly', false);
		self::loadTextdomain($locale);
		if (!is_textdomain_loaded('llamly')) {
			// Nothing is loaded (English, or the domain was never loaded): core only flags a loaded domain as
			// unloaded, so a short-circuited unload is used to set that flag and keep the JIT loader away.
			$flagOnly = static fn ($override, $domain) => $domain === 'llamly' ? true : $override;
			add_filter('override_unload_textdomain', $flagOnly, 10, 2);
			unload_textdomain('llamly', false);
			remove_filter('override_unload_textdomain', $flagOnly, 10);
		}
	}

	/**
	 * Schema upgrade for administrators (admin_init). Writes stay paused (boot registers no runtime and
	 * MutationRunner::preflight rejects) until Schema::install() verified every table, column and key.
	 * It ignores the retry interval that protects the automatic path, except on admin-ajax: heartbeat polls would
	 * otherwise repeat a failed backup every few seconds.
	 */
	public static function maybeUpgrade(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		if ((int) get_option(Schema::OPTION_VERSION, 0) >= Schema::VERSION) {
			delete_option(self::OPTION_SKIP_BACKUP);
			return;
		}
		if (function_exists('wp_doing_ajax') && wp_doing_ajax() && self::throttled()) {
			return;
		}
		if (self::runUpgrade()) {
			self::registerRuntime();
		}
	}

	/**
	 * Same upgrade without a visit to wp-admin (init priority 5: cron, WP-CLI, Llamly REST requests).
	 * The ten-minute wait applies to two recorded failures: backup_failed and schema_verification_failed, so an
	 * anonymous caller cannot make every request repeat a table copy or a dbDelta that keeps failing. An interrupted
	 * attempt (state "interrupted": timeout, a killed process) is retried by the very next request: dbDelta and the
	 * backup are idempotent, so it simply continues where the last one stopped, and the lock keeps requests apart.
	 * The admin path (maybeUpgrade) ignores the wait, except on admin-ajax heartbeats.
	 */
	public static function autoUpgrade(): void
	{
		if ((int) get_option(Schema::OPTION_VERSION, 0) >= Schema::VERSION) {
			delete_option(self::OPTION_SKIP_BACKUP);
			return;
		}
		if (self::throttled()) {
			return;
		}
		if (self::runUpgrade()) {
			self::registerRuntime();
		}
	}

	private static function throttled(): bool
	{
		$error = (string) get_option('llamly_free_upgrade_error', '');
		return ($error === 'schema_verification_failed' || str_starts_with($error, 'backup_failed'))
			&& time() - (int) get_option(self::OPTION_LAST_ATTEMPT, 0) < self::RETRY_AFTER_FAILURE;
	}

	private static function storedVersion(): int
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Schema::OPTION_VERSION));
	}

	/**
	 * admin-post handler of the "Continue without a backup" button. The flag names the exact step it was granted for
	 * ("<from>><to>"); an upgrade over a different pair discards it.
	 */
	public static function skipBackupAndUpgrade(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do this.', 'llamly'), '', ['response' => 403]);
		}
		check_admin_referer(self::SKIP_BACKUP_ACTION);
		$old = self::storedVersion();
		if ($old < Schema::VERSION) {
			update_option(self::OPTION_SKIP_BACKUP, $old . '>' . Schema::VERSION, false);
		}
		if (self::runUpgrade()) {
			self::registerRuntime();
		}
		delete_option(self::OPTION_SKIP_BACKUP);
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	/** True when this request ran the upgrade (or found it done by another request) and the schema is current. */
	private static function runUpgrade(): bool
	{
		global $wpdb;
		// The skip flag is single-use: read and discard it on every path, whatever happens next.
		$skip = (string) get_option(self::OPTION_SKIP_BACKUP, '');
		if ($skip !== '') {
			delete_option(self::OPTION_SKIP_BACKUP);
		}
		$lock = strlen($wpdb->prefix . self::LOCK_NAME) <= 64 ? $wpdb->prefix . self::LOCK_NAME : 'llamly_su_' . md5($wpdb->prefix . self::LOCK_NAME);
		// GET_LOCK belongs to this connection and vanishes with it: no stale lock to expire, no resume by clock.
		if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
			return false;
		}
		try {
			wp_cache_delete(Schema::OPTION_VERSION, 'options');
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
			$old = self::storedVersion();
			if ($old >= Schema::VERSION) {
				return true;
			}
			update_option(self::OPTION_LAST_ATTEMPT, time(), false);
			// Replaced by the real reason below (backup_failed, schema_verification_failed); it stays only if this
			// request dies mid-upgrade, and that state is never throttled.
			update_option('llamly_free_upgrade_error', 'interrupted', false);
			$backedUp = false;
			if ($old > 0 && Schema::requiresBackup($old)) {
				if ($skip !== $old . '>' . Schema::VERSION) {
					if (!self::backupTables($old)) {
						return false;
					}
					$backedUp = true;
				}
			}
			if (!Schema::install()) {
				update_option('llamly_free_upgrade_error', 'schema_verification_failed', false);
				return false;
			}
			delete_option('llamly_free_upgrade_error');
			delete_option(self::OPTION_LAST_ATTEMPT);
			try {
				if ($backedUp) {
					Maintenance::pruneBackups($old);
				}
				Maintenance::pruneOrphanBackups();
				update_option(Maintenance::OPTION_LABEL_BACKFILL, '0', false);
				Maintenance::backfillOperationLabels();
			} catch (\Throwable $e) {
				// Housekeeping and the label backfill are best effort; the daily maintenance resumes the backfill.
				unset($e);
			}
			return true;
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
		}
	}

	/**
	 * Runs only when the upgrade path has a destructive step (Schema::STEPS); additive upgrades skip it.
	 * Copies each existing table to <table>_bak_v<old> (structure and rows) unless that backup already exists.
	 * Older backups are pruned after a verified upgrade (Maintenance::pruneBackups); uninstall (Core\Uninstall) removes the rest.
	 */
	private static function backupTables(int $old): bool
	{
		global $wpdb;
		foreach (Schema::tableNames() as $table) {
			$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
			if ($exists !== $table) {
				continue;
			}
			$backup = $table . '_bak_v' . $old;
			$source = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
			$backupExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($backup)));
			if ($backupExists === $backup) {
				// A finished backup is only renamed into place, so an existing one is complete unless rows changed since.
				if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $backup)) >= $source) {
					continue;
				}
				$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $backup));
			}
			$tmp = $backup . '_tmp';
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tmp));
			if ($wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i', $tmp, $table)) === false
				|| $wpdb->query($wpdb->prepare('INSERT INTO %i SELECT * FROM %i', $tmp, $table)) === false
				|| (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $tmp)) < $source
				|| $wpdb->query($wpdb->prepare('RENAME TABLE %i TO %i', $tmp, $backup)) === false
			) {
				$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $tmp));
				update_option('llamly_free_upgrade_error', 'backup_failed:' . $table, false);
				return false;
			}
		}
		return true;
	}

	public static function schemaNotice(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}
		$reason = (string) get_option('llamly_free_upgrade_error', '');
		echo '<div class="notice notice-error"><p>'
			. esc_html__('Llamly database setup is incomplete. MCP requests and the admin interface are paused until the schema is verified.', 'llamly')
			. ($reason !== '' ? ' ' . esc_html(sprintf(/* translators: %s: internal error code. */ __('Last error: %s', 'llamly'), $reason)) : '')
			. '</p>';
		if (str_starts_with($reason, 'backup_failed')) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::SKIP_BACKUP_ACTION) . '">';
			wp_nonce_field(self::SKIP_BACKUP_ACTION);
			echo '<p><button type="submit" class="button">' . esc_html__('Continue without a backup', 'llamly') . '</button></p></form>';
		}
		echo '</div>';
	}
}
