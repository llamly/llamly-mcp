<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Admin;

use Llamly\Free\Core\Schema;

final class AdminPage
{
	private const MENU_SLUG = 'llamly';
	/** @var array<string, array{label:string, renderer:callable, capability:string}> */
	private static array $registeredSections = [];
	private static bool $sectionsRegistered = false;

	public static function registerHooks(): void
	{
		if (!self::$sectionsRegistered) {
			self::$sectionsRegistered = true;
			do_action('llamly_free_register_admin_sections');
		}
		add_action('admin_menu', [self::class, 'registerMenu']);
		ProPage::registerHooks();
		add_action('admin_enqueue_scripts', [self::class, 'menuIconStyle']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
		add_action('admin_post_llamly_free_install_schema', [self::class, 'installSchema']);
		add_action('admin_notices', [self::class, 'renderOnboardingNotice']);
		add_action('admin_post_llamly_free_dismiss_onboarding', [self::class, 'dismissOnboardingNotice']);
		if (defined('LLAMLY_FREE_PLUGIN_FILE')) {
			add_filter('plugin_action_links_' . plugin_basename(LLAMLY_FREE_PLUGIN_FILE), [self::class, 'pluginActionLinks']);
		}
	}

	private static function proActive(): bool
	{
		return defined('LLAMLY_PRO_VERSION');
	}

	/**
	 * Plugins screen row: a "Get Llamly Pro" link to Llamly > Llamly Pro (ProPage, a page inside Llamly), only while
	 * Llamly Pro is not active. That page is the one place that points outside (WordPress.org guidelines 5 and 11).
	 *
	 * @param array<string,string> $links
	 * @return array<string,string>
	 */
	public static function pluginActionLinks($links): array
	{
		$links = is_array($links) ? $links : [];
		if (ProPage::available() && current_user_can('manage_options')) {
			$links['llamly_pro'] = '<a href="' . esc_url(ProPage::url()) . '">' . esc_html__('Get Llamly Pro', 'llamly') . '</a>';
		}
		return $links;
	}

	public static function registerAdminSection(string $id, string $label, callable $renderer, string $capability = 'manage_options'): bool
	{
		$id = sanitize_key($id);
		$label = sanitize_text_field($label);
		if ($id === '' || strlen($id) > 64 || !preg_match('/^[a-z][a-z0-9_-]*$/', $id)
			|| $label === '' || strlen($label) > 80 || $capability === '' || isset(self::$registeredSections[$id])
		) {
			return false;
		}
		self::$registeredSections[$id] = ['label' => $label, 'renderer' => $renderer, 'capability' => sanitize_key($capability)];
		return true;
	}

	/** @return list<array{id:string,label:string,html:string}> */
	private static function sectionsForCurrentUser(): array
	{
		$sections = [];
		foreach (self::$registeredSections + \Llamly\Free\Modules\ModuleRegistry::sections() as $id => $section) {
			if (!current_user_can($section['capability'])) {
				continue;
			}
			ob_start();
			try {
				$returned = ($section['renderer'])();
			} catch (\Throwable $error) {
				$returned = '';
			}
			$html = (string) ob_get_clean() . (is_string($returned) ? $returned : '');
			$sections[] = ['id' => $id, 'label' => $section['label'], 'html' => wp_kses_post($html)];
		}
		return $sections;
	}

	/** The brand mark is a URL icon, which WordPress renders as an unsized <img>; keep it at the menu's 20px. */
	public static function menuIconStyle(): void
	{
		wp_add_inline_style('admin-menu', '#toplevel_page_' . sanitize_html_class(self::MENU_SLUG) . ' .wp-menu-image img{width:20px;height:20px;padding-top:7px;opacity:1}');
	}

	/** Legacy (pt-BR) tab slugs from earlier builds, mapped to the English slugs. This is the only place they appear. */
	private const LEGACY_TABS = ['conexoes' => 'connections', 'atividade' => 'activity', 'configuracoes' => 'settings', 'sobre' => 'about'];

	/** Runs on load-{page}, before any output, so the redirect can still send headers. */
	public static function redirectLegacyTab(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter.
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		if ($tab === '' || !isset(self::LEGACY_TABS[$tab]) || !current_user_can('manage_options')) {
			return;
		}
		$args = [];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameters.
		foreach (array_keys($_GET) as $key) {
			if (is_string($key) && $key !== 'tab' && isset($_GET[$key]) && is_scalar($_GET[$key])) {
				$args[sanitize_key($key)] = sanitize_text_field(wp_unslash((string) $_GET[$key]));
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args['page'] = self::MENU_SLUG;
		$args['tab'] = self::LEGACY_TABS[$tab];
		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')), 302);
		exit;
	}

	public static function registerMenu(): void
	{
		$hook = add_menu_page(
			esc_html__('Llamly', 'llamly'),
			esc_html__('Llamly', 'llamly'),
			'manage_options',
			self::MENU_SLUG,
			[self::class, 'render'],
			LLAMLY_FREE_ASSET_URL . 'assets/brand/llamly-mark.svg',
			58
		);
		if (is_string($hook) && $hook !== '') {
			add_action('load-' . $hook, [self::class, 'redirectLegacyTab']);
		}
	}

	public static function enqueueAssets(string $hook): void
	{
		if ($hook !== 'toplevel_page_' . self::MENU_SLUG
			|| ! current_user_can('manage_options')
			|| (int) get_option(Schema::OPTION_VERSION, 0) !== Schema::VERSION
		) {
			return;
		}

		$assetVersion = defined('LLAMLY_FREE_VERSION') ? LLAMLY_FREE_VERSION : '1.1.1';
		$base = LLAMLY_FREE_ASSET_URL;
		foreach (['tokens', 'llamly', 'connections', 'activity', 'logs', 'settings', 'about', 'admin-app'] as $style) {
			wp_enqueue_style('llamly-free-' . $style, $base . 'css/' . $style . '.css', [], $assetVersion);
		}
		wp_enqueue_script('llamly-free-icons', $base . 'js/icons.js', [], $assetVersion, true);
		wp_enqueue_script('llamly-free-admin', $base . 'js/admin.js', ['llamly-free-icons', 'wp-i18n'], $assetVersion, true);
		wp_set_script_translations('llamly-free-admin', 'llamly');

		$adminRoot = rest_url('llamly/v1/admin/');
		wp_localize_script('llamly-free-admin', 'LLAMLY_FREE_BOOTSTRAP', [
			'adminRoot' => esc_url_raw($adminRoot),
			'assetUrl' => esc_url_raw($base),
			'mcpEndpoint' => esc_url_raw(rest_url('llamly/v1/endpoint')),
			'restIndex' => esc_url_raw(rest_url()),
			'protectedResource' => esc_url_raw(home_url('/.well-known/oauth-protected-resource')),
			'authorizationServer' => esc_url_raw(home_url('/.well-known/oauth-authorization-server')),
			'nonce' => wp_create_nonce('wp_rest'),
			'pageUrl' => esc_url_raw(admin_url('admin.php?page=' . self::MENU_SLUG)),
			'adminUrl' => esc_url_raw(admin_url()),
			'exportLogsUrl' => wp_nonce_url(admin_url('admin-post.php?action=llamly_free_export_logs'), 'llamly_free_export_logs'),
			'sections' => self::sectionsForCurrentUser(),
			'pro' => ['page' => ProPage::available() ? esc_url_raw(ProPage::url()) : '', 'active' => self::proActive()],
			'site' => [
				'name' => wp_strip_all_tags(get_bloginfo('name')),
				'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
				'user' => wp_strip_all_tags(wp_get_current_user()->display_name),
			],
		]);
	}

	public static function renderOnboardingNotice(): void
	{
		// Only right after activation (Plugins) and on the Dashboard; never on other admin screens.
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || !in_array((string) $screen->id, ['dashboard', 'plugins'], true)
			|| !current_user_can('manage_options') || (int) get_option(Schema::OPTION_VERSION, 0) !== Schema::VERSION
			|| get_user_meta(get_current_user_id(), 'llamly_free_onboarding_dismissed', true)
		) {
			return;
		}
		global $wpdb;
		$tables = Schema::tableNames();
		$hasKey = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL', $tables['credentials'])) > 0;
		$hasGrant = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL', $tables['oauth_grants'])) > 0;
		if ($hasKey || $hasGrant) {
			return;
		}
		$dismissUrl = wp_nonce_url(admin_url('admin-post.php?action=llamly_free_dismiss_onboarding'), 'llamly_free_dismiss_onboarding');
		echo '<div class="notice notice-info"><p><strong>' . esc_html__('Llamly is ready.', 'llamly') . '</strong> ';
		echo esc_html__('Connect Claude, ChatGPT or another AI app to this site. It takes about a minute.', 'llamly') . '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=connections')) . '">' . esc_html__('Connect an app', 'llamly') . '</a> ';
		echo '<a class="button-link" href="' . esc_url($dismissUrl) . '">' . esc_html__('Dismiss', 'llamly') . '</a></p></div>';
	}

	public static function dismissOnboardingNotice(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to dismiss this notice.', 'llamly'), '', ['response' => 403]);
		}
		check_admin_referer('llamly_free_dismiss_onboarding');
		update_user_meta(get_current_user_id(), 'llamly_free_onboarding_dismissed', time());
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	public static function render(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access Llamly.', 'llamly'));
		}
		if ((int) get_option(Schema::OPTION_VERSION, 0) !== Schema::VERSION) {
			echo '<div class="wrap"><h1>' . esc_html__('Llamly setup', 'llamly') . '</h1>';
			echo '<p>' . esc_html__('The required database tables are not ready. Retry the schema check before connecting clients.', 'llamly') . '</p>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			echo '<input type="hidden" name="action" value="llamly_free_install_schema">';
			wp_nonce_field('llamly_free_install_schema');
			submit_button(esc_html__('Verify database setup', 'llamly'));
			echo '</form></div>';
			return;
		}

		echo '<div class="wrap llamly-admin-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__('Llamly', 'llamly') . '</h1>';
		echo '<div id="ll-app" class="llamly" aria-live="polite"></div>';
		self::renderRegisteredSections();
		echo '</div>';
	}

	private static function renderRegisteredSections(): void
	{
		foreach (self::$registeredSections as $id => $section) {
			if (!current_user_can($section['capability'])) {
				continue;
			}
			echo '<section class="llamly ll-card ll-extension-section" id="ll-extension-' . esc_attr($id) . '" aria-labelledby="ll-extension-title-' . esc_attr($id) . '">';
			echo '<div class="ll-card__head"><h2 class="ll-card__title" id="ll-extension-title-' . esc_attr($id) . '">' . esc_html($section['label']) . '</h2></div>';
			echo '<div class="ll-card__body">';
			call_user_func($section['renderer']);
			echo '</div></section>';
		}
	}

	public static function installSchema(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to update Llamly.', 'llamly'));
		}
		check_admin_referer('llamly_free_install_schema');
		$result = Schema::install();
		$target = add_query_arg(
			['page' => self::MENU_SLUG, 'schema' => $result ? 'ready' : 'error'],
			admin_url('admin.php')
		);
		wp_safe_redirect($target);
		exit;
	}
}
