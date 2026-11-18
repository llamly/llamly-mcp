<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\History\Adapters\OptionObject;

/**
 * Basic site information. Reads never return absolute server paths, database identifiers, the table prefix or
 * credentials. Site settings, Site Health and the PHP error log are administrative and are not offered to agents
 * (agent requests run under Auth\CapabilityCeiling); the option adapter stays so changes journaled by earlier versions
 * can still be undone in Llamly > Activity.
 */
final class SiteTools
{
	public static function register(): void
	{
		Toolkit::read('wp_site_info', 'Read basic site information: name, URLs, WordPress and PHP versions, theme, locale, limits, and the wp-admin links to the site settings.', 'site', 'read', [], [], [self::class, 'siteInfo']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new OptionObject()];
	}

	// ------------------------------------------------------------------ wp_site_info

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function siteInfo(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_site_info', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$theme = wp_get_theme();
		$info = [
			'name' => (string) get_bloginfo('name'),
			'description' => (string) get_bloginfo('description'),
			'home_url' => home_url(),
			'admin_url' => admin_url(),
			'wp_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'multisite' => is_multisite(),
			'permalink_structure' => (string) get_option('permalink_structure'),
			'timezone' => wp_timezone_string(),
			'language' => get_bloginfo('language'),
			'active_theme' => [
				'name' => (string) $theme->get('Name'),
				'slug' => $theme->get_stylesheet(),
				'version' => (string) $theme->get('Version'),
				'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			],
			'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
			'post_types' => array_values(get_post_types(['public' => true])),
			'taxonomies' => array_values(get_taxonomies(['public' => true])),
			'uploads_url' => (string) (wp_get_upload_dir()['baseurl'] ?? ''),
			'debug_mode' => [
				'wp_debug' => defined('WP_DEBUG') && (bool) WP_DEBUG,
				'wp_debug_log' => defined('WP_DEBUG_LOG') && (bool) WP_DEBUG_LOG,
				'wp_debug_display' => defined('WP_DEBUG_DISPLAY') && (bool) WP_DEBUG_DISPLAY,
			],
			'memory_limit' => (string) ini_get('memory_limit'),
			'max_upload_size' => (int) wp_max_upload_size(),
			'elementor_active' => defined('ELEMENTOR_VERSION'),
			'abilities_available' => function_exists('wp_register_ability'),
			'llamly_version' => defined('LLAMLY_FREE_VERSION') ? (string) LLAMLY_FREE_VERSION : null,
			// LLP-469: where a person changes the site settings, and whether this agent can (only through an add-on module).
			'settings_links' => SiteSettings::links(),
			'settings_editable_by_agent' => SiteSettings::editableByAgent($auth),
		];
		return $info;
	}

	// ------------------------------------------------------------------ shared

	/** @param array<string, mixed> $auth */
	private static function gate(string $tool, array $auth): bool|\WP_Error
	{
		$definition = ToolRegistry::get($tool);
		return $definition ? Authorization::requireTool($auth, $definition) : Toolkit::error('tool_not_found', 'The requested tool is not registered.', 404);
	}
}
