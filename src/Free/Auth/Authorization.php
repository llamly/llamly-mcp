<?php

declare(strict_types=1);

namespace Llamly\Free\Auth;

use Llamly\Free\Core\Settings;

final class Authorization
{
	public static function canUseTool(array $auth, array $tool, ?int $objectId = null): bool
	{
		$userId = (int) ($auth['user_id'] ?? 0);
		if ($userId < 1 || !get_user_by('id', $userId)) {
			return false;
		}
		if (is_multisite() && !is_user_member_of_blog($userId, get_current_blog_id())) {
			return false;
		}

		$mode = (string) ($auth['scope_mode'] ?? 'invalid');
		$name = (string) ($tool['name'] ?? '');
		$access = (string) ($tool['access'] ?? '');
		$allowed = $auth['allowed_tools'] ?? null;
		if ($mode === 'read_only' && $access !== 'read') {
			return false;
		}
		if ($mode === 'custom' && (!is_array($allowed) || !in_array($name, $allowed, true))) {
			return false;
		}
		if (!in_array($mode, ['full', 'read_only', 'custom'], true)) {
			return false;
		}

		$settings = Settings::get();
		if (empty($settings['_valid']) || empty($settings['enabled'])) {
			return false;
		}
		$capability = (string) ($tool['capability'] ?? '');
		if ($capability === '') {
			return false;
		}
		if ($objectId === null) {
			// Meta capabilities need an object; without one, check the primitive capability behind them.
			$primitive = ['edit_post' => 'edit_posts', 'read_post' => 'read', 'delete_post' => 'delete_posts', 'edit_page' => 'edit_pages', 'delete_page' => 'delete_pages'];
			return user_can($userId, $primitive[$capability] ?? $capability);
		}
		return user_can($userId, $capability, $objectId);
	}

	public static function requireTool(array $auth, array $tool, ?int $objectId = null): bool|\WP_Error
	{
		if (!self::canUseTool($auth, $tool, $objectId)) {
			return new \WP_Error('forbidden', __('This connected user or credential cannot use the requested tool.', 'llamly'), ['status' => 403]);
		}
		return true;
	}
}
