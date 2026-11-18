<?php
/**
 * Removes Llamly data when the plugin is deleted (rules in Llamly\Free\Core\Uninstall).
 *
 * WordPress runs this file instead of any uninstall hook, so it also does what the Freemius SDK's uninstall hook would:
 * Uninstall::onUninstall() lets the SDK report the uninstall (only for a site that opted in, or with deactivation
 * feedback) and fire after_uninstall, then runs the cleanup. Being a file, it runs on every delete, including a plugin
 * updated while inactive and a subsite-only activation on multisite, where no uninstall hook was ever registered.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/llamly.php';

// The SDK finds its instance from the hook name (uninstall_<plugin basename>), so the hook carries WordPress's basename.
$llamlyFreeUninstallHook = 'uninstall_' . (is_string(WP_UNINSTALL_PLUGIN) && WP_UNINSTALL_PLUGIN !== '' ? WP_UNINSTALL_PLUGIN : 'llamly/llamly.php');
add_action($llamlyFreeUninstallHook, ['\\Llamly\\Free\\Core\\Uninstall', 'onUninstall']);
do_action($llamlyFreeUninstallHook); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WordPress core's own uninstall_<plugin> action, which the Freemius SDK listens for.
