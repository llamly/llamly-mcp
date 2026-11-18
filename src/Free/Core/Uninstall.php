<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes Llamly's own rows on uninstall.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- drops only Llamly tables ({prefix}llamly_free_*) on uninstall, when the administrator enabled data removal.

namespace Llamly\Free\Core;

/**
 * Removes Llamly data when the plugin is deleted. Llamly's tables, options and media copies only go when an
 * administrator enabled data removal in Settings; unfinished uploads and the Freemius state of Llamly always go.
 * Leaves the options of earlier experimental Llamly builds (llamly_mcp_spike_*) untouched.
 *
 * llamly/uninstall.php calls onUninstall() inside the uninstall_<plugin> action. WordPress runs uninstall.php instead
 * of the Freemius SDK's uninstall hook, so onUninstall() first lets the SDK report the uninstall
 * (Freemius::_uninstall_plugin_hook, which fires after_uninstall) and then runs the cleanup itself, so the cleanup also
 * happens without a signed-in administrator (WP-CLI) or without the SDK (LLP-455).
 */
final class Uninstall
{
	private static bool $ran = false;

	/** Called by llamly/uninstall.php inside the uninstall_{plugin} action. */
	public static function onUninstall(): void
	{
		if (class_exists('Freemius', false) && \Llamly\Free\Freemius\Sdk::instance() !== null) {
			try {
				\Freemius::_uninstall_plugin_hook();
			} catch (\Throwable $error) {
				// The SDK must never block the cleanup below.
			}
		}
		self::run();
	}

	/** Idempotent: the SDK's after_uninstall action and onUninstall() both call it. */
	public static function run(): void
	{
		if (self::$ran) {
			return;
		}
		self::$ran = true;
		if (is_multisite()) {
			foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blogId) {
				switch_to_blog((int) $blogId);
				\Llamly\Free\Freemius\Storage::forget();
				self::site();
				restore_current_blog();
			}
			global $wpdb;
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like('llamly_free_') . '%'));
		} else {
			\Llamly\Free\Freemius\Storage::forget();
			self::site();
		}
	}

	private static function site(): void
	{
		global $wpdb;

		wp_clear_scheduled_hook('llamly_free_daily_maintenance');
		// Unfinished uploads in parts are temporary files, never site data: always removed.
		wp_unschedule_hook(\Llamly\Free\Tools\MediaParts::HOOK_EXPIRE);
		\Llamly\Free\Tools\MediaParts::purgeAll();
		// Data is preserved unless an administrator explicitly enabled removal in Settings.
		$settings = get_option('llamly_free_settings', []);
		if (!is_array($settings) || ($settings['delete_data_on_uninstall'] ?? false) !== true) {
			return;
		}

		foreach (Schema::tableNames() as $table) {
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
			$backups = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table . '_bak_v') . '%'));
			foreach ((array) $backups as $backup) {
				$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', (string) $backup));
			}
		}

		// Tables of features that left the plugin, if an upgrade did not drop them yet.
		Schema::dropRetired();

		// Media retention copies (uploads/llamly-mcp/). Their index rows (llamly_free_mret_*) go with the options below.
		$uploads = wp_upload_dir(null, false);
		$base = realpath((string) ($uploads['basedir'] ?? ''));
		$dir = $base ? realpath($base . '/llamly-mcp') : false;
		if ($base && $dir && strpos($dir, $base . DIRECTORY_SEPARATOR) === 0 && is_dir($dir) && !is_link($dir)) {
			$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($items as $item) {
				if ($item->isDir() && !$item->isLink()) {
					Filesystem::direct()->rmdir($item->getPathname());
				} else {
					wp_delete_file($item->getPathname());
				}
			}
			Filesystem::direct()->rmdir($dir);
		}

		$like = $wpdb->esc_like('llamly_free_') . '%';
		$timeout = $wpdb->esc_like('_transient_timeout_llamly_free_') . '%';
		$transient = $wpdb->esc_like('_transient_llamly_free_') . '%';
		$siteTimeout = $wpdb->esc_like('_site_transient_timeout_llamly_free_') . '%';
		$siteTransient = $wpdb->esc_like('_site_transient_llamly_free_') . '%';
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$like,
			$timeout,
			$transient,
			$siteTimeout,
			$siteTransient
		));
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like));

		wp_clear_scheduled_hook('llamly_free_daily_maintenance');
		wp_cache_flush();
	}
}
