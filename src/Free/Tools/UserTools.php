<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\CapabilityCeiling;

/**
 * User tools, read-only: users are never created, changed or deleted through Llamly (that stays in wp-admin).
 * Output never carries password hashes, activation keys, session tokens, Application Passwords or raw capabilities.
 */
final class UserTools
{
	private const CATEGORY = 'users';
	private const ORDERBY = ['ID' => 'ID', 'login' => 'login', 'display_name' => 'display_name', 'registered' => 'registered', 'post_count' => 'post_count'];

	public static function register(): void
	{
		$id = ['type' => 'integer', 'minimum' => 1];
		$role = ['type' => 'string', 'minLength' => 1, 'maxLength' => 60, 'pattern' => '^[a-z0-9_-]+$', 'description' => 'Only users with this role (role slug, for example editor).'];
		Toolkit::read('wp_list_users', 'List users with their roles. E-mail is shown only for users the connected user can edit. Never returns passwords, keys, tokens or raw capabilities.', self::CATEGORY, 'list_users', [
			'search' => ['type' => 'string', 'maxLength' => 100],
			'role' => $role,
			'orderby' => ['type' => 'string', 'enum' => array_keys(self::ORDERBY), 'default' => 'ID'],
			'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'ASC'],
		] + Toolkit::pageProps(), [], [self::class, 'listUsers']);

		Toolkit::read('wp_get_user', 'Read one user by exactly one of user_id, login or email: profile, roles and post count. Never returns passwords, keys, tokens or raw capabilities.', self::CATEGORY, 'list_users', [
			'user_id' => $id,
			'login' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
			'email' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 100],
		], [], [self::class, 'getUser']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [];
	}

	// ------------------------------------------------------------------ reads

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function listUsers(array $args, array $auth): array|\WP_Error
	{
		$actorId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$query = [
			'number' => $perPage,
			'paged' => $page,
			'orderby' => self::ORDERBY[(string) ($args['orderby'] ?? 'ID')] ?? 'ID',
			'order' => (string) ($args['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC',
			'count_total' => true,
			'fields' => 'all',
		];
		if (isset($args['role'])) {
			$role = (string) $args['role'];
			if (!wp_roles()->is_role($role)) {
				return Toolkit::error('invalid_arguments', __('Unknown role.', 'llamly'), 400);
			}
			$query['role'] = $role;
		}
		$search = trim((string) ($args['search'] ?? ''));
		if ($search !== '') {
			$query['search'] = '*' . str_replace('*', '', $search) . '*';
			$query['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
		}
		$userQuery = new \WP_User_Query($query);
		$users = (array) $userQuery->get_results();
		$items = [];
		foreach ($users as $user) {
			$uid = (int) $user->ID;
			// Read visibility follows the role, as before the capability ceiling (users stay readable, never writable).
			$canEdit = CapabilityCeiling::roleCan($actorId, 'edit_user', $uid);
			$items[] = [
				'ID' => $uid,
				'user_login' => (string) $user->user_login,
				'user_email' => $canEdit ? (string) $user->user_email : null,
				'display_name' => (string) $user->display_name,
				'roles' => array_values((array) $user->roles),
				'registered' => str_replace(' ', 'T', (string) $user->user_registered) . 'Z',
			];
		}
		return Toolkit::listResult($items, (int) $userQuery->get_total(), $page, $perPage);
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function getUser(array $args, array $auth): array|\WP_Error
	{
		$given = array_values(array_intersect(['user_id', 'login', 'email'], array_keys($args)));
		if (count($given) !== 1) {
			return Toolkit::error('invalid_arguments', __('Send exactly one of user_id, login or email.', 'llamly'), 400);
		}
		$user = match ($given[0]) {
			'user_id' => get_user_by('id', (int) $args['user_id']),
			'login' => get_user_by('login', (string) $args['login']),
			default => get_user_by('email', (string) $args['email']),
		};
		if (!$user instanceof \WP_User) {
			return Toolkit::error('object_not_found', __('The user was not found.', 'llamly'), 404);
		}
		$uid = (int) $user->ID;
		if (!CapabilityCeiling::roleCan((int) $auth['user_id'], 'edit_user', $uid)) {
			return Toolkit::error('forbidden', __('The connected user cannot view this user\'s profile.', 'llamly'), 403);
		}
		return [
			'ID' => $uid,
			'user_login' => (string) $user->user_login,
			'user_email' => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'first_name' => (string) get_user_meta($uid, 'first_name', true),
			'last_name' => (string) get_user_meta($uid, 'last_name', true),
			'nickname' => (string) get_user_meta($uid, 'nickname', true),
			'url' => (string) $user->user_url,
			'description' => (string) get_user_meta($uid, 'description', true),
			'locale' => (string) get_user_meta($uid, 'locale', true),
			'roles' => array_values((array) $user->roles),
			'registered' => str_replace(' ', 'T', (string) $user->user_registered) . 'Z',
			'post_count' => (int) count_user_posts($uid),
			'is_privileged' => CapabilityCeiling::roleCan($uid, 'manage_options') || CapabilityCeiling::roleCan($uid, 'edit_users') || CapabilityCeiling::roleCan($uid, 'promote_users'),
		];
	}
}
