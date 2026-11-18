<?php

declare(strict_types=1);

namespace Llamly\Free\Auth;

/**
 * Capability ceiling for agent requests (MCP and the Abilities bridge). While a request runs, the WordPress user behind
 * the credential keeps their content capabilities but never the administrative ones below, whatever their role: no
 * site settings, users, plugins, themes, code files, import/export, network administration, or unfiltered HTML. So
 * an administrator's key edits content like an editor, and every piece of markup goes through the content policy.
 *
 * The list passes through the filter `llamly_mcp_capability_ceiling` ($denied, $auth) so an add-on module that needs one
 * of these capabilities can relax it explicitly. Without such an add-on the ceiling is always the full list.
 * The ceiling applies only to the credential's user and only inside run(); it is removed in a finally block.
 */
final class CapabilityCeiling
{
	public const DENIED = [
		'unfiltered_html', 'unfiltered_upload', 'manage_options',
		'edit_users', 'create_users', 'delete_users', 'promote_users', 'remove_users', 'add_users',
		'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'resume_plugins',
		'install_themes', 'switch_themes', 'update_themes', 'delete_themes', 'edit_themes', 'resume_themes',
		'edit_files', 'update_core', 'install_languages', 'update_languages', 'export', 'import', 'view_site_health_checks',
		'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes',
		'manage_network_options', 'upgrade_network', 'setup_network', 'create_sites', 'delete_sites', 'delete_site',
	];

	/** @var list<array{user_id:int, denied:array<string, true>}> */
	private static array $stack = [];

	/**
	 * Runs $callback as the credential's user under the ceiling, then restores the previous user and filters.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function run(array $auth, callable $callback): mixed
	{
		$userId = (int) ($auth['user_id'] ?? 0);
		$originalUser = get_current_user_id();
		self::$stack[] = ['user_id' => $userId, 'denied' => array_fill_keys(self::denied($auth), true)];
		if (count(self::$stack) === 1) {
			add_filter('map_meta_cap', [self::class, 'filterMetaCaps'], PHP_INT_MAX, 4);
			add_filter('user_has_cap', [self::class, 'filterUserCaps'], PHP_INT_MAX, 4);
		}
		wp_set_current_user($userId);
		// kses decides its filters from the current user; set_current_user does not fire when the user is the same.
		kses_init();
		try {
			return $callback();
		} finally {
			array_pop(self::$stack);
			if (!self::$stack) {
				remove_filter('map_meta_cap', [self::class, 'filterMetaCaps'], PHP_INT_MAX);
				remove_filter('user_has_cap', [self::class, 'filterUserCaps'], PHP_INT_MAX);
			}
			wp_set_current_user($originalUser);
			kses_init();
		}
	}

	/** @return list<string> The capabilities denied to this credential (the full list unless an add-on relaxed it). */
	public static function denied(array $auth): array
	{
		$denied = apply_filters('llamly_mcp_capability_ceiling', self::DENIED, $auth);
		if (!is_array($denied)) {
			return self::DENIED;
		}
		return array_values(array_unique(array_filter(array_map('strval', $denied), static fn(string $cap): bool => $cap !== '')));
	}

	/**
	 * What the user's role allows, ignoring the ceiling for this one check. Only for read visibility that must stay as it
	 * was before the ceiling (which users' profiles and e-mails a connection may read); never for a write or a tool gate.
	 */
	public static function roleCan(int $userId, string $capability, mixed ...$args): bool
	{
		self::$stack[] = ['user_id' => 0, 'denied' => []];
		try {
			return user_can($userId, $capability, ...$args);
		} finally {
			array_pop(self::$stack);
		}
	}

	public static function active(): bool
	{
		return self::$stack !== [];
	}

	/**
	 * Primary gate: a capability check of the credential's user that is, or maps to, a denied capability resolves to
	 * do_not_allow. This also covers multisite super admins, for whom WordPress skips user_has_cap.
	 *
	 * @param list<string> $caps
	 * @return list<string>
	 */
	public static function filterMetaCaps($caps, $cap, $userId, $args): array
	{
		$caps = is_array($caps) ? $caps : [];
		$denied = self::deniedFor((int) $userId);
		if ($denied !== null && (isset($denied[(string) $cap]) || array_intersect_key(array_flip(array_map('strval', $caps)), $denied))) {
			return ['do_not_allow'];
		}
		return $caps;
	}

	/**
	 * Second gate for code that checks the primitive capability list directly.
	 *
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	public static function filterUserCaps($allcaps, $caps, $args, $user): array
	{
		$allcaps = is_array($allcaps) ? $allcaps : [];
		$denied = $user instanceof \WP_User ? self::deniedFor((int) $user->ID) : null;
		if ($denied !== null) {
			foreach ($denied as $cap => $unused) {
				$allcaps[$cap] = false;
			}
		}
		return $allcaps;
	}

	/** @return array<string, true>|null */
	private static function deniedFor(int $userId): ?array
	{
		$top = end(self::$stack);
		return $top !== false && $userId > 0 && $top['user_id'] === $userId ? $top['denied'] : null;
	}
}
