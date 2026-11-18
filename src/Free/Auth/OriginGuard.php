<?php

declare(strict_types=1);

namespace Llamly\Free\Auth;

use Llamly\Free\Core\Settings;

final class OriginGuard
{
	public static function check(\WP_REST_Request $request): bool|\WP_Error
	{
		$origin = trim((string) $request->get_header('origin'));
		if ($origin === '') {
			return true;
		}
		$canonical = self::canonicalOrigin($origin);
		if ($canonical === null) {
			return new \WP_Error('origin_not_allowed', __('The request Origin is invalid.', 'llamly'), ['status' => 403]);
		}
		$settings = Settings::get();
		if (empty($settings['_valid'])) {
			return new \WP_Error('settings_unavailable', __('Llamly settings are invalid; the request was denied.', 'llamly'), ['status' => 503]);
		}
		$siteOrigins = [self::siteOrigin(home_url('/')), self::siteOrigin(site_url('/'))];
		$siteOrigins = array_values(array_filter($siteOrigins));
		if (in_array($canonical, $siteOrigins, true)) {
			return true;
		}
		if (!empty($settings['origin_allowlist_enabled'])) {
			$allowed = array_values(array_unique(array_merge($siteOrigins, (array) $settings['allowed_origins'])));
			if (in_array($canonical, $allowed, true)) {
				return true;
			}
			return new \WP_Error('origin_not_allowed', __('The request Origin is not in the site allowlist.', 'llamly'), ['status' => 403]);
		}
		if (strpos($canonical, 'https://') === 0) {
			return true;
		}
		return new \WP_Error('origin_not_allowed', __('An Origin outside the site must use HTTPS.', 'llamly'), ['status' => 403]);
	}

	public static function installCorsFilter(): void
	{
		add_filter('rest_pre_serve_request', [self::class, 'sendCorsHeaders'], 9, 4);
	}

	public static function sendCorsHeaders($served, $result, $request, $server): bool
	{
		$routes = ['/llamly/v1/endpoint', '/llamly/v1/oauth/register', '/llamly/v1/oauth/token', '/llamly/v1/oauth/revoke'];
		if (!$request instanceof \WP_REST_Request || !in_array($request->get_route(), $routes, true)) {
			return (bool) $served;
		}
		remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
		$origin = (string) $request->get_header('origin');
		if ($origin !== '' && self::check($request) === true) {
			header('Access-Control-Allow-Origin: ' . $origin);
			header('Vary: Origin', false);
			header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
			header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
			header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, Mcp-Protocol-Version');
		}
		return (bool) $served;
	}

	private static function canonicalOrigin(string $origin): ?string
	{
		if ($origin === 'null' || strlen($origin) > 255) {
			return null;
		}
		$parts = wp_parse_url($origin);
		if (!$parts || empty($parts['scheme']) || empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['path'])
			|| isset($parts['query']) || isset($parts['fragment'])
		) {
			return null;
		}
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower((string) $parts['host']);
		if (!in_array($scheme, ['http', 'https'], true) || !preg_match('/^[a-z0-9.\-:\[\]]+$/', $host)) {
			return null;
		}
		if ($scheme !== 'https' && !($scheme === 'http' && self::isLoopbackHost($host))) {
			return null;
		}
		$port = isset($parts['port']) ? (int) $parts['port'] : null;
		if ($port !== null && ($port < 1 || $port > 65535)) {
			return null;
		}
		$defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
		return $scheme . '://' . $host . ($port !== null && !$defaultPort ? ':' . $port : '');
	}

	private static function siteOrigin(string $url): ?string
	{
		$parts = wp_parse_url($url);
		if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower((string) $parts['host']);
		$port = isset($parts['port']) ? (int) $parts['port'] : null;
		return self::canonicalOrigin($scheme . '://' . $host . ($port ? ':' . $port : ''));
	}

	private static function isLoopbackHost(string $host): bool
	{
		return in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
	}
}
