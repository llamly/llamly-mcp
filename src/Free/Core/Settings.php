<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Core;

final class Settings
{
	public const OPTION = 'llamly_free_settings';
	private const REVISION_KEY = 'revision';

	/** @return array<string, mixed> */
	public static function defaults(): array
	{
		return [
			self::REVISION_KEY => 1,
			'enabled' => true,
			'safe_mode' => true,
			// Optional human approval queue for publishing and settings. Off by default (decision 28).
			'require_approval' => false,
			'journal_enabled' => true,
			'journal_retention_days' => 7,
			'audit_retention_days' => 30,
			'rate_limit_per_minute' => 120,
			'allowed_origins' => [],
			'origin_allowlist_enabled' => true,
			'oauth_redirect_allowlist_enabled' => true,
			'extra_oauth_redirect_hosts' => [],
			'oauth_window_until' => 0,
			'debug_logging' => false,
			'delete_data_on_uninstall' => false,
			'abilities_enabled' => false,
		];
	}

	/** @return array<string, mixed> */
	public static function get(): array
	{
		$value = get_option(self::OPTION, null);
		if (!is_array($value)) {
			return array_merge(self::defaults(), ['_valid' => false]);
		}
		$settings = array_merge(self::defaults(), $value);
		$settings['_valid'] = self::isValidStored($settings);
		return $settings;
	}

	/** Read the stored settings straight from the options table, bypassing the object cache. */
	public static function fresh(): array
	{
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
		$value = $raw === null ? null : maybe_unserialize($raw);
		if (!is_array($value)) {
			return array_merge(self::defaults(), ['_valid' => false]);
		}
		$settings = array_merge(self::defaults(), $value);
		$settings['_valid'] = self::isValidStored($settings);
		return $settings;
	}

	/** Drop cached copies after the settings transaction has committed. */
	public static function flushCache(): void
	{
		wp_cache_delete(self::OPTION, 'options');
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	public static function installDefaults(): bool
	{
		add_option(self::OPTION, self::defaults(), '', false);
		$value = get_option(self::OPTION, null);
		return is_array($value) && self::isValidStored(array_merge(self::defaults(), $value));
	}

	/** Removes keys that no setting uses any more from the stored settings (upgrade). @param list<string> $keys */
	public static function dropKeys(array $keys): bool
	{
		$value = get_option(self::OPTION, null);
		if (!is_array($value) || !array_intersect_key($value, array_flip($keys))) {
			return true;
		}
		update_option(self::OPTION, array_diff_key($value, array_flip($keys)), false);
		self::flushCache();
		$stored = get_option(self::OPTION, null);
		return is_array($stored) && !array_intersect_key($stored, array_flip($keys));
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save(array $input, int $expectedRevision, bool $confirmRisk = false): array|\WP_Error
	{
		global $wpdb;
		$allowed = ['enabled', 'safe_mode', 'require_approval', 'journal_enabled', 'journal_retention_days', 'audit_retention_days', 'rate_limit_per_minute', 'allowed_origins', 'origin_allowlist_enabled', 'oauth_redirect_allowlist_enabled', 'extra_oauth_redirect_hosts', 'debug_logging', 'delete_data_on_uninstall', 'oauth_window_until', 'abilities_enabled'];
		if (array_diff(array_keys($input), $allowed)) {
			return new \WP_Error('invalid_settings', __('The settings request contains an unsupported field.', 'llamly'), ['status' => 400]);
		}
		$currentRaw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
		if ($currentRaw === null) {
			$current = self::defaults();
			$oldSerialized = null;
		} else {
			$current = maybe_unserialize($currentRaw);
			if (!is_array($current)) {
				return new \WP_Error('settings_unavailable', __('Stored settings are invalid; no changes were saved.', 'llamly'), ['status' => 503]);
			}
			$oldSerialized = (string) $currentRaw;
		}
		$current = array_merge(self::defaults(), $current);
		if (!self::isValidStored($current)) {
			return new \WP_Error('settings_unavailable', __('Stored settings are invalid; no changes were saved.', 'llamly'), ['status' => 503]);
		}
		if ((int) $current[self::REVISION_KEY] !== $expectedRevision) {
			return new \WP_Error('stale_revision', __('Settings changed in another session. Reload before saving.', 'llamly'), ['status' => 409]);
		}
		if (($input['delete_data_on_uninstall'] ?? null) === true && empty($current['delete_data_on_uninstall']) && !$confirmRisk) {
			return new \WP_Error('confirmation_required', __('Removing all Llamly data on uninstall requires explicit confirmation.', 'llamly'), ['status' => 409]);
		}
		foreach (['origin_allowlist_enabled', 'oauth_redirect_allowlist_enabled'] as $allowlist) {
			if (array_key_exists($allowlist, $input) && $input[$allowlist] === false && !empty($current[$allowlist]) && !$confirmRisk) {
				return new \WP_Error('confirmation_required', __('Disabling an origin or redirect allowlist requires explicit confirmation of the security risk.', 'llamly'), ['status' => 409]);
			}
		}

		$next = $current;
		foreach (['enabled', 'safe_mode', 'require_approval', 'journal_enabled', 'origin_allowlist_enabled', 'oauth_redirect_allowlist_enabled', 'debug_logging', 'delete_data_on_uninstall', 'abilities_enabled'] as $key) {
			if (array_key_exists($key, $input)) {
				if (!is_bool($input[$key])) {
					return new \WP_Error('invalid_settings', __('Boolean settings must be true or false.', 'llamly'), ['status' => 400]);
				}
				$next[$key] = $input[$key];
			}
		}
		foreach (['journal_retention_days' => [1, 90], 'audit_retention_days' => [1, 365], 'rate_limit_per_minute' => [10, 1000]] as $key => [$min, $max]) {
			if (array_key_exists($key, $input)) {
				if (!is_int($input[$key]) || $input[$key] < $min || $input[$key] > $max) {
					return new \WP_Error('invalid_settings', sprintf(/* translators: %s: setting key. */ __('The value for %s is outside the allowed range.', 'llamly'), $key), ['status' => 400]);
				}
				$next[$key] = $input[$key];
			}
		}
		if (array_key_exists('allowed_origins', $input)) {
			$origins = self::normalizeOrigins($input['allowed_origins']);
			if (is_wp_error($origins)) {
				return $origins;
			}
			$next['allowed_origins'] = $origins;
		}
		if (array_key_exists('extra_oauth_redirect_hosts', $input)) {
			$hosts = self::normalizeRedirectHosts($input['extra_oauth_redirect_hosts']);
			if (is_wp_error($hosts)) {
				return $hosts;
			}
			$next['extra_oauth_redirect_hosts'] = $hosts;
		}
		if (array_key_exists('oauth_window_until', $input)) {
			$until = $input['oauth_window_until'];
			if (!is_int($until) || ($until !== 0 && ($until < time() || $until > time() + 1800))) {
				return new \WP_Error('invalid_settings', __('The OAuth registration window can only be closed or opened for up to 30 minutes.', 'llamly'), ['status' => 400]);
			}
			$next['oauth_window_until'] = $until;
		}

		$next[self::REVISION_KEY] = $expectedRevision + 1;
		$serialized = maybe_serialize($next);
		// Turning the approval queue off cancels every request still waiting for it, in the same transaction as the
		// setting: a pending request must never execute under a policy that no longer asks a human.
		$supersede = !empty($current['require_approval']) && empty($next['require_approval']) && $oldSerialized !== null;
		if ($supersede && $wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('settings_unavailable', __('The settings could not be saved. Try again.', 'llamly'), ['status' => 503]);
		}
		if ($oldSerialized === null) {
			$updated = $wpdb->insert(
				$wpdb->options,
				['option_name' => self::OPTION, 'option_value' => $serialized, 'autoload' => 'no'],
				['%s', '%s', '%s']
			);
		} else {
			$updated = $wpdb->query($wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$serialized,
				self::OPTION,
				$oldSerialized
			));
		}
		if ($updated !== 1) {
			if ($supersede) {
				$wpdb->query('ROLLBACK');
			}
			return new \WP_Error('stale_revision', __('Settings changed in another session. Reload before saving.', 'llamly'), ['status' => 409]);
		}
		if ($supersede) {
			$cancelled = \Llamly\Free\History\PolicyMigration::supersedePending('queue_disabled');
			if ($cancelled === false || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
				$wpdb->query('ROLLBACK');
				self::flushCache();
				return new \WP_Error('settings_unavailable', __('The settings could not be saved because the pending approval requests could not be cancelled. No changes were saved.', 'llamly'), ['status' => 503]);
			}
		}

		self::flushCache();
		return $next;
	}

	/** @param array<string, mixed> $settings */
	private static function isValidStored(array $settings): bool
	{
		if (!isset($settings[self::REVISION_KEY]) || !is_int($settings[self::REVISION_KEY]) || $settings[self::REVISION_KEY] < 1) {
			return false;
		}
		foreach (['enabled', 'safe_mode', 'require_approval', 'journal_enabled', 'origin_allowlist_enabled', 'oauth_redirect_allowlist_enabled', 'debug_logging', 'delete_data_on_uninstall', 'abilities_enabled'] as $key) {
			if (!isset($settings[$key]) || !is_bool($settings[$key])) {
				return false;
			}
		}
		foreach (['journal_retention_days' => [1, 90], 'audit_retention_days' => [1, 365], 'rate_limit_per_minute' => [10, 1000]] as $key => [$min, $max]) {
			if (!isset($settings[$key]) || !is_int($settings[$key]) || $settings[$key] < $min || $settings[$key] > $max) {
				return false;
			}
		}
		if (!isset($settings['allowed_origins']) || !is_array($settings['allowed_origins'])) {
			return false;
		}
		if (!isset($settings['extra_oauth_redirect_hosts']) || !is_array($settings['extra_oauth_redirect_hosts']) || count($settings['extra_oauth_redirect_hosts']) > 50) {
			return false;
		}
		$normalizedOrigins = self::normalizeOrigins($settings['allowed_origins']);
		$normalizedHosts = self::normalizeRedirectHosts($settings['extra_oauth_redirect_hosts']);
		if (is_wp_error($normalizedOrigins) || $normalizedOrigins !== array_values(array_unique($settings['allowed_origins']))
			|| is_wp_error($normalizedHosts) || $normalizedHosts !== array_values(array_unique($settings['extra_oauth_redirect_hosts']))
		) {
			return false;
		}
		if (!isset($settings['oauth_window_until']) || !is_int($settings['oauth_window_until']) || $settings['oauth_window_until'] < 0) {
			return false;
		}
		return true;
	}

	/** @return list<string>|\WP_Error */
	private static function normalizeRedirectHosts($input): array|\WP_Error
	{
		if (!is_array($input) || count($input) > 50) {
			return new \WP_Error('invalid_settings', __('Add no more than 50 OAuth redirect hosts.', 'llamly'), ['status' => 400]);
		}
		$hosts = [];
		foreach ($input as $host) {
			if (!is_string($host)) {
				return new \WP_Error('invalid_settings', __('An OAuth redirect host is invalid.', 'llamly'), ['status' => 400]);
			}
			$host = strtolower(rtrim(trim($host), '.'));
			if ($host === '' || strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host)
				|| filter_var($host, FILTER_VALIDATE_IP)
			) {
				return new \WP_Error('invalid_settings', __('Enter a hostname only, without scheme, path, port, or IP address.', 'llamly'), ['status' => 400]);
			}
			$hosts[] = $host;
		}
		return array_values(array_unique($hosts));
	}

	/** @return list<string>|\WP_Error */
	private static function normalizeOrigins($input): array|\WP_Error
	{
		if (!is_array($input) || count($input) > 20) {
			return new \WP_Error('invalid_settings', __('Allowed origins must be a list of up to 20 HTTPS origins.', 'llamly'), ['status' => 400]);
		}
		$origins = [];
		foreach ($input as $origin) {
			if (!is_string($origin) || strlen($origin) > 255) {
				return new \WP_Error('invalid_settings', __('An allowed origin is invalid.', 'llamly'), ['status' => 400]);
			}
			$parts = wp_parse_url($origin);
			if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
				|| empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
				|| isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
				|| (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))
				|| !preg_match('/^[a-z0-9.\-\[\]]+$/i', (string) $parts['host'])
			) {
				return new \WP_Error('invalid_settings', __('Allowed origins must contain only an HTTPS scheme and host.', 'llamly'), ['status' => 400]);
			}
			$port = isset($parts['port']) ? (int) $parts['port'] : null;
			$origins[] = 'https://' . strtolower($parts['host']) . ($port !== null && $port !== 443 ? ':' . $port : '');
		}
		return array_values(array_unique($origins));
	}
}
