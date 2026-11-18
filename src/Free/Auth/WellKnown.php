<?php

declare(strict_types=1);

namespace Llamly\Free\Auth;

final class WellKnown
{
	public static function registerHooks(): void
	{
		add_filter('query_vars', [self::class, 'queryVars']);
		add_action('init', [self::class, 'addRewriteRules'], 1);
		// Plain permalinks have no rewrite rules: the request path itself is matched here, before the main query and
		// before redirect_canonical (template_redirect 10) can append a slash or send the request to the home page.
		add_action('parse_request', [self::class, 'parseRequest'], 0);
		add_action('template_redirect', [self::class, 'dispatch'], 0);
	}

	/** Serves the discovery documents from the request path (any permalink mode) or from the rewrite query var. */
	public static function parseRequest(\WP $wp): void
	{
		$type = isset($wp->query_vars['llamly_well_known']) && is_string($wp->query_vars['llamly_well_known']) ? $wp->query_vars['llamly_well_known'] : '';
		if ($type === '') {
			$uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
			$type = self::typeForPath((string) wp_parse_url($uri, PHP_URL_PATH));
		}
		if ($type !== '') {
			self::serve($type);
		}
	}

	/**
	 * Which discovery document a request path asks for, or '' for none. The path is taken relative to the path of
	 * home_url() (WordPress in a subdirectory) and may end with a slash. RFC 9728 / RFC 8414 path-suffixed forms
	 * (/.well-known/oauth-protected-resource/<resource path>, /.well-known/oauth-authorization-server/<issuer path>)
	 * are served only when the suffix is the path of this site's MCP resource or issuer.
	 */
	public static function typeForPath(string $path): string
	{
		if ($path === '' || strpos($path, '/.well-known/') === false) {
			return '';
		}
		$homePath = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
		if ($homePath !== '' && strpos($path, $homePath . '/') === 0) {
			$path = substr($path, strlen($homePath));
		}
		if (!preg_match('#^/\.well-known/oauth-(authorization-server|protected-resource)(/.*)?$#', $path, $m)) {
			return '';
		}
		$type = $m[1];
		$suffix = untrailingslashit($m[2] ?? '');
		if ($suffix === '') {
			return $type;
		}
		$target = $type === 'protected-resource' ? rest_url('llamly/v1/endpoint') : home_url('/');
		$targetPath = untrailingslashit((string) wp_parse_url($target, PHP_URL_PATH));
		$allowed = array_filter([$targetPath, $homePath !== '' && strpos($targetPath, $homePath . '/') === 0 ? substr($targetPath, strlen($homePath)) : '']);
		return in_array($suffix, $allowed, true) ? $type : '';
	}

	public static function queryVars(array $vars): array
	{
		$vars[] = 'llamly_well_known';
		return $vars;
	}

	public static function addRewriteRules(): void
	{
		add_rewrite_rule('^\.well-known/oauth-authorization-server/?$', 'index.php?llamly_well_known=authorization-server', 'top');
		add_rewrite_rule('^\.well-known/oauth-protected-resource/?$', 'index.php?llamly_well_known=protected-resource', 'top');
	}

	public static function dispatch(): void
	{
		$type = (string) get_query_var('llamly_well_known');
		if ($type === '') {
			return;
		}
		self::serve($type);
	}

	private static function serve(string $type): void
	{
		$issuer = untrailingslashit(home_url('/'));
		if ($type === 'authorization-server') {
			$metadata = [
				'issuer' => $issuer,
				'authorization_endpoint' => rest_url('llamly/v1/oauth/authorize'),
				'token_endpoint' => rest_url('llamly/v1/oauth/token'),
				'registration_endpoint' => rest_url('llamly/v1/oauth/register'),
				'revocation_endpoint' => rest_url('llamly/v1/oauth/revoke'),
				'response_types_supported' => ['code'],
				'grant_types_supported' => ['authorization_code', 'refresh_token'],
				'token_endpoint_auth_methods_supported' => ['none'],
				'code_challenge_methods_supported' => ['S256'],
				'authorization_response_iss_parameter_supported' => true,
				'scopes_supported' => ['llamly.read', 'llamly.write'],
			];
		} elseif ($type === 'protected-resource') {
			$metadata = [
				'resource' => untrailingslashit(rest_url('llamly/v1/endpoint')),
				'authorization_servers' => [$issuer],
				'bearer_methods_supported' => ['header'],
				'scopes_supported' => ['llamly.read', 'llamly.write'],
				'resource_name' => 'Llamly MCP endpoint',
			];
		} else {
			return;
		}
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: public, max-age=300');
		echo wp_json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
}
