<?php
/**
 * Plugin Name: Llamly MCP - AI Agent with ChatGPT & Claude
 * Description: Connect AI clients to WordPress through the Llamly MCP server.
 * Version: 1.1.1
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Llamly
 * License: GPL-2.0-or-later
 * Text Domain: llamly
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('LLAMLY_FREE_PLUGIN_FILE', __FILE__);
define('LLAMLY_FREE_VERSION', '1.1.1');
// Writes are on by default: concurrent writes and the change journal were verified on WP 7.1 + MariaDB 10.11 and
// WP 6.2 + PHP 8.0 + MySQL 8.0. A site can still pause all writes by defining this constant as false in wp-config.php.
if (!defined('LLAMLY_FREE_MUTATIONS_VERIFIED')) {
	define('LLAMLY_FREE_MUTATIONS_VERIFIED', true);
}

define('LLAMLY_FREE_DIR', plugin_dir_path(__FILE__));
define('LLAMLY_FREE_URL', plugin_dir_url(__FILE__));

// The package has src/ and assets/ next to this file. In the development checkout this file lives in llamly/ and they
// are one folder up (plugin_dir_path() of this folder is that parent folder).
$llamlyFreeInDevCheckout = is_file(plugin_dir_path(LLAMLY_FREE_DIR) . 'src/Free/Core/Autoloader.php');
define('LLAMLY_FREE_ROOT', untrailingslashit($llamlyFreeInDevCheckout ? plugin_dir_path(LLAMLY_FREE_DIR) : LLAMLY_FREE_DIR));
// plugin_dir_url() of a path inside assets/free is the URL of that folder (the file name itself is not read).
define('LLAMLY_FREE_ASSET_URL', is_dir(LLAMLY_FREE_ROOT . '/assets/free') ? plugin_dir_url(LLAMLY_FREE_ROOT . '/assets/free/index') : '');

require_once LLAMLY_FREE_ROOT . '/src/Free/Core/Autoloader.php';

\Llamly\Free\Core\Autoloader::register();

// Version of the add-on module contract (src/Free/Modules). Add-ons also receive it with the llamly_free_loaded action.
define('LLAMLY_FREE_MODULE_API', \Llamly\Free\Modules\ModuleRegistry::API);

// Freemius (LLP-455): usage statistics after an explicit opt-in, and the Llamly Pro add-on. Loaded here, in the main
// file, so the SDK sees activation and deactivation. Without ready settings it is not loaded and nothing is defined.
\Llamly\Free\Freemius\Sdk::boot();
if (\Llamly\Free\Freemius\Sdk::instance() !== null && !function_exists('llamly_fs')) {
	/**
	 * The Freemius instance of Llamly, for Freemius add-ons of Llamly (they check function_exists('llamly_fs') or wait
	 * for the llamly_fs_loaded action). Defined only when the SDK is loaded.
	 *
	 * @return \Freemius
	 */
	function llamly_fs() {
		return \Llamly\Free\Freemius\Sdk::instance();
	}
	do_action('llamly_fs_loaded');
}

register_activation_hook(__FILE__, ['\\Llamly\\Free\\Core\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['\\Llamly\\Free\\Core\\Plugin', 'deactivate']);
add_action('plugins_loaded', ['\\Llamly\\Free\\Core\\Plugin', 'boot']);
