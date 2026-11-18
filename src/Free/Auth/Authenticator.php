<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;

final class Authenticator
{
	/** @return array<string, mixed>|\WP_Error */
	public static function authenticate(\WP_REST_Request $request): array|\WP_Error
	{
		$settings = Settings::get();
		if (empty($settings['_valid'])) {
			return new \WP_Error('settings_unavailable', __('Llamly settings are invalid; the MCP server is paused.', 'llamly'), ['status' => 503]);
		}
		if (empty($settings['enabled'])) {
			return new \WP_Error('server_disabled', __('The Llamly MCP server is disabled.', 'llamly'), ['status' => 503]);
		}

		$header = self::authorizationHeader($request);
		if ($header === '') {
			// Discovery probe without credentials: answer 401 + WWW-Authenticate, never counted as a failure.
			return new \WP_Error('unauthorized', __('A valid Bearer token is required.', 'llamly'), ['status' => 401]);
		}
		if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $match)) {
			if (!preg_match('/^Bearer(?:\s|$)/i', trim($header))) {
				// Some other scheme (e.g. Basic): not a Llamly credential attempt.
				return new \WP_Error('unauthorized', __('A valid Bearer token is required.', 'llamly'), ['status' => 401]);
			}
			return self::failure($request, 'malformed_token', '', new \WP_Error('unauthorized', __('A valid Bearer token is required.', 'llamly'), ['status' => 401]));
		}

		$token = $match[1];
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
			return self::failure($request, 'malformed_token', '', new \WP_Error('unauthorized', __('A valid Bearer token is required.', 'llamly'), ['status' => 401]));
		}
		$isApiKey = str_starts_with($token, 'llm_');
		$auth = $isApiKey
			? CredentialStore::authenticateApiKey($token)
			: self::authenticateOAuthAccessToken($token);
		if (is_wp_error($auth)) {
			if ($auth->get_error_code() === 'unauthorized') {
				return self::failure($request, $isApiKey ? 'invalid_api_key' : 'invalid_access_token', substr($token, 0, 8), $auth);
			}
			return $auth;
		}

		$limit = max(10, min(1000, (int) $settings['rate_limit_per_minute']));
		if (!RateLimiter::allow('credential:' . $auth['credential_key'], $limit)) {
			// One Logs row per credential per minute while it stays over the limit.
			if (RateLimiter::allow('rate-audit:' . $auth['credential_key'], 1)) {
				AuditLog::append('rate_limited', ['limit_per_minute' => $limit], (int) $auth['user_id'], isset($auth['credential_id']) ? (int) $auth['credential_id'] : null, (string) $auth['credential_key'], [
					'result' => 'rate',
					'ip' => RateLimiter::remoteAddress($request),
				]);
			}
			return new \WP_Error('rate_limited', __('The credential request limit has been reached.', 'llamly'), ['status' => 429]);
		}
		return $auth;
	}

	private static function authorizationHeader(\WP_REST_Request $request): string
	{
		$header = trim((string) $request->get_header('authorization'));
		foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
			if ($header === '' && isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
				// Keys and tokens are [a-z0-9_]; sanitizing never changes a valid credential.
				$header = trim(sanitize_text_field(wp_unslash($_SERVER[$key])));
			}
		}
		if ($header === '' && function_exists('getallheaders')) {
			$headers = getallheaders();
			if (is_array($headers)) {
				foreach ($headers as $name => $value) {
					if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
						$header = trim($value);
						break;
					}
				}
			}
		}
		return $header;
	}

	/** Counts a bad credential toward the per-IP limit and records a throttled audit event. */
	private static function failure(\WP_REST_Request $request, string $reason, string $prefix, \WP_Error $error): \WP_Error
	{
		if (!preg_match('/^ll(?:m|at|rt|c)_/', $prefix)) {
			$prefix = '';
		}
		$addressHash = hash('sha256', RateLimiter::remoteAddress($request));
		$allowed = RateLimiter::allow('auth-ip:' . $addressHash, 20);
		// At most one audit row per IP per reason per minute.
		if (RateLimiter::allow('auth-audit:' . hash('sha256', $addressHash . '|' . $reason), 1)) {
			AuditLog::append('auth_failed', [
				'reason' => $reason,
				'ip' => substr($addressHash, 0, 16),
				'credential_prefix' => preg_match('/^(ll(?:m|at|rt|c)_)/', $prefix, $family) ? $family[1] : '',
				'rate_limited' => !$allowed,
			], null, null, null, ['ip' => RateLimiter::remoteAddress($request)]);
		}
		if (!$allowed) {
			return new \WP_Error('rate_limited', __('Too many authentication failures from this network address.', 'llamly'), ['status' => 429]);
		}
		return $error;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function authenticateOAuthAccessToken(string $token): array|\WP_Error
	{
		global $wpdb;
		if (!preg_match('/^llat_[a-f0-9]{64}$/', $token)) {
			return new \WP_Error('unauthorized', __('The Bearer token is invalid or expired.', 'llamly'), ['status' => 401]);
		}
		$tables = Schema::tableNames();
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT t.*, g.revoked_at AS grant_revoked, c.revoked_at AS client_revoked
			 FROM %i t
			 INNER JOIN %i g ON g.id = t.grant_id
			 INNER JOIN %i c ON c.client_id = t.client_id
			 WHERE t.token_hash = %s AND t.token_type = 'access' LIMIT 1",
			$tables['oauth_tokens'],
			$tables['oauth_grants'],
			$tables['oauth_clients'],
			hash('sha256', $token)
		), ARRAY_A);
		if (!$row || !empty($row['revoked_at']) || !empty($row['grant_revoked']) || !empty($row['client_revoked'])
			|| strtotime((string) $row['expires_at'] . ' UTC') <= time()
		) {
			return new \WP_Error('unauthorized', __('The Bearer token is invalid or expired.', 'llamly'), ['status' => 401]);
		}
		$scopes = json_decode((string) $row['scopes'], true);
		if (!is_array($scopes) || !$scopes) {
			return new \WP_Error('invalid_scope', __('The OAuth grant has no valid scope; access is denied.', 'llamly'), ['status' => 403]);
		}
		// llamly.read covers every read tool; llamly.write adds every write tool. WordPress capabilities still apply per call.
		foreach ($scopes as $scope) {
			if (!in_array($scope, ['llamly.read', 'llamly.write'], true)) {
				return new \WP_Error('invalid_scope', __('The OAuth grant contains an unsupported scope.', 'llamly'), ['status' => 403]);
			}
		}
		$scopeMode = in_array('llamly.write', $scopes, true) ? 'full' : 'read_only';
		$updated = $wpdb->update($tables['oauth_tokens'], ['last_used_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $row['id']], ['%s'], ['%d']);
		if ($updated === false || $wpdb->last_error !== '') {
			return new \WP_Error('storage_unavailable', __('The OAuth usage record could not be saved.', 'llamly'), ['status' => 503]);
		}

		return [
			'credential_id' => null,
			'credential_key' => 'oauth:' . (string) $row['grant_id'],
			'user_id' => (int) $row['user_id'],
			'kind' => 'oauth',
			'client_id' => (string) $row['client_id'],
			'grant_id' => (string) $row['grant_id'],
			'resource' => (string) $row['resource'],
			'scope_mode' => $scopeMode,
			'allowed_tools' => [],
		];
	}
}
