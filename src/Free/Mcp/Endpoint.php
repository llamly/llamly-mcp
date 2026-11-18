<?php

declare(strict_types=1);

namespace Llamly\Free\Mcp;

use Llamly\Free\Auth\Authenticator;
use Llamly\Free\Auth\OriginGuard;

final class Endpoint
{
	public const ROUTE = '/llamly/v1/endpoint';

	/**
	 * Result of the permission check per request object: the authenticated credential, true for a CORS preflight,
	 * or the WP_Error to answer with. WordPress calls the permission_callback again for the Allow header
	 * (rest_send_allow_header) and Protocol::handle() reads it as defense in depth: the credential is checked once,
	 * so a failure is counted once by the rate limiter and the audit log.
	 *
	 * @var \WeakMap<\WP_REST_Request, array<string, mixed>|true|\WP_Error>|null
	 */
	private static ?\WeakMap $results = null;

	public static function registerRoutes(): void
	{
		OriginGuard::installCorsFilter();
		add_filter('rest_pre_serve_request', [Protocol::class, 'serveSse'], 11, 4);
		add_filter('rest_request_before_callbacks', [self::class, 'resetAuthorization'], 10, 3);
		add_filter('rest_request_after_callbacks', [self::class, 'permissionErrorResponse'], 10, 3);
		register_rest_route('llamly/v1', '/endpoint', [
			'methods' => \WP_REST_Server::ALLMETHODS,
			'callback' => [Protocol::class, 'handle'],
			'permission_callback' => [self::class, 'permission'],
		]);
	}

	/**
	 * permission_callback of the MCP endpoint: the request Origin, then the caller's credential (Llamly API key or
	 * OAuth access token, Authenticator::authenticate()), then the OAuth token audience. Each tool call is then
	 * checked again against the credential's scope and the user's WordPress capabilities, under the capability
	 * ceiling (Protocol::handle()).
	 */
	public static function permission(\WP_REST_Request $request): bool|\WP_Error
	{
		$result = self::authorize($request);
		return is_wp_error($result) ? $result : true;
	}

	/** @return array<string, mixed>|true|\WP_Error the credential, true for a CORS preflight, or the error. */
	public static function authorize(\WP_REST_Request $request): array|bool|\WP_Error
	{
		self::$results ??= new \WeakMap();
		if (!isset(self::$results[$request])) {
			self::$results[$request] = Protocol::inEnglish(static fn() => self::check($request));
		}
		return self::$results[$request];
	}

	/** @return array<string, mixed>|true|\WP_Error */
	private static function check(\WP_REST_Request $request): array|bool|\WP_Error
	{
		if (strtoupper($request->get_method()) === 'OPTIONS') {
			// CORS preflight: browsers send it without credentials. WordPress answers it with the route description
			// (rest_handle_options_request) and never runs the endpoint callback; OriginGuard adds CORS headers only
			// for allowed origins.
			return true;
		}
		$origin = OriginGuard::check($request);
		if (is_wp_error($origin)) {
			return $origin;
		}
		$auth = Authenticator::authenticate($request);
		if (is_wp_error($auth)) {
			return $auth;
		}
		if (($auth['kind'] ?? '') === 'oauth' && !self::resourceMatches((string) ($auth['resource'] ?? ''))) {
			return new \WP_Error('invalid_resource', __('The access token is not issued for this MCP endpoint.', 'llamly'), ['status' => 401]);
		}
		return $auth;
	}

	/**
	 * Every dispatch of this route starts with no stored result, so a request object dispatched again (for example
	 * twice through rest_do_request() with another Authorization header, or after the credential was revoked) is
	 * checked again. rest_request_before_callbacks runs right before the permission_callback.
	 *
	 * @param mixed $response
	 * @param array<string, mixed> $handler
	 * @return mixed
	 */
	public static function resetAuthorization($response, $handler, $request)
	{
		if ($request instanceof \WP_REST_Request && $request->get_route() === self::ROUTE && self::$results !== null) {
			unset(self::$results[$request]);
		}
		return $response;
	}

	/**
	 * A permission_callback error would leave in the core REST format ({code, message, data}). MCP clients read the
	 * endpoint's own format ({error, message}, Cache-Control: no-store and, on 401, WWW-Authenticate with the OAuth
	 * resource metadata used for discovery), so only the errors produced by permission() for this route are rewritten.
	 *
	 * @param mixed $response
	 * @param array<string, mixed> $handler
	 * @return mixed
	 */
	public static function permissionErrorResponse($response, $handler, $request)
	{
		if (!is_wp_error($response) || !$request instanceof \WP_REST_Request || $request->get_route() !== self::ROUTE
			|| self::$results === null || !isset(self::$results[$request]) || self::$results[$request] !== $response
		) {
			return $response;
		}
		$converted = Protocol::errorResponse($response);
		// The Allow header WordPress sends for this route (rest_send_allow_header() leaves it out when the permission
		// check fails), kept as it was before the check moved into permission_callback.
		$methods = is_array($handler) && isset($handler['methods']) && is_array($handler['methods']) ? array_keys($handler['methods']) : [];
		if ($methods) {
			$converted->header('Allow', implode(', ', array_map('strtoupper', $methods)));
		}
		return $converted;
	}

	public static function resourceMatches(string $resource): bool
	{
		$expected = untrailingslashit(rest_url('llamly/v1/endpoint'));
		return hash_equals($expected, untrailingslashit($resource));
	}

	/**
	 * Registered instead of the real routes while the schema upgrade is pending or running: clients get a retryable
	 * 503 rather than a 404.
	 */
	public static function registerUpgradeRoute(): void
	{
		if (\Llamly\Free\Core\Plugin::runtimeRegistered()) {
			return; // this very request finished the upgrade: the real routes are being registered.
		}
		register_rest_route('llamly/v1', '/endpoint', [
			'methods' => \WP_REST_Server::ALLMETHODS,
			'callback' => [self::class, 'upgradeInProgress'],
			// Public by design: only answers 503 "updating, retry later" and never reads or changes data. The real
			// endpoint, with its permission_callback, replaces it once the schema upgrade has finished.
			'permission_callback' => '__return_true',
		]);
	}

	public static function upgradeInProgress(): \WP_REST_Response
	{
		$response = new \WP_REST_Response([
			'jsonrpc' => '2.0',
			'id' => null,
			'error' => [
				'code' => -32003,
				'message' => __('Llamly is updating its database. Try again in a few seconds.', 'llamly'),
				'data' => ['error' => 'upgrade_in_progress'],
			],
		], 503);
		$response->header('Retry-After', '30');
		$response->header('Cache-Control', 'no-store');
		return $response;
	}
}
