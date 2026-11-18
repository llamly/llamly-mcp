<?php

declare(strict_types=1);

namespace Llamly\Free\Freemius;

use Llamly\Free\Core\Uninstall;

/**
 * Loads the Freemius SDK (vendor/freemius/wordpress-sdk, vendored unchanged by tools/freemius-sdk.sh) only when
 * freemius-settings.php is ready. Development and invalid settings never load it, so no Freemius code runs.
 *
 * WordPress.org rules this class keeps (LLP-455):
 * - Nothing is sent before an administrator clicks "Allow & Continue" on the opt-in screen. "Skip" keeps every
 *   feature; nothing in Llamly reads the opt-in state.
 * - No redirect on activation. The opt-in screen and every Freemius notice show only on Llamly screens.
 * - The SDK is the free WordPress.org build (is_org_compliant): updates come from WordPress.org only.
 */
final class Sdk
{
	private static ?object $fs = null;
	private static bool $failed = false;
	private static ?Config $config = null;

	public static function boot(): void
	{
		if (self::$fs !== null || self::$failed) {
			return;
		}
		$config = self::config();
		if ($config->state() !== Config::READY) {
			return;
		}
		try {
			require_once LLAMLY_FREE_ROOT . '/vendor/freemius/wordpress-sdk/start.php';
			if (!function_exists('fs_dynamic_init')) {
				throw new \RuntimeException('fs_dynamic_init missing');
			}
			$fs = fs_dynamic_init($config->sdkArgs());
			if (!is_object($fs)) {
				throw new \RuntimeException('fs_dynamic_init returned no instance');
			}
			$fs->add_filter('redirect_on_activation', '__return_false');
			// Llamly's own mark on the opt-in screen. Without it the SDK copies a default icon into its folder, or on a
			// local site downloads one from Freemius or WordPress.org (before any consent).
			$fs->add_filter('plugin_icon', [self::class, 'icon']);
			$fs->add_filter('show_admin_notice', [self::class, 'showAdminNotice'], 10, 2);
			// llamly/uninstall.php calls the SDK uninstall hook (Core\Uninstall::onUninstall), which fires after_uninstall.
			$fs->add_action('after_uninstall', [Uninstall::class, 'run']);
			self::$fs = $fs;
			add_filter('pre_http_request', [self::class, 'holdAddonsList'], 10, 3);
		} catch (\Throwable $error) {
			self::$failed = true;
			self::$fs = null;
		}
	}

	/**
	 * Callback of pre_http_request (LLP-458). With a Freemius add-on of Llamly installed (Llamly Pro), the SDK asks
	 * Freemius for Llamly's add-on list on every admin screen once the opt-in is answered, Skip included (the Contact
	 * menu check reads it). That GET carries the site URL in its user agent, so it would break "nothing is sent unless
	 * the administrator allows it". While Llamly is not connected to Freemius it gets a WP_Error instead (never cached
	 * by the SDK, no network). It goes out as before after Allow, when the add-on is connected, and during Freemius
	 * actions the administrator starts (license activation in Llamly Pro, opt-in).
	 *
	 * @param false|array|\WP_Error $pre
	 * @param array<string,mixed>   $args
	 * @return false|array|\WP_Error
	 */
	public static function holdAddonsList($pre, $args, $url)
	{
		if ($pre !== false || self::$fs === null) {
			return $pre;
		}
		$method = is_array($args) && isset($args['method']) ? (string) $args['method'] : 'GET';
		if (!self::isAddonsListRequest($method, (string) $url, (string) self::config()->sdkArgs()['id'])) {
			return $pre;
		}
		try {
			$fs = self::$fs;
			$connected = $fs->is_registered() || self::addonConnected($fs);
		} catch (\Throwable $error) {
			return $pre;
		}
		if ($connected || self::userFreemiusAction()) {
			return $pre;
		}
		return new \WP_Error('llamly_freemius_not_connected', 'Llamly is not connected to Freemius.');
	}

	/** Whether $url is the GET of Llamly's add-on list (api.freemius.com/v1/plugins/<id>/addons.json, any scheme). */
	public static function isAddonsListRequest(string $method, string $url, string $productId): bool
	{
		if (strtoupper($method) !== 'GET' || $productId === '') {
			return false;
		}
		$parts = wp_parse_url($url);
		if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'api.freemius.com') {
			return false;
		}
		return (string) ($parts['path'] ?? '') === '/v1/plugins/' . $productId . '/addons.json';
	}

	/** An installed Freemius add-on of Llamly that is connected (license activated). */
	private static function addonConnected(object $fs): bool
	{
		$addons = method_exists($fs, 'get_installed_addons') ? $fs->get_installed_addons() : [];
		foreach (is_array($addons) ? $addons : [] as $addon) {
			if (is_object($addon) && method_exists($addon, 'is_registered') && $addon->is_registered()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A Freemius request an administrator started: a Freemius AJAX call (fs_*, such as license activation on the
	 * Llamly Pro screen) or one of the Freemius opt-in and sync actions (fs_action). Skip and any other fs_action do not
	 * count, and neither does a user who cannot manage options.
	 */
	private static function userFreemiusAction(): bool
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only to route the request; the SDK checks its own nonces.
		$ajaxAction = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
		$fsAction = isset($_REQUEST['fs_action']) && is_string($_REQUEST['fs_action']) ? sanitize_key(wp_unslash($_REQUEST['fs_action'])) : '';
		// phpcs:enable
		// Before pluggable.php (early SDK calls) there is no current user yet: not a user action.
		$canManage = function_exists('wp_get_current_user') && current_user_can('manage_options');
		return self::isUserFreemiusAction(wp_doing_ajax(), $ajaxAction, $fsAction, $canManage);
	}

	/** The rule of userFreemiusAction(), apart from the request. */
	public static function isUserFreemiusAction(bool $ajax, string $ajaxAction, string $fsAction, bool $canManage): bool
	{
		if (!$canManage) {
			return false;
		}
		if ($ajax && str_starts_with($ajaxAction, 'fs_')) {
			return true;
		}
		foreach (['_activate_existing', '_activate_new', '_reconnect', '_sync_license', '_sync_user'] as $suffix) {
			if ($fsAction !== '' && str_ends_with($fsAction, $suffix)) {
				return true;
			}
		}
		return false;
	}

	/** An installed Freemius add-on of Llamly that is connected (license activated). Public for the tests. */
	public static function addonConnectedNow(): bool
	{
		return self::$fs !== null && self::addonConnected(self::$fs);
	}

	public static function icon(): string
	{
		return LLAMLY_FREE_ROOT . '/assets/free/assets/brand/llamly-mark.svg';
	}

	public static function config(): Config
	{
		return self::$config ??= Config::load();
	}

	/** The Freemius instance, or null (development or invalid settings, or SDK failure). */
	public static function instance(): ?object
	{
		return self::$fs;
	}

	public static function failed(): bool
	{
		return self::$failed;
	}

	/** Freemius notices (opt-in reminder, connection results) only on Llamly screens (WordPress.org guideline 11). */
	public static function showAdminNotice($show, $message = []): bool
	{
		return $show === true && self::isLlamlyScreen();
	}

	public static function isLlamlyScreen(): bool
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen) {
			return false;
		}
		$id = (string) $screen->id;
		return $id === 'toplevel_page_' . Config::MENU_SLUG || str_starts_with($id, Config::MENU_SLUG . '_page_');
	}
}
