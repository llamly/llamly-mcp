<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\Schema;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\Core\AuditLog;
use Llamly\Free\History\MutationRunner;

final class CredentialStore
{
	/** @return array<string, mixed>|\WP_Error */
	public static function createApiKey(int $userId, string $label, string $scopeMode, array $allowedTools = []): array|\WP_Error
	{
		global $wpdb;
		if ($userId < 1 || !get_user_by('id', $userId) || !user_can($userId, 'manage_options')) {
			return new \WP_Error('forbidden', __('Only a site administrator can create a Llamly key.', 'llamly'), ['status' => 403]);
		}
		$label = sanitize_text_field($label);
		if ($label === '' || strlen($label) > 120) {
			return new \WP_Error('invalid_credential', __('Enter a name of up to 120 characters.', 'llamly'), ['status' => 400]);
		}
		$scopes = self::normalizeScope($scopeMode, $allowedTools);
		if (is_wp_error($scopes)) {
			return $scopes;
		}

		try {
			$secret = 'llm_' . bin2hex(random_bytes(32));
		} catch (\Throwable $error) {
			return new \WP_Error('credential_unavailable', __('A secure random key could not be generated.', 'llamly'), ['status' => 503]);
		}
		$prefix = substr($secret, 0, 20);
		$now = gmdate('Y-m-d H:i:s');
		$table = Schema::tableNames()['credentials'];
		if ($wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('credential_unavailable', __('The credential transaction could not start.', 'llamly'), ['status' => 503]);
		}
		$inserted = $wpdb->insert($table, [
			'user_id' => $userId,
			'kind' => 'api_key',
			'label' => $label,
			'prefix' => $prefix,
			'secret_hash' => hash('sha256', $secret),
			'scope_mode' => $scopeMode,
			'allowed_tools' => wp_json_encode($scopes),
			'created_at' => $now,
		], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
		if ($inserted !== 1) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('credential_unavailable', __('The key could not be stored. No key was issued.', 'llamly'), ['status' => 503]);
		}
		$credentialId = (int) $wpdb->insert_id;
		if ($credentialId < 1) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('credential_unavailable', __('The key ID could not be confirmed. No key was issued.', 'llamly'), ['status' => 503]);
		}
		if (!AuditLog::append('credential_created', ['credential_id' => $credentialId, 'label' => $label, 'scope_mode' => $scopeMode, 'prefix' => $prefix], $userId, $credentialId, 'key:' . $credentialId)
			|| $wpdb->query('COMMIT') === false || $wpdb->last_error !== ''
		) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('credential_unavailable', __('The key and its audit record could not be stored. No key was issued.', 'llamly'), ['status' => 503]);
		}

		return [
			'id' => $credentialId,
			'user_id' => $userId,
			'kind' => 'api_key',
			'label' => $label,
			'prefix' => $prefix,
			'scope_mode' => $scopeMode,
			'allowed_tools' => $scopes,
			'created_at' => $now,
			'secret' => $secret,
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function authenticateApiKey(string $secret): array|\WP_Error
	{
		global $wpdb;
		if (!preg_match('/^llm_[a-f0-9]{64}$/', $secret)) {
			return new \WP_Error('unauthorized', __('The bearer token is invalid.', 'llamly'), ['status' => 401]);
		}
		$table = Schema::tableNames()['credentials'];
		$prefix = substr($secret, 0, 20);
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM %i WHERE prefix = %s AND kind = 'api_key' LIMIT 1",
			$table,
			$prefix
		), ARRAY_A);
		if (!$row || !empty($row['revoked_at']) || !hash_equals((string) $row['secret_hash'], hash('sha256', $secret))) {
			return new \WP_Error('unauthorized', __('The bearer token is invalid or revoked.', 'llamly'), ['status' => 401]);
		}

		$mode = (string) $row['scope_mode'];
		$tools = json_decode((string) $row['allowed_tools'], true);
		if (!in_array($mode, ['full', 'read_only', 'custom'], true) || !is_array($tools)) {
			return new \WP_Error('invalid_scope', __('The stored credential scope is invalid; access is denied.', 'llamly'), ['status' => 403]);
		}
		$now = gmdate('Y-m-d H:i:s');
		$updated = $wpdb->update($table, ['last_used_at' => $now], ['id' => (int) $row['id']], ['%s'], ['%d']);
		if ($updated === false || $wpdb->last_error !== '') {
			return new \WP_Error('storage_unavailable', __('The credential usage record could not be saved.', 'llamly'), ['status' => 503]);
		}

		return [
			'credential_id' => (int) $row['id'],
			'credential_key' => 'key:' . (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'kind' => 'api_key',
			'scope_mode' => $mode,
			'allowed_tools' => array_values(array_filter($tools, 'is_string')),
		];
	}

	/** @return true|\WP_Error */
	public static function revoke(int $id): bool|\WP_Error
	{
		return self::revokeForAdmin($id);
	}

	/** @return true|\WP_Error */
	public static function revokeForAdmin(int $id): bool|\WP_Error
	{
		global $wpdb;
		if (!current_user_can('manage_options')) {
			return new \WP_Error('forbidden', __('Only a site administrator can revoke a Llamly key.', 'llamly'), ['status' => 403]);
		}
		$table = Schema::tableNames()['credentials'];
		if ($wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('storage_unavailable', __('The key could not be revoked.', 'llamly'), ['status' => 503]);
		}
		$row = $wpdb->get_row($wpdb->prepare('SELECT id, user_id, label, prefix FROM %i WHERE id = %d AND revoked_at IS NULL FOR UPDATE', $table, $id), ARRAY_A);
		if (!$row) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('credential_not_found', __('The active key was not found.', 'llamly'), ['status' => 404]);
		}
		$updated = $wpdb->update($table, ['revoked_at' => gmdate('Y-m-d H:i:s')], ['id' => $id, 'revoked_at' => null], ['%s'], ['%d', '%s']);
		$audited = $updated === 1 && AuditLog::append('credential_revoked', ['credential_id' => $id, 'label' => (string) $row['label'], 'prefix' => (string) $row['prefix']], get_current_user_id(), $id, 'key:' . $id);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('storage_unavailable', __('The key could not be revoked.', 'llamly'), ['status' => 503]);
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function updateScopeForAdmin(int $id, string $scopeMode, array $allowedTools = []): bool|\WP_Error
	{
		global $wpdb;
		if (!current_user_can('manage_options')) {
			return new \WP_Error('forbidden', __('Only a site administrator can change a Llamly key scope.', 'llamly'), ['status' => 403]);
		}
		$scopes = self::normalizeScope($scopeMode, $allowedTools);
		if (is_wp_error($scopes)) {
			return $scopes;
		}
		$table = Schema::tableNames()['credentials'];
		if ($wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('storage_unavailable', __('The key scope could not be changed.', 'llamly'), ['status' => 503]);
		}
		$row = $wpdb->get_row($wpdb->prepare("SELECT id, user_id, label, prefix, scope_mode, allowed_tools FROM %i WHERE id = %d AND kind = 'api_key' AND revoked_at IS NULL FOR UPDATE", $table, $id), ARRAY_A);
		if (!$row) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('credential_not_found', __('The active API key was not found.', 'llamly'), ['status' => 404]);
		}
		$current = json_decode((string) $row['allowed_tools'], true);
		$current = is_array($current) ? array_values(array_filter($current, 'is_string')) : [];
		if ((string) $row['scope_mode'] === $scopeMode && $current === $scopes) {
			$wpdb->query('ROLLBACK');
			return true;
		}
		$updated = $wpdb->update($table, [
			'scope_mode' => $scopeMode,
			'allowed_tools' => wp_json_encode($scopes),
		], ['id' => $id, 'revoked_at' => null], ['%s', '%s'], ['%d', '%s']);
		$audited = $updated === 1 && AuditLog::append('credential_scope_updated', [
			'credential_id' => $id,
			'label' => (string) $row['label'],
			'previous_scope_mode' => (string) $row['scope_mode'],
			'scope_mode' => $scopeMode,
			'allowed_tools' => $scopes,
			'prefix' => (string) $row['prefix'],
		], get_current_user_id(), $id, 'key:' . $id);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('storage_unavailable', __('The key scope and its audit record could not be saved.', 'llamly'), ['status' => 503]);
		}
		return true;
	}

	/** @return list<array<string, mixed>> */
	public static function listForAdmin(): array
	{
		global $wpdb;
		$table = Schema::tableNames()['credentials'];
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT id, user_id, kind, label, prefix, scope_mode, allowed_tools, created_at, last_used_at, revoked_at FROM %i ORDER BY id DESC LIMIT %d',
			$table,
			200
		), ARRAY_A);
		if (!is_array($rows)) {
			return [];
		}
		foreach ($rows as &$row) {
			$row['id'] = (int) $row['id'];
			$row['user_id'] = (int) $row['user_id'];
			$row['allowed_tools'] = json_decode((string) $row['allowed_tools'], true);
			if (!is_array($row['allowed_tools'])) {
				$row['allowed_tools'] = [];
			}
		}
		return $rows;
	}

	/** @return list<string>|\WP_Error */
	private static function normalizeScope(string $mode, array $tools): array|\WP_Error
	{
		$registry = ToolRegistry::all();
		if (!in_array($mode, ['full', 'read_only', 'custom'], true)) {
			return new \WP_Error('invalid_scope', __('Choose full, read-only, or custom access.', 'llamly'), ['status' => 400]);
		}
		if ($mode === 'full') {
			if (!MutationRunner::writeEnabled()) {
				return new \WP_Error('write_not_ready', __('Full access is unavailable until the post write, journal, and recovery checks pass.', 'llamly'), ['status' => 503]);
			}
			return array_keys($registry);
		}
		if ($mode === 'read_only') {
			return array_values(array_keys(array_filter($registry, static fn(array $definition): bool => ($definition['access'] ?? '') === 'read')));
		}
		$tools = array_values(array_unique(array_filter($tools, 'is_string')));
		if (!$tools || array_diff($tools, array_keys($registry))) {
			return new \WP_Error('invalid_scope', __('Custom access must name at least one registered tool.', 'llamly'), ['status' => 400]);
		}
		if (!MutationRunner::writeEnabled()) {
			foreach ($tools as $tool) {
				if (($registry[$tool]['access'] ?? '') === 'write') {
					return new \WP_Error('write_not_ready', __('Write tools are unavailable until the required post write and recovery checks pass.', 'llamly'), ['status' => 503]);
				}
			}
		}
		return $tools;
	}
}
