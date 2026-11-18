<?php

declare(strict_types=1);

namespace Llamly\Free\Auth;

use Llamly\Free\Core\Settings;

final class OAuthRedirectPolicy
{
	private const DEFAULT_HOSTS = ['chatgpt.com', 'openai.com', 'claude.ai', 'claude.com', 'anthropic.com', 'cursor.com'];
	private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

	public static function allows(string $uri): bool
	{
		if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7f]/', $uri)) {
			return false;
		}
		$parts = wp_parse_url($uri);
		if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			return false;
		}
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower(trim((string) $parts['host'], '[]'));
		$port = isset($parts['port']) ? (int) $parts['port'] : null;
		if (!in_array($scheme, ['https', 'http'], true) || ($port !== null && ($port < 1 || $port > 65535))) {
			return false;
		}
		if (in_array($host, self::LOOPBACK_HOSTS, true)) {
			return $scheme === 'http' || $scheme === 'https';
		}
		if ($scheme !== 'https' || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host)) {
			return false;
		}
		$settings = Settings::get();
		if (empty($settings['_valid'])) {
			return false;
		}
		if (empty($settings['oauth_redirect_allowlist_enabled'])) {
			return true;
		}
		$allowedHosts = array_unique(array_merge(self::DEFAULT_HOSTS, (array) $settings['extra_oauth_redirect_hosts']));
		$allowedHosts = apply_filters('llamly_free_oauth_redirect_hosts', $allowedHosts);
		foreach ((array) $allowedHosts as $allowed) {
			$allowed = strtolower((string) $allowed);
			if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the site host looks like a local development site (modelled on Cowboy MCP's heuristic).
	 * Advisory only, for UI copy: it derives from home_url() and must never gate security behavior.
	 */
	public static function hostLooksLocal(string $host): bool
	{
		$host = strtolower(trim($host, '[]'));
		if ($host === '' || in_array($host, self::LOOPBACK_HOSTS, true) || preg_match('/\.(?:localhost|local|test|internal|example|invalid)$/', $host)) {
			return true;
		}
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
		}
		return false;
	}

	/** @param bool $translate False for values that are persisted (audit context); they keep the English source label. */
	public static function hostForLabel(string $uri, bool $translate = true): string
	{
		$host = strtolower(trim((string) wp_parse_url($uri, PHP_URL_HOST), '[]'));
		foreach (['chatgpt.com' => 'ChatGPT', 'openai.com' => 'ChatGPT', 'claude.ai' => 'Claude', 'claude.com' => 'Claude', 'anthropic.com' => 'Claude', 'cursor.com' => 'Cursor'] as $domain => $label) {
			if ($host === $domain || str_ends_with($host, '.' . $domain)) {
				return $label;
			}
		}
		if (in_array($host, self::LOOPBACK_HOSTS, true)) {
			return $translate ? __('A desktop or terminal app', 'llamly') : 'A desktop or terminal app';
		}
		return substr((string) preg_replace('/[^a-z0-9.\-]/', '', $host), 0, 100);
	}
}
