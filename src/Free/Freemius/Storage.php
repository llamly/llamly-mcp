<?php

declare(strict_types=1);

namespace Llamly\Free\Freemius;

/**
 * Removes what the Freemius SDK stored about Llamly, on uninstall. The SDK keeps the data of every Freemius product of
 * the site in shared options (fs_accounts and others), so this removes only Llamly's entries, by slug, product id and
 * main file, the same keys the SDK's own garbage collector (FS_Product_Garbage_Collector) removes. When no other
 * Freemius product is left in fs_accounts, the shared Freemius options are deleted as well.
 *
 * Works on the raw options, so it also runs when the SDK is not loaded (development settings, SDK failure).
 */
final class Storage
{
	public const SLUG = 'llamly';
	/** Per-product maps of fs_accounts, keyed by slug, product id or main file: the ones FS_Product_Garbage_Collector cleans, plus plugin storage. */
	private const MAPS = ['admin_notices', 'updates', 'all_licenses', 'addons', 'id_slug_type_path_map', 'file_slug_map', 'sites', 'plans', 'plugins', 'plugin_data', 'gc_timestamp'];
	/** Options the SDK shares between all Freemius products of the site. */
	private const SHARED = ['fs_accounts', 'fs_dbg_accounts', 'fs_api_cache', 'fs_gdpr', 'fs_options', 'fs_active_plugins', 'fs_debug_mode', 'fs_storage_logger'];

	/** Current site, and the network options on multisite. */
	public static function forget(): void
	{
		self::forgetIn(false);
		if (is_multisite()) {
			self::forgetIn(true);
		}
	}

	private static function forgetIn(bool $network): void
	{
		$accounts = $network ? get_site_option('fs_accounts') : get_option('fs_accounts');
		if (!is_array($accounts)) {
			return;
		}
		$id = (string) Sdk::config()->sdkArgsId();
		$file = defined('LLAMLY_FREE_PLUGIN_FILE') ? plugin_basename(LLAMLY_FREE_PLUGIN_FILE) : '';
		foreach (self::MAPS as $name) {
			if (!isset($accounts[$name]) || !is_array($accounts[$name])) {
				continue;
			}
			$map = $accounts[$name];
			unset($map[self::SLUG], $map[self::SLUG . ':plugin']);
			if ($id !== '') {
				unset($map[$id], $map[(int) $id]);
			}
			if ($file !== '' && isset($map[$file]) && $map[$file] === self::SLUG) {
				unset($map[$file]);
			}
			$accounts[$name] = $map;
		}
		$others = !empty($accounts['plugins']) || !empty($accounts['themes']);
		if ($others) {
			$network ? update_site_option('fs_accounts', $accounts) : update_option('fs_accounts', $accounts);
			return;
		}
		foreach (self::SHARED as $option) {
			$network ? delete_site_option($option) : delete_option($option);
		}
	}
}
