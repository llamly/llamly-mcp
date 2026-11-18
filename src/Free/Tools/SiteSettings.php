<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Auth\CapabilityCeiling;
use Llamly\Free\History\MutationRunner;
use Llamly\Free\Modules\ModuleRegistry;

/**
 * Whether the connected agent can change the basic site settings (site title, tagline, homepage, posts page): only
 * through an active add-on module whose write tool takes one of those options (`option_name`), never through the
 * Free itself (Auth\CapabilityCeiling, LLP-449). Used by the MCP instructions and wp_site_info, so an agent that cannot
 * change them gives the user the wp-admin links instead (LLP-469).
 */
final class SiteSettings
{
	/** Options that make a module write tool a site settings tool. */
	private const OPTIONS = ['blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts'];

	/** @return array{general:string,reading:string,permalinks:string} Settings > General, Settings > Reading, Settings > Permalinks. */
	public static function links(): array
	{
		return [
			'general' => admin_url('options-general.php'),
			'reading' => admin_url('options-reading.php'),
			'permalinks' => admin_url('options-permalink.php'),
		];
	}

	/**
	 * The first active module write tool that changes site settings and whether this connection can use it: its scope,
	 * writes enabled and the user's role. The role is read without the ceiling (CapabilityCeiling::roleCan) because the
	 * module relaxes the ceiling only while its own tool runs; this is information for the agent, never a gate (the tool
	 * call itself goes through the usual checks).
	 *
	 * @param array<string, mixed> $auth
	 * @return array{module:string,label:string,editable:bool}|null Null when no active module offers it.
	 */
	public static function moduleAccess(array $auth): ?array
	{
		if (!ModuleRegistry::ready()) {
			return null;
		}
		$found = null;
		foreach (ModuleRegistry::tools() as $name => $tool) {
			$definition = (array) ($tool['definition'] ?? []);
			if (($definition['access'] ?? '') !== 'write' || !self::changesSettings($definition)) {
				continue;
			}
			$owner = ModuleRegistry::ownerOfTool((string) $name);
			$editable = MutationRunner::writeEnabled()
				&& Authorization::canUseTool($auth, ['capability' => 'read'] + $definition)
				&& CapabilityCeiling::roleCan((int) ($auth['user_id'] ?? 0), (string) ($definition['capability'] ?? ''));
			$entry = ['module' => (string) ($owner['id'] ?? ''), 'label' => (string) ($owner['label'] ?? ''), 'editable' => $editable];
			if ($editable) {
				return $entry;
			}
			$found ??= $entry;
		}
		return $found;
	}

	/** @param array<string, mixed> $auth */
	public static function editableByAgent(array $auth): bool
	{
		return (bool) (self::moduleAccess($auth)['editable'] ?? false);
	}

	/**
	 * Line of the MCP instructions while this connection cannot change the site settings ('' when it can). Both wp-admin
	 * links are written in full, so a custom connection without wp_site_info still has them (Diego, LLP-476 comment
	 * 9605, D1). With $shortLinks the two links share the admin URL, for a text that would pass the 3 KB budget (D4).
	 * Last sentence: an active module this connection cannot use, or, with Llamly Pro active and no such module, the
	 * LLP-469 sentence to turn on Site admin (D5a). With Llamly Pro not active, its own paragraph says where this is.
	 *
	 * @param array<string, mixed> $auth
	 */
	public static function instructions(array $auth, bool $shortLinks = false): string
	{
		$access = self::moduleAccess($auth);
		if ($access !== null && $access['editable']) {
			return '';
		}
		$links = self::links();
		$text = 'Site settings cannot be changed through this connection. When the user asks, give both links: '
			. ($shortLinks
				? 'Settings > General and Settings > Reading: options-general.php and options-reading.php in ' . admin_url()
				: 'Settings > General ' . $links['general'] . ' and Settings > Reading ' . $links['reading'])
			. '.';
		if ($access !== null) {
			$text .= ' This connection cannot use the ' . $access['label'] . ' tools that change them.';
		} elseif (\Llamly\Free\Pro\ProFeatures::state() === 'active') {
			$text .= ' Changing them through the agent: turn on the Site admin module in Llamly > Llamly Pro.';
		}
		return $text;
	}

	/** @param array<string, mixed> $definition */
	private static function changesSettings(array $definition): bool
	{
		$enum = $definition['inputSchema']['properties']['option_name']['enum'] ?? null;
		return is_array($enum) && array_intersect(self::OPTIONS, $enum) !== [];
	}
}
