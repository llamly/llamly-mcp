<?php

declare(strict_types=1);

namespace Llamly\Free\Admin;

use Llamly\Free\Freemius\Sdk;

/**
 * Llamly > Llamly Pro while Llamly Pro is not active (LLP-462): what Llamly Pro is, its plans and a link to the
 * Freemius checkout. Llamly Pro registers its own Llamly > Llamly Pro page when it is active, so this one goes away.
 *
 * WordPress.org rules this page keeps:
 * - It is a page inside Llamly's own menu (guideline 5). No admin notice, no banner, no redirect (guideline 11).
 * - Nothing is sent before the administrator asks: the plans come from Freemius only after a click on "Show plans
 *   and prices", or on their own once the administrator has allowed Llamly to connect to Freemius (opt-in).
 *   Rendering the page makes no request. The Buy buttons are plain links to the Freemius checkout, without site or
 *   user data in the URL.
 * - Prices are never in the code: they are what Freemius returns for the Llamly Pro add-on. While Freemius returns no
 *   plan (sale not open yet), the page says that Llamly Pro is coming soon.
 */
final class ProPage
{
	public const SLUG = 'llamly-get-pro';
	private const PARENT = 'llamly';
	private const CACHE = 'llamly_free_pro_plans';
	private const ACTION = 'llamly_free_pro_plans';
	private const CHECKOUT = 'https://checkout.freemius.com/plugin/%s/plan/%s/licenses/%s/';

	private static string $hook = '';

	public static function registerHooks(): void
	{
		// Same priority as Llamly Pro's own page: after Llamly's menu, before the Freemius SDK prepares its pages.
		add_action('admin_menu', [self::class, 'menu'], 20);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
	}

	/** True while Llamly Pro is not active: then this page exists and the Plugins row links to it. */
	public static function available(): bool
	{
		return !defined('LLAMLY_PRO_VERSION');
	}

	public static function url(): string
	{
		return admin_url('admin.php?page=' . self::SLUG);
	}

	public static function menu(): void
	{
		if (!self::available() || empty($GLOBALS['admin_page_hooks'][self::PARENT])) {
			return;
		}
		$hook = add_submenu_page(
			self::PARENT,
			__('Llamly Pro', 'llamly'),
			__('Llamly Pro', 'llamly'),
			'manage_options',
			self::SLUG,
			[self::class, 'render']
		);
		if (is_string($hook) && $hook !== '') {
			self::$hook = $hook;
			add_action('load-' . $hook, [self::class, 'load']);
		}
	}

	public static function enqueueAssets(string $hook): void
	{
		if (self::$hook === '' || $hook !== self::$hook || !current_user_can('manage_options')) {
			return;
		}
		$version = defined('LLAMLY_FREE_VERSION') ? LLAMLY_FREE_VERSION : '1.1.1';
		foreach (['tokens', 'llamly', 'admin-app', 'pro'] as $style) {
			wp_enqueue_style('llamly-free-' . $style, LLAMLY_FREE_ASSET_URL . 'css/' . $style . '.css', [], $version);
		}
	}

	/** load-{page}: "Show plans and prices" (and "Check again") fetch the plans, then come back to the plain page URL. */
	public static function load(): void
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below.
		if (!isset($_GET[self::ACTION]) || !current_user_can('manage_options')) {
			return;
		}
		check_admin_referer(self::ACTION);
		$result = self::fetch();
		$args = ['page' => self::SLUG];
		if ($result === null) {
			$args['plans'] = 'error';
		}
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')), 303);
		exit;
	}

	/**
	 * What the Plans section shows: 'ask' (no plans loaded yet, the administrator has not allowed or clicked), 'plans'
	 * (Freemius returned at least one yearly price), 'soon' (Freemius returned no plan, or there is no Freemius in this
	 * build) or 'error' (Freemius could not be reached; nothing cached, the button stays).
	 *
	 * @return array{state:string, plans:list<array{plan:string,licenses:int,annual:float}>}
	 */
	public static function view(bool $lastFetchFailed = false): array
	{
		if (Sdk::instance() === null || self::addonId() === '') {
			return ['state' => 'soon', 'plans' => []];
		}
		$cached = get_transient(self::CACHE);
		if (is_array($cached) && isset($cached['plans']) && is_array($cached['plans'])) {
			return ['state' => $cached['plans'] === [] ? 'soon' : 'plans', 'plans' => $cached['plans']];
		}
		if ($lastFetchFailed) {
			return ['state' => 'error', 'plans' => []];
		}
		if (!self::consented()) {
			return ['state' => 'ask', 'plans' => []];
		}
		$plans = self::fetch(); // Only with the opt-in on: the SDK already talks to Freemius.
		if ($plans === null) {
			return ['state' => 'error', 'plans' => []];
		}
		return ['state' => $plans === [] ? 'soon' : 'plans', 'plans' => $plans];
	}

	/** The administrator allowed Llamly to connect to Freemius (opt-in) and has not opted out since. */
	private static function consented(): bool
	{
		return self::consentedFor(Sdk::instance());
	}

	/**
	 * The rule of consented(), apart from the SDK instance. is_registered() stays true after an opt-out (Llamly >
	 * Account > Opt Out only turns tracking off), so the opt-in counts only while tracking is still allowed.
	 */
	public static function consentedFor(?object $fs): bool
	{
		try {
			return $fs !== null && method_exists($fs, 'is_registered') && method_exists($fs, 'is_tracking_allowed')
				&& $fs->is_registered() === true && $fs->is_tracking_allowed() === true;
		} catch (\Throwable $error) {
			return false;
		}
	}

	/**
	 * Reads the Llamly Pro plans from Freemius through Llamly's SDK (public key only) and caches them: 12 hours when
	 * there are plans, 1 hour when there are none. Returns null, and caches nothing, when Freemius cannot be reached.
	 *
	 * @return list<array{plan:string,licenses:int,annual:float}>|null
	 */
	private static function fetch(): ?array
	{
		$fs = Sdk::instance();
		$addonId = self::addonId();
		if ($fs === null || $addonId === '' || !method_exists($fs, 'get_api_plugin_scope')) {
			return null;
		}
		try {
			$result = $fs->get_api_plugin_scope()->call('/addons/' . $addonId . '/pricing.json?type=visible');
		} catch (\Throwable $error) {
			return null;
		}
		$plans = self::parse($result);
		if ($plans === null) {
			return null;
		}
		set_transient(self::CACHE, ['plans' => $plans], $plans === [] ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS);
		return $plans;
	}

	/**
	 * The yearly US dollar prices of a Freemius pricing.json answer, by number of sites. Freemius answers
	 * {"plugin": {...}, "plans": [{"id", "pricing": [{"licenses", "annual_price", "currency"}, ...]}, ...]}. Prices that
	 * are not yearly, not in USD or for unlimited sites are left out (Llamly Pro is sold per year and per number of
	 * sites, decision 4.44). Null when the answer is an error or is not that shape.
	 *
	 * @return list<array{plan:string,licenses:int,annual:float}>|null
	 */
	public static function parse($result): ?array
	{
		if (!is_object($result) || isset($result->error) || !isset($result->plans) || !is_array($result->plans)) {
			return null;
		}
		$plans = [];
		foreach ($result->plans as $plan) {
			$planId = is_object($plan) && isset($plan->id) ? (string) $plan->id : '';
			if (!preg_match('/^[1-9]\d{0,11}$/', $planId) || !isset($plan->pricing) || !is_array($plan->pricing)) {
				continue;
			}
			foreach ($plan->pricing as $pricing) {
				if (!is_object($pricing)) {
					continue;
				}
				$currency = strtolower((string) ($pricing->currency ?? 'usd'));
				$licenses = $pricing->licenses ?? null;
				$annual = $pricing->annual_price ?? null;
				if ($currency !== 'usd' || !is_numeric($licenses) || (int) $licenses < 1 || !is_numeric($annual) || (float) $annual <= 0) {
					continue;
				}
				$plans[(int) $licenses] = ['plan' => $planId, 'licenses' => (int) $licenses, 'annual' => (float) $annual];
			}
		}
		ksort($plans);
		return array_values($plans);
	}

	/** Freemius checkout of Llamly Pro for that plan and number of sites, or '' when an id is not valid. */
	public static function checkoutUrl(string $planId, int $licenses): string
	{
		$addonId = self::addonId();
		if ($addonId === '' || !preg_match('/^[1-9]\d{0,11}$/', $planId) || $licenses < 1) {
			return '';
		}
		return sprintf(self::CHECKOUT, $addonId, $planId, (string) $licenses);
	}

	private static function addonId(): string
	{
		return Sdk::config()->proAddonId();
	}

	/** Llamly Pro is installed (any folder with its main file) but not active. */
	private static function proInstalled(): bool
	{
		return \Llamly\Free\Pro\ProFeatures::state() === 'inactive';
	}

	public static function render(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access Llamly.', 'llamly'));
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by load() after its nonce check.
		$failed = isset($_GET['plans']) && sanitize_key(wp_unslash($_GET['plans'])) === 'error';
		$view = self::view($failed);
		$fetchUrl = wp_nonce_url(add_query_arg([self::ACTION => '1'], self::url()), self::ACTION);

		echo '<div class="wrap llamly-admin-wrap llamly-pro-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__('Llamly Pro', 'llamly') . '</h1>';
		echo '<div class="llamly"><div class="ll-main">';

		echo '<section class="ll-card lp-hero" aria-labelledby="lp-title">';
		echo '<img class="lp-hero__mark" src="' . esc_url(LLAMLY_FREE_ASSET_URL . 'assets/brand/llamly-mark.svg') . '" alt="" width="72" height="72">';
		echo '<div class="lp-hero__text"><h2 id="lp-title">' . esc_html__('Llamly Pro', 'llamly') . '</h2>';
		echo '<p>' . esc_html__('Llamly Pro is an optional add-on with extra modules for your agent. The first one, Llamly Builder, builds pages in Elementor and in the block editor.', 'llamly') . '</p>';
		echo '<p class="ll-muted">' . esc_html__('Everything in Llamly is free and stays free. Llamly Pro only adds modules.', 'llamly') . '</p></div>';
		echo '</section>';

		if (self::proInstalled()) {
			echo '<div class="ll-callout" role="status"><div class="ll-callout__body"><p class="ll-callout__title">' . esc_html__('Llamly Pro is installed but not active.', 'llamly') . '</p>';
			echo '<p><a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Activate it on the Plugins screen', 'llamly') . '</a></p></div></div>';
		}

		echo '<section aria-labelledby="lp-plans-title"><div class="ll-section-head"><div>';
		echo '<h2 class="ll-section-title" id="lp-plans-title">' . esc_html__('Plans', 'llamly') . '</h2>';
		echo '<p class="ll-section-desc">' . esc_html__('A yearly license for a number of sites. Freemius sells Llamly Pro and handles payment and licenses.', 'llamly') . '</p>';
		echo '</div></div>';

		switch ($view['state']) {
			case 'plans':
				self::renderPlans($view['plans']);
				break;
			case 'ask':
				echo '<div class="ll-card lp-state"><p>' . esc_html__('Plans and prices come from Freemius. Llamly contacts Freemius only when you click the button.', 'llamly') . '</p>';
				echo '<a class="ll-btn ll-btn--primary" href="' . esc_url($fetchUrl) . '">' . esc_html__('Show plans and prices', 'llamly') . '</a></div>';
				break;
			case 'error':
				echo '<div class="ll-card lp-state"><p>' . esc_html__('Llamly could not load the plans from Freemius. Check the connection of this site and try again.', 'llamly') . '</p>';
				echo '<a class="ll-btn" href="' . esc_url($fetchUrl) . '">' . esc_html__('Try again', 'llamly') . '</a></div>';
				break;
			default:
				echo '<div class="ll-card lp-state" data-lp-state="soon"><p class="lp-state__title">' . esc_html__('Llamly Pro is coming soon.', 'llamly') . '</p>';
				echo '<p class="ll-muted">' . esc_html__('The plans are not on sale yet.', 'llamly') . '</p>';
				if (Sdk::instance() !== null && self::addonId() !== '') {
					echo '<a class="ll-btn" href="' . esc_url($fetchUrl) . '">' . esc_html__('Check again', 'llamly') . '</a>';
				}
				echo '</div>';
		}
		echo '</section></div></div></div>';
	}

	/** @param list<array{plan:string,licenses:int,annual:float}> $plans */
	private static function renderPlans(array $plans): void
	{
		echo '<ul class="lp-plans">';
		foreach ($plans as $plan) {
			$checkout = self::checkoutUrl($plan['plan'], $plan['licenses']);
			/* translators: %d: number of sites the license covers. */
			$sites = sprintf(_n('%d site', '%d sites', $plan['licenses'], 'llamly'), $plan['licenses']);
			$price = '$' . number_format_i18n($plan['annual'], floor($plan['annual']) === $plan['annual'] ? 0 : 2);
			echo '<li class="ll-card lp-plan" data-licenses="' . esc_attr((string) $plan['licenses']) . '">';
			echo '<h3 class="lp-plan__sites">' . esc_html($sites) . '</h3>';
			/* translators: %s: yearly price, for example $59. */
			echo '<p class="lp-plan__price">' . wp_kses(sprintf(__('%s <span>per year</span>', 'llamly'), '<strong>' . esc_html($price) . '</strong>'), ['strong' => [], 'span' => []]) . '</p>';
			if ($checkout !== '') {
				/* translators: %s: number of sites, for example "5 sites". */
				$label = sprintf(__('Buy Llamly Pro for %s (opens Freemius checkout in a new tab)', 'llamly'), $sites);
				echo '<a class="ll-btn ll-btn--primary lp-plan__buy" href="' . esc_url($checkout) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr($label) . '">' . esc_html__('Buy', 'llamly') . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="ll-faint lp-note">' . esc_html__('The checkout opens on freemius.com in a new tab. After the purchase, Freemius sends you the Llamly Pro download and license key by email.', 'llamly') . '</p>';
	}
}
