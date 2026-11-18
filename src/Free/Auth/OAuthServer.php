<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;
use Llamly\Free\History\MutationRunner;

final class OAuthServer
{
	private const ACCESS_TTL = 3600;
	private const REFRESH_TTL = 2592000;

	public static function registerHooks(): void
	{
		add_filter('query_vars', [self::class, 'queryVars']);
		add_action('template_redirect', [self::class, 'handleConsentPage'], 0);
	}

	public static function queryVars(array $vars): array
	{
		$vars[] = 'llamly_oauth_consent';
		return $vars;
	}

	public static function registerRoutes(): void
	{
		OriginGuard::installCorsFilter();
		register_rest_route('llamly/v1', '/oauth/register', [
			'methods' => 'POST',
			'callback' => [self::class, 'registerClient'],
			// Public by design (OAuth Dynamic Client Registration, RFC 7591): only while an administrator has opened the
			// registration window; it stores a client ID and grants no access.
			'permission_callback' => '__return_true',
		]);
		register_rest_route('llamly/v1', '/oauth/authorize', [
			'methods' => 'GET',
			'callback' => [self::class, 'authorize'],
			// Public by design (OAuth authorization endpoint, RFC 6749): it validates the request and redirects to the
			// consent page, where a signed-in user approves with a nonce and their own capabilities.
			'permission_callback' => '__return_true',
		]);
		register_rest_route('llamly/v1', '/oauth/token', [
			'methods' => 'POST',
			'callback' => [self::class, 'token'],
			// Public by design (OAuth token endpoint, RFC 6749): the caller proves the grant with the authorization code
			// and PKCE verifier, or with a refresh token.
			'permission_callback' => '__return_true',
		]);
		register_rest_route('llamly/v1', '/oauth/revoke', [
			'methods' => 'POST',
			'callback' => [self::class, 'revoke'],
			// Public by design (OAuth token revocation, RFC 7009): it can only revoke the token it is given.
			'permission_callback' => '__return_true',
		]);
	}

	public static function registerClient(\WP_REST_Request $request): \WP_REST_Response
	{
		$origin = OriginGuard::check($request);
		if (is_wp_error($origin)) {
			return self::oauthError($origin->get_error_code(), $origin->get_error_message(), 403);
		}
		$settings = Settings::get();
		if (empty($settings['_valid']) || empty($settings['enabled'])) {
			return self::oauthError('server_error', __('Llamly is not ready to register clients.', 'llamly'), 503);
		}
		if ((int) $settings['oauth_window_until'] <= time()) {
			return self::oauthError('registration_closed', __('New OAuth connections are closed. An administrator can open a 30-minute registration window in Llamly.', 'llamly'), 403);
		}
		$ip = RateLimiter::remoteAddress($request);
		if (!RateLimiter::allow('dcr-ip:' . hash('sha256', $ip), 20)) {
			return self::oauthError('rate_limited', __('Too many client registration requests.', 'llamly'), 429);
		}

		$body = $request->get_json_params();
		if (!is_array($body)) {
			return self::oauthError('invalid_client_metadata', __('Send a JSON client registration request.', 'llamly'), 400);
		}
		$uris = $body['redirect_uris'] ?? null;
		if (!is_array($uris) || count($uris) < 1 || count($uris) > 10) {
			return self::oauthError('invalid_redirect_uri', __('Register between one and ten redirect URIs.', 'llamly'), 400);
		}
		foreach ($uris as $uri) {
			if (!is_string($uri)) {
				return self::oauthError('invalid_redirect_uri', __('Every redirect URI must be a string.', 'llamly'), 400);
			}
		}
		// Like Cowboy: clients such as Cursor register a list mixing loopback, custom-scheme and web
		// entries. Keep only entries that satisfy the policy (https and loopback); fail if none survive.
		$uris = array_values(array_unique(array_filter($uris, [OAuthRedirectPolicy::class, 'allows'])));
		if (count($uris) < 1) {
			return self::oauthError('invalid_redirect_uri', __('No redirect URI satisfies the site redirect-host policy.', 'llamly'), 400);
		}
		$responseTypes = $body['response_types'] ?? ['code'];
		$grantTypes = $body['grant_types'] ?? ['authorization_code', 'refresh_token'];
		$normalizedGrantTypes = is_array($grantTypes) ? array_values(array_unique($grantTypes)) : [];
		sort($normalizedGrantTypes);
		if (!is_array($responseTypes) || $responseTypes !== ['code']
			|| !is_array($grantTypes) || $normalizedGrantTypes !== ['authorization_code', 'refresh_token']
		) {
			return self::oauthError('invalid_client_metadata', __('Only authorization-code clients with PKCE are supported.', 'llamly'), 400);
		}
		if (isset($body['token_endpoint_auth_method']) && $body['token_endpoint_auth_method'] !== 'none') {
			return self::oauthError('invalid_client_metadata', __('Public clients must use token_endpoint_auth_method=none and PKCE.', 'llamly'), 400);
		}
		if (isset($body['client_name']) && !is_string($body['client_name'])) {
			return self::oauthError('invalid_client_metadata', __('The client name must be a string.', 'llamly'), 400);
		}
		$name = sanitize_text_field((string) ($body['client_name'] ?? 'MCP client'));
		$name = $name !== '' ? substr($name, 0, 160) : 'MCP client';
		try {
			$clientId = 'llc_' . bin2hex(random_bytes(24));
		} catch (\Throwable $error) {
			return self::oauthError('server_error', __('A secure client ID could not be created.', 'llamly'), 503);
		}

		global $wpdb;
		$table = Schema::tableNames()['oauth_clients'];
		$lockName = 'llamly:dcr:' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 32);
		$lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
		if ((int) $lock !== 1 || $wpdb->last_error !== '') {
			return self::oauthError('server_error', __('The client registration lock is unavailable.', 'llamly'), 503);
		}
		try {
			if (!self::beginTransaction()) {
				return self::oauthError('server_error', __('The client registration transaction could not start.', 'llamly'), 503);
			}
			$activeClients = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL', $table));
			if ($wpdb->last_error !== '') {
				$wpdb->query('ROLLBACK');
				return self::oauthError('server_error', __('The client registration limit could not be checked.', 'llamly'), 503);
			}
			if ($activeClients >= 100) {
				$wpdb->query('ROLLBACK');
				return self::oauthError('registration_limit', __('This site has reached its limit of 100 active OAuth clients. Revoke an unused client before registering another.', 'llamly'), 429);
			}
			$inserted = $wpdb->insert($table, [
				'client_id' => $clientId,
				'client_secret_hash' => null,
				'user_id' => null,
				'name' => $name,
				'redirect_uris' => wp_json_encode($uris),
				'created_at' => gmdate('Y-m-d H:i:s'),
			], ['%s', '%s', '%d', '%s', '%s', '%s']);
			$audited = $inserted === 1 && AuditLog::append('oauth_client_registered', [
				'client_id' => $clientId,
				// Persisted audit context: the loopback label stays in English. The Logs tab shows the context raw; labels in lists are translated.
				'redirect_hosts' => array_values(array_unique(array_map(static fn (string $uri): string => OAuthRedirectPolicy::hostForLabel($uri, false), $uris))),
			], null, null, null);
			if (!$audited || !self::commitTransaction()) {
				$wpdb->query('ROLLBACK');
				return self::oauthError('server_error', __('The client registration could not be stored with its audit record.', 'llamly'), 503);
			}

			return self::noStore(new \WP_REST_Response([
				'client_id' => $clientId,
				'client_id_issued_at' => time(),
				'client_name' => $name,
				'redirect_uris' => $uris,
				'response_types' => ['code'],
				'grant_types' => ['authorization_code', 'refresh_token'],
				'token_endpoint_auth_method' => 'none',
			], 201));
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
		}
	}

	public static function authorize(\WP_REST_Request $request): \WP_REST_Response
	{
		// Only Dynamic Client Registration is gated by the window. An already registered, non-revoked client may
		// start a new authorization (reconnect) with the window closed; the admin still has to approve on the consent page.
		$ready = self::requireServerReady();
		if (is_wp_error($ready)) {
			return self::oauthError($ready->get_error_code(), $ready->get_error_message(), 503);
		}
		$validated = self::validatedAuthorizationRequest($request->get_query_params());
		if (is_wp_error($validated)) {
			return self::oauthError($validated->get_error_code(), $validated->get_error_message(), 400);
		}
		$consentUrl = self::consentUrl($validated);
		nocache_headers();
		wp_safe_redirect($consentUrl, 302, 'Llamly OAuth');
		exit;
	}

	public static function handleConsentPage(): void
	{
		if ((string) get_query_var('llamly_oauth_consent') !== '1') {
			return;
		}
		\Llamly\Free\Core\Plugin::useUserLocale();
		nocache_headers();
		header('Referrer-Policy: no-referrer');
		header('X-Content-Type-Options: nosniff');
		$isPost = isset($_SERVER['REQUEST_METHOD']) && sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) === 'post';
		$requestData = self::requestParams($isPost);
		$ready = self::requireServerReady();
		if (is_wp_error($ready)) {
			self::consentError(503, __('Llamly is not ready to accept new connections on this site.', 'llamly'), __('Ask an administrator to check that the MCP server is turned on in Llamly > Settings.', 'llamly'));
		}
		$validated = self::validatedAuthorizationRequest($requestData);
		if (is_wp_error($validated)) {
			self::consentError(400, __('This connection request is invalid.', 'llamly'), __('Go back to the app and start the connection again.', 'llamly'), $validated->get_error_message());
		}
		if (!is_user_logged_in()) {
			$target = self::consentUrl($validated);
			wp_safe_redirect(wp_login_url($target), 302, 'Llamly OAuth');
			exit;
		}

		self::requireAdminWhenWindowClosed($validated);
		if ($isPost) {
			self::completeConsent($validated, $requestData);
		}
		self::renderConsent($validated);
	}

	/**
	 * The parameters of the OAuth authorization request from the client (GET) or of the consent form (POST), each read
	 * on its own and sanitized for its kind; anything else in the request is ignored. validatedAuthorizationRequest()
	 * and completeConsent() then validate every value. No nonce exists on the client's first request; the consent POST
	 * is checked with wp_verify_nonce() in completeConsent() before anything is granted.
	 *
	 * @return array<string, string|list<string>>
	 */
	private static function requestParams(bool $isPost): array
	{
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- see above.
		$params = [];
		foreach (['client_id', 'code_challenge', 'code_challenge_method', 'response_type', 'scope', 'decision', '_llamly_nonce'] as $key) {
			if ($isPost ? isset($_POST[$key]) && is_string($_POST[$key]) : isset($_GET[$key]) && is_string($_GET[$key])) {
				$params[$key] = $isPost ? sanitize_text_field(wp_unslash($_POST[$key])) : sanitize_text_field(wp_unslash($_GET[$key]));
			}
		}
		// state is opaque to the server and must go back to the client byte for byte (RFC 6749: printable ASCII), so it is
		// kept exactly or refused, never rewritten. A value outside that set becomes "\0", which validatedAuthorizationRequest()
		// refuses as invalid_request; a missing state stays optional.
		$stateFilter = ['options' => ['regexp' => '/^[\x20-\x7E]{0,512}$/']];
		$state = $isPost
			? (isset($_POST['state']) && is_string($_POST['state']) ? filter_var(wp_unslash($_POST['state']), FILTER_VALIDATE_REGEXP, $stateFilter) : null)
			: (isset($_GET['state']) && is_string($_GET['state']) ? filter_var(wp_unslash($_GET['state']), FILTER_VALIDATE_REGEXP, $stateFilter) : null);
		if ($state !== null) {
			$params['state'] = $state === false ? "\x00" : $state;
		}
		foreach (['redirect_uri', 'resource'] as $key) {
			if ($isPost ? isset($_POST[$key]) && is_string($_POST[$key]) : isset($_GET[$key]) && is_string($_GET[$key])) {
				$params[$key] = $isPost ? esc_url_raw(wp_unslash($_POST[$key]), ['http', 'https']) : esc_url_raw(wp_unslash($_GET[$key]), ['http', 'https']);
			}
		}
		if ($isPost && isset($_POST['grant_scopes']) && is_array($_POST['grant_scopes'])) {
			$params['grant_scopes'] = array_values(array_map('sanitize_text_field', wp_unslash($_POST['grant_scopes'])));
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		return $params;
	}

	/** True when the user may approve this sign-in: always while the window is open; only an administrator while it is closed. */
	private static function mayApproveSignIn(): bool
	{
		$settings = Settings::get();
		if ((int) ($settings['oauth_window_until'] ?? 0) > time()) {
			return true;
		}
		return current_user_can('manage_options');
	}

	/** With the window closed, an existing app can only reconnect when an administrator approves (same rule as Cowboy). */
	private static function requireAdminWhenWindowClosed(array $request): void
	{
		if (!self::mayApproveSignIn()) {
			self::consentError(403, __('An administrator must approve this connection.', 'llamly'), __('New connections are turned off on this site. Sign in as an administrator to approve it, or ask an administrator to turn on new connections for 30 minutes in Llamly > Connections.', 'llamly'), '', self::switchAccountUrl($request));
		}
	}

	private static function renderConsent(array $request): void
	{
		$clientLabel = OAuthRedirectPolicy::hostForLabel($request['redirect_uri']);
		$requested = $request['scope_list'];
		$canRead = in_array('llamly.read', $requested, true) && current_user_can('read');
		$canWrite = MutationRunner::writeEnabled() && in_array('llamly.write', $requested, true) && current_user_can('edit_posts');
		if (!$canRead && !$canWrite) {
			self::consentError(403, __('Your account cannot approve this connection.', 'llamly'), __('Sign in with an account that can edit this site, or ask an administrator to connect the app.', 'llamly'), '', self::switchAccountUrl($request));
		}
		$icon = 'anthropic.svg';
		if ($clientLabel === 'ChatGPT') {
			$icon = 'openai.svg';
		} elseif ($clientLabel === 'Claude') {
			$icon = 'claude-color.svg';
		} elseif ($clientLabel === 'Cursor') {
			$icon = 'cursor.svg';
		}
		$nonce = wp_nonce_field('llamly_free_oauth_consent', '_llamly_nonce', false, false);
		$hidden = $request;
		unset($hidden['scope_list']);
		$consent = [
			'site_name' => wp_strip_all_tags(get_bloginfo('name')),
			'site_host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
			'user_name' => wp_get_current_user()->display_name,
			'client_label' => $clientLabel,
			'redirect_host' => (string) wp_parse_url($request['redirect_uri'], PHP_URL_HOST),
			'client_icon' => LLAMLY_FREE_ASSET_URL . 'assets/clients/' . $icon,
			'action' => add_query_arg('llamly_oauth_consent', '1', home_url('/')),
			'nonce_html' => $nonce,
			'request' => $hidden,
			'can_read' => $canRead,
			'can_write' => $canWrite,
			'arrow_icon' => '<span class="ll-icon" aria-hidden="true">→</span>',
			'warning_icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.29 3.86-8.3 14.36A2 2 0 0 0 3.72 21h16.56a2 2 0 0 0 1.73-2.78l-8.3-14.36a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
		];
		$html = self::consentTemplate($consent);
		header('Content-Type: text/html; charset=utf-8');
		header('Content-Security-Policy: ' . self::consentCsp((string) $request['redirect_uri']));
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered templates/free/consent.php, which escapes every value it prints.
		exit;
	}

	/** Content-Security-Policy of the consent page for a validated redirect URI. */
	private static function consentCsp(string $redirectUri): string
	{
		// Chromium applies form-action to every redirect hop that follows the consent POST, not only to the first one.
		// Some clients answer their callback with a further redirect to another host (Cursor: www.cursor.com 308 to
		// cursor.com), so any https: target is allowed, plus the exact redirect origin, which keeps http loopback
		// redirects (http://127.0.0.1:PORT) working.
		// Accepted trade-off: the redirect_uri was validated against the client's registration and this page runs no script.
		$formAction = "'self' https:";
		$redirectOrigin = self::redirectOrigin($redirectUri);
		if ($redirectOrigin !== '') {
			$formAction .= ' ' . $redirectOrigin;
		}
		return "default-src 'self'; img-src 'self' data:; style-src 'self'; form-action {$formAction}; base-uri 'none'; frame-ancestors 'none'";
	}

	/**
	 * Error state of the consent page: same card and brand as the request, with the status wp_die() used
	 * before. No form, no nonce and no authorization code are emitted; the page sends a CSP without script-src.
	 */
	private static function consentError(int $status, string $message, string $guidance = '', string $detail = '', string $switchUrl = ''): void
	{
		$html = self::consentTemplate([
			'site_name' => wp_strip_all_tags(get_bloginfo('name')),
			'site_host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
			'error' => ['message' => $message, 'guidance' => $guidance, 'detail' => $detail, 'switch_url' => $switchUrl],
		]);
		status_header($status);
		header('Content-Type: text/html; charset=utf-8');
		header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered templates/free/consent.php, which escapes every value it prints.
		exit;
	}

	/** Buffered templates/free/consent.php. The page is a standalone document (no wp_head); its styles are registered and printed by handle. */
	private static function consentTemplate(array $consent): string
	{
		$styleBase = LLAMLY_FREE_ASSET_URL . 'css/';
		wp_register_style('llamly-free-tokens', $styleBase . 'tokens.css', [], LLAMLY_FREE_VERSION);
		wp_register_style('llamly-free-llamly', $styleBase . 'llamly.css', ['llamly-free-tokens'], LLAMLY_FREE_VERSION);
		wp_register_style('llamly-free-consent', $styleBase . 'consent.css', ['llamly-free-llamly'], LLAMLY_FREE_VERSION);
		ob_start();
		include LLAMLY_FREE_ROOT . '/templates/free/consent.php';
		return (string) ob_get_clean();
	}

	/** Log out and come back to the same (validated) request, as the "Switch account" link of the consent form does. */
	private static function switchAccountUrl(array $request): string
	{
		$hidden = $request;
		unset($hidden['scope_list']);
		return wp_logout_url(add_query_arg(array_map('rawurlencode', array_map('strval', $hidden)), add_query_arg('llamly_oauth_consent', '1', home_url('/'))));
	}

	private static function completeConsent(array $request, array $post): void
	{
		if (!isset($post['_llamly_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $post['_llamly_nonce']), 'llamly_free_oauth_consent')) {
			self::consentError(403, __('This consent form expired.', 'llamly'), __('Go back to the app and start the connection again.', 'llamly'));
		}
		$decision = sanitize_key((string) ($post['decision'] ?? ''));
		if ($decision === 'deny') {
			self::redirectToClient($request['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'Access was denied by the WordPress user.', 'state' => (string) $request['state'], 'iss' => self::issuer()]);
		}
		if ($decision !== 'approve') {
			self::consentError(400, __('Choose whether to approve or refuse this connection.', 'llamly'), __('Go back to the app and start the connection again.', 'llamly'));
		}
		$selected = self::normalizeConsentScopes($post['grant_scopes'] ?? null, $request['scope_list']);
		if (is_wp_error($selected)) {
			self::consentError(400, $selected->get_error_message(), __('Go back to the app and start the connection again.', 'llamly'));
		}
		if (in_array('llamly.read', $selected, true) && !current_user_can('read')) {
			self::consentError(403, __('Your WordPress user cannot grant read access.', 'llamly'), __('Sign in with an account that can edit this site, or ask an administrator to connect the app.', 'llamly'), '', self::switchAccountUrl($request));
		}
		if (in_array('llamly.write', $selected, true) && (!MutationRunner::writeEnabled() || !current_user_can('edit_posts'))) {
			self::consentError(403, __('Your WordPress user cannot grant write access.', 'llamly'), __('Sign in with an account that can edit this site, or ask an administrator to connect the app.', 'llamly'), '', self::switchAccountUrl($request));
		}
		$code = self::issueAuthorizationCode($request, $selected, get_current_user_id());
		if (is_wp_error($code)) {
			self::consentError(503, $code->get_error_message(), __('Try again in a moment.', 'llamly'));
		}
		self::redirectToClient($request['redirect_uri'], ['code' => $code, 'state' => (string) $request['state'], 'iss' => self::issuer()]);
	}

	/** @return list<string>|\WP_Error */
	private static function normalizeConsentScopes(mixed $submitted, array $requested): array|\WP_Error
	{
		if (!is_array($submitted) || !$submitted) {
			return new \WP_Error('invalid_scope_selection', __('Select at least one requested permission.', 'llamly'));
		}

		$selected = [];
		foreach ($submitted as $scope) {
			if (!is_string($scope)
				|| !in_array($scope, ['llamly.read', 'llamly.write'], true)
				|| !in_array($scope, $requested, true)
			) {
				return new \WP_Error('invalid_scope_selection', __('Select only permissions requested by this client.', 'llamly'));
			}
			$selected[] = $scope;
		}

		return array_values(array_unique($selected));
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function validatedAuthorizationRequest(array $source): array|\WP_Error
	{
		global $wpdb;
		$clientId = sanitize_text_field((string) ($source['client_id'] ?? ''));
		$redirectUri = (string) ($source['redirect_uri'] ?? '');
		$challenge = (string) ($source['code_challenge'] ?? '');
		$method = (string) ($source['code_challenge_method'] ?? '');
		$responseType = (string) ($source['response_type'] ?? '');
		$resource = (string) ($source['resource'] ?? rest_url('llamly/v1/endpoint'));
		$state = (string) ($source['state'] ?? '');
		if ($clientId === '' || $responseType !== 'code' || $method !== 'S256'
			|| !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)
			|| strlen($state) > 512 || preg_match('/[\x00-\x1f\x7f]/', $state)
			|| untrailingslashit($resource) !== untrailingslashit(rest_url('llamly/v1/endpoint'))
			|| !OAuthRedirectPolicy::allows($redirectUri)
		) {
			return new \WP_Error('invalid_request', __('The OAuth request is invalid or uses an unsupported resource, redirect, or PKCE method.', 'llamly'));
		}
		$client = $wpdb->get_row($wpdb->prepare(
			'SELECT client_id, redirect_uris, revoked_at FROM %i WHERE client_id = %s LIMIT 1',
			Schema::tableNames()['oauth_clients'],
			$clientId
		), ARRAY_A);
		$uris = $client ? json_decode((string) $client['redirect_uris'], true) : null;
		if (!$client || !empty($client['revoked_at']) || !is_array($uris) || !in_array($redirectUri, $uris, true)) {
			return new \WP_Error('invalid_client', __('The client or exact redirect URI is not registered.', 'llamly'));
		}
		$scopeText = trim((string) ($source['scope'] ?? 'llamly.read'));
		$scopeList = $scopeText === '' ? ['llamly.read'] : array_values(array_unique(preg_split('/\s+/', $scopeText) ?: []));
		if (!$scopeList || array_diff($scopeList, ['llamly.read', 'llamly.write'])) {
			return new \WP_Error('invalid_scope', __('The client requested an unsupported Llamly scope.', 'llamly'));
		}
		return [
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'code_challenge' => $challenge,
			'code_challenge_method' => $method,
			'response_type' => $responseType,
			'resource' => untrailingslashit($resource),
			'state' => $state,
			'scope' => implode(' ', $scopeList),
			'scope_list' => $scopeList,
		];
	}

	private static function issueAuthorizationCode(array $request, array $scopes, int $userId): string|\WP_Error
	{
		global $wpdb;
		try {
			$grantId = wp_generate_uuid4();
			$code = 'llc_' . bin2hex(random_bytes(32));
		} catch (\Throwable $error) {
			return new \WP_Error('storage_unavailable', __('A secure authorization code could not be created.', 'llamly'));
		}
		$tables = Schema::tableNames();
		$now = gmdate('Y-m-d H:i:s');
		if (!self::beginTransaction()) {
			return new \WP_Error('journal_unavailable', __('The authorization grant transaction could not start.', 'llamly'));
		}
		$grant = $wpdb->insert($tables['oauth_grants'], [
			'id' => $grantId,
			'client_id' => $request['client_id'],
			'user_id' => $userId,
			'resource' => $request['resource'],
			'scopes' => wp_json_encode(array_values($scopes)),
			'created_at' => $now,
		], ['%s', '%s', '%d', '%s', '%s', '%s']);
		$codeRow = $grant === 1 ? $wpdb->insert($tables['oauth_codes'], [
			'code_hash' => hash('sha256', $code),
			'grant_id' => $grantId,
			'client_id' => $request['client_id'],
			'user_id' => $userId,
			'redirect_uri' => $request['redirect_uri'],
			'code_challenge' => $request['code_challenge'],
			'resource' => $request['resource'],
			'scopes' => wp_json_encode(array_values($scopes)),
			'state_value' => $request['state'],
			'expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
			'created_at' => $now,
		], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']) : false;
		$audited = $codeRow === 1 && AuditLog::append('oauth_grant_created', [
			'client_id' => $request['client_id'],
			'grant_id' => $grantId,
			'scopes' => array_values($scopes),
			'resource' => $request['resource'],
		], $userId, null, 'oauth:' . $grantId);
		if (!$audited || !self::commitTransaction()) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('journal_unavailable', __('The authorization grant and its audit record could not be stored.', 'llamly'));
		}
		return $code;
	}

	public static function token(\WP_REST_Request $request): \WP_REST_Response
	{
		$origin = OriginGuard::check($request);
		if (is_wp_error($origin)) {
			return self::oauthError('invalid_request', $origin->get_error_message(), 403);
		}
		$params = $request->get_body_params();
		if (!$params) {
			$params = $request->get_json_params();
		}
		if (!is_array($params)) {
			return self::oauthError('invalid_request', __('Send OAuth token parameters.', 'llamly'), 400);
		}
		$grantType = (string) ($params['grant_type'] ?? '');
		if ($grantType === 'authorization_code') {
			$result = self::exchangeCode($params);
		} elseif ($grantType === 'refresh_token') {
			$result = self::refresh($params);
		} else {
			return self::oauthError('unsupported_grant_type', __('Only authorization_code and refresh_token grants are supported.', 'llamly'), 400);
		}
		if (is_wp_error($result)) {
			$code = $result->get_error_code();
			$data = $result->get_error_data($code);
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
			return self::oauthError($code, $result->get_error_message(), $status);
		}
		return self::noStore(new \WP_REST_Response($result, 200));
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function exchangeCode(array $params): array|\WP_Error
	{
		global $wpdb;
		$code = (string) ($params['code'] ?? '');
		$clientId = sanitize_text_field((string) ($params['client_id'] ?? ''));
		$redirectUri = (string) ($params['redirect_uri'] ?? '');
		$verifier = (string) ($params['code_verifier'] ?? '');
		$resource = untrailingslashit((string) ($params['resource'] ?? rest_url('llamly/v1/endpoint')));
		if (!preg_match('/^llc_[a-f0-9]{64}$/', $code) || $clientId === ''
			|| !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
			|| !OAuthRedirectPolicy::allows($redirectUri)
			|| $resource !== untrailingslashit(rest_url('llamly/v1/endpoint'))
		) {
			return new \WP_Error('invalid_grant', __('The authorization code, PKCE verifier, redirect, or resource is invalid.', 'llamly'), ['status' => 400]);
		}
		$table = Schema::tableNames();
		if (!self::beginTransaction()) {
			return new \WP_Error('server_error', __('The authorization-code transaction could not start.', 'llamly'), ['status' => 503]);
		}
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT c.*, g.revoked_at AS grant_revoked, cl.revoked_at AS client_revoked FROM %i c
			 INNER JOIN %i g ON g.id = c.grant_id
			 INNER JOIN %i cl ON cl.client_id = c.client_id
			 WHERE c.code_hash = %s FOR UPDATE",
			$table['oauth_codes'],
			$table['oauth_grants'],
			$table['oauth_clients'],
			hash('sha256', $code)
		), ARRAY_A);
		if ($wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('The authorization-code record could not be read.', 'llamly'), ['status' => 503]);
		}
		if ($row && !empty($row['consumed_at']) && hash_equals((string) $row['client_id'], $clientId)) {
			// RFC 6749 §4.1.2: a replayed code revokes every token issued from its grant.
			return self::revokeGrantAfterReuse($row, 'oauth_code_reuse_detected', __('The authorization code was already used; tokens issued from it have been revoked.', 'llamly'));
		}
		if (!$row || !empty($row['consumed_at']) || !empty($row['grant_revoked']) || !empty($row['client_revoked'])
			|| strtotime((string) $row['expires_at'] . ' UTC') <= time()
			|| !hash_equals((string) $row['client_id'], $clientId)
			|| !hash_equals((string) $row['redirect_uri'], $redirectUri)
			|| !hash_equals((string) $row['resource'], $resource)
		) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', __('The authorization code is invalid, expired, or already used.', 'llamly'), ['status' => 400]);
		}
		$computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		if (!hash_equals((string) $row['code_challenge'], $computedChallenge)) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', __('The PKCE verifier does not match the authorization request.', 'llamly'), ['status' => 400]);
		}
		$consumed = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at > %s',
			$table['oauth_codes'],
			gmdate('Y-m-d H:i:s'),
			(int) $row['id'],
			gmdate('Y-m-d H:i:s')
		));
		if ($consumed !== 1) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', __('The authorization code was already consumed.', 'llamly'), ['status' => 400]);
		}
		$tokens = self::insertTokenPair((string) $row['grant_id'], $clientId, (int) $row['user_id'], $resource, (string) $row['scopes'], wp_generate_uuid4(), null);
		$audited = !is_wp_error($tokens) && AuditLog::append('oauth_tokens_issued', [
			'client_id' => $clientId,
			'grant_id' => (string) $row['grant_id'],
			'flow' => 'authorization_code',
		], (int) $row['user_id'], null, 'oauth:' . (string) $row['grant_id']);
		if (!$audited || !self::commitTransaction()) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('The token response could not be saved with its audit record.', 'llamly'), ['status' => 503]);
		}
		return $tokens;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function refresh(array $params): array|\WP_Error
	{
		global $wpdb;
		$rawToken = (string) ($params['refresh_token'] ?? '');
		$clientId = sanitize_text_field((string) ($params['client_id'] ?? ''));
		$resource = untrailingslashit((string) ($params['resource'] ?? rest_url('llamly/v1/endpoint')));
		if (!preg_match('/^llrt_[a-f0-9]{64}$/', $rawToken) || $clientId === '' || $resource !== untrailingslashit(rest_url('llamly/v1/endpoint'))) {
			return new \WP_Error('invalid_grant', __('The refresh token, client, or resource is invalid.', 'llamly'), ['status' => 400]);
		}
		$tables = Schema::tableNames();
		if (!self::beginTransaction()) {
			return new \WP_Error('server_error', __('The refresh-token transaction could not start.', 'llamly'), ['status' => 503]);
		}
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT t.*, g.revoked_at AS grant_revoked, c.revoked_at AS client_revoked FROM %i t
			 INNER JOIN %i g ON g.id = t.grant_id
			 INNER JOIN %i c ON c.client_id = t.client_id
			 WHERE t.token_hash = %s AND t.token_type = 'refresh' FOR UPDATE",
			$tables['oauth_tokens'],
			$tables['oauth_grants'],
			$tables['oauth_clients'],
			hash('sha256', $rawToken)
		), ARRAY_A);
		if ($wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('The refresh-token record could not be read.', 'llamly'), ['status' => 503]);
		}
		if ($row && !empty($row['consumed_at'])) {
			// Consumed is checked before expiry, revocation and client: any reuse of a leaked token revokes the whole family.
			return self::revokeGrantAfterReuse($row, 'oauth_refresh_reuse_detected', __('Refresh token reuse was detected; the token family has been revoked.', 'llamly'));
		}
		if (!$row || !empty($row['revoked_at']) || !empty($row['grant_revoked']) || !empty($row['client_revoked'])
			|| !hash_equals((string) $row['client_id'], $clientId)
			|| !hash_equals((string) $row['resource'], $resource)
			|| strtotime((string) $row['expires_at'] . ' UTC') <= time()
		) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', __('The refresh token is invalid, expired, or revoked.', 'llamly'), ['status' => 400]);
		}
		$consumed = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND revoked_at IS NULL',
			$tables['oauth_tokens'],
			gmdate('Y-m-d H:i:s'),
			(int) $row['id']
		));
		if ($consumed !== 1) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', __('The refresh token was already consumed.', 'llamly'), ['status' => 400]);
		}
		$tokens = self::insertTokenPair((string) $row['grant_id'], $clientId, (int) $row['user_id'], $resource, (string) $row['scopes'], (string) $row['family_id'], (int) $row['id']);
		$audited = !is_wp_error($tokens) && AuditLog::append('oauth_tokens_refreshed', ['client_id' => $clientId, 'grant_id' => $row['grant_id']], (int) $row['user_id'], null, 'oauth:' . (string) $row['grant_id']);
		if (!$audited || !self::commitTransaction()) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('The refreshed token pair could not be saved with its audit record.', 'llamly'), ['status' => 503]);
		}
		return $tokens;
	}

	/**
	 * Inside an open transaction: revokes the token family (if any) and the grant, audits, commits, and
	 * returns invalid_grant. Repeated replays of an already-revoked grant just return invalid_grant.
	 *
	 * @param array<string, mixed> $row Locked code or refresh-token row (needs grant_id, client_id, user_id).
	 */
	private static function revokeGrantAfterReuse(array $row, string $event, string $message): \WP_Error
	{
		global $wpdb;
		$tables = Schema::tableNames();
		$now = gmdate('Y-m-d H:i:s');
		$tokens = $wpdb->update($tables['oauth_tokens'], ['revoked_at' => $now], ['grant_id' => $row['grant_id'], 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$grant = $wpdb->update($tables['oauth_grants'], ['revoked_at' => $now], ['id' => $row['grant_id'], 'revoked_at' => null], ['%s'], ['%s', '%s']);
		if ($tokens === false || $grant === false) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('Token reuse was detected, but revocation could not be saved.', 'llamly'), ['status' => 503]);
		}
		if ((int) $tokens + (int) $grant === 0) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('invalid_grant', $message, ['status' => 400]);
		}
		$audited = AuditLog::append($event, ['client_id' => (string) $row['client_id'], 'grant_id' => (string) $row['grant_id'], 'tokens_revoked' => (int) $tokens], (int) $row['user_id'], null, 'oauth:' . (string) $row['grant_id']);
		if (!$audited || !self::commitTransaction()) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('server_error', __('Token reuse was detected, but revocation could not be committed with its audit record.', 'llamly'), ['status' => 503]);
		}
		return new \WP_Error('invalid_grant', $message, ['status' => 400]);
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function insertTokenPair(string $grantId, string $clientId, int $userId, string $resource, string $scopesJson, string $familyId, ?int $parentId): array|\WP_Error
	{
		global $wpdb;
		try {
			$access = 'llat_' . bin2hex(random_bytes(32));
			$refresh = 'llrt_' . bin2hex(random_bytes(32));
		} catch (\Throwable $error) {
			return new \WP_Error('server_error', __('Secure access tokens could not be created.', 'llamly'));
		}
		$table = Schema::tableNames()['oauth_tokens'];
		$now = gmdate('Y-m-d H:i:s');
		$accessExpires = gmdate('Y-m-d H:i:s', time() + self::ACCESS_TTL);
		$refreshExpires = gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL);
		$scopes = json_decode($scopesJson, true);
		if (!is_array($scopes) || !$scopes) {
			return new \WP_Error('invalid_scope', __('The grant does not contain a valid scope.', 'llamly'));
		}
		$accessId = $wpdb->insert($table, [
			'token_hash' => hash('sha256', $access),
			'token_type' => 'access',
			'grant_id' => $grantId,
			'family_id' => $familyId,
			'parent_id' => $parentId,
			'client_id' => $clientId,
			'user_id' => $userId,
			'resource' => $resource,
			'scopes' => wp_json_encode(array_values($scopes)),
			'created_at' => $now,
			'expires_at' => $accessExpires,
		], ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
		$refreshId = $accessId === 1 ? $wpdb->insert($table, [
			'token_hash' => hash('sha256', $refresh),
			'token_type' => 'refresh',
			'grant_id' => $grantId,
			'family_id' => $familyId,
			'parent_id' => $parentId,
			'client_id' => $clientId,
			'user_id' => $userId,
			'resource' => $resource,
			'scopes' => wp_json_encode(array_values($scopes)),
			'created_at' => $now,
			'expires_at' => $refreshExpires,
		], ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']) : false;
		if ($accessId !== 1 || $refreshId !== 1) {
			return new \WP_Error('storage_unavailable', __('The token pair could not be stored.', 'llamly'));
		}
		return [
			'access_token' => $access,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'refresh_token_expires_in' => self::REFRESH_TTL,
			'scope' => implode(' ', $scopes),
		];
	}

	public static function revoke(\WP_REST_Request $request): \WP_REST_Response
	{
		$origin = OriginGuard::check($request);
		if (is_wp_error($origin)) {
			return self::oauthError('invalid_request', $origin->get_error_message(), 403);
		}
		$params = $request->get_body_params();
		if (!$params) {
			$params = $request->get_json_params();
		}
		$token = is_array($params) ? (string) ($params['token'] ?? '') : '';
		$clientId = is_array($params) ? sanitize_text_field((string) ($params['client_id'] ?? '')) : '';
		if (!preg_match('/^ll(?:at|rt)_[a-f0-9]{64}$/', $token) || $clientId === '') {
			return new \WP_REST_Response(null, 200);
		}
		global $wpdb;
		$tables = Schema::tableNames();
		if (!self::beginTransaction()) {
			return self::oauthError('server_error', __('The revocation transaction could not start.', 'llamly'), 503);
		}
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM %i WHERE token_hash = %s AND client_id = %s FOR UPDATE',
			$tables['oauth_tokens'],
			hash('sha256', $token),
			$clientId
		), ARRAY_A);
		if ($wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return self::oauthError('server_error', __('The token record could not be read.', 'llamly'), 503);
		}
		if ($row) {
			$now = gmdate('Y-m-d H:i:s');
			$family = $wpdb->update($tables['oauth_tokens'], ['revoked_at' => $now], ['family_id' => $row['family_id'], 'revoked_at' => null], ['%s'], ['%s', '%s']);
			$grant = $wpdb->update($tables['oauth_grants'], ['revoked_at' => $now], ['id' => $row['grant_id'], 'revoked_at' => null], ['%s'], ['%s', '%s']);
			$audited = $family !== false && $grant !== false
				&& AuditLog::append('oauth_grant_revoked', ['client_id' => $clientId, 'grant_id' => $row['grant_id']], (int) $row['user_id'], null, 'oauth:' . (string) $row['grant_id']);
			if (!$audited || !self::commitTransaction()) {
				$wpdb->query('ROLLBACK');
				return self::oauthError('server_error', __('The grant could not be revoked.', 'llamly'), 503);
			}
		} else {
			$wpdb->query('ROLLBACK');
		}
		return new \WP_REST_Response(null, 200);
	}

	/** @param array<string, mixed> $validated */
	private static function consentUrl(array $validated): string
	{
		unset($validated['scope_list']);
		$base = home_url('/');
		return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query(array_merge(['llamly_oauth_consent' => '1'], $validated), '', '&', PHP_QUERY_RFC3986);
	}

	private static function issuer(): string
	{
		return untrailingslashit(home_url('/'));
	}

	/** Exact `scheme://host[:port]` of a validated redirect URI, or '' when it cannot be expressed safely. */
	private static function redirectOrigin(string $uri): string
	{
		$parts = wp_parse_url($uri);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower(trim((string) $parts['host'], '[]'));
		if (!in_array($scheme, ['https', 'http'], true) || !preg_match('/^[a-z0-9.:-]+$/', $host)) {
			return '';
		}
		if (strpos($host, ':') !== false) {
			$host = '[' . $host . ']';
		}
		$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}

	/** @param array<string, string> $params */
	private static function redirectToClient(string $uri, array $params): void
	{
		$fragment = '';
		$hashPos = strpos($uri, '#');
		if ($hashPos !== false) {
			$fragment = substr($uri, $hashPos);
			$uri = substr($uri, 0, $hashPos);
		}
		$params = array_filter($params, static fn ($value): bool => $value !== '');
		$query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		$target = $uri . (strpos($uri, '?') === false ? '?' : (substr($uri, -1) === '?' || substr($uri, -1) === '&' ? '' : '&')) . $query . $fragment;
		// The client's redirect_uri is an external host by design (OAuth); it was matched against the URIs registered by
		// that client and the redirect-host allowlist before this point.
		wp_redirect($target, 302, 'Llamly OAuth'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above.
		exit;
	}

	private static function noStore(\WP_REST_Response $response): \WP_REST_Response
	{
		$response->header('Cache-Control', 'no-store');
		$response->header('Pragma', 'no-cache');
		return $response;
	}

	private static function oauthError(string $error, string $description, int $status): \WP_REST_Response
	{
		$response = new \WP_REST_Response(['error' => sanitize_key($error), 'error_description' => $description], $status);
		$response->header('Cache-Control', 'no-store');
		$response->header('Pragma', 'no-cache');
		return $response;
	}

	private static function requireServerReady(): bool|\WP_Error
	{
		$settings = Settings::get();
		if (empty($settings['_valid']) || empty($settings['enabled'])) {
			return new \WP_Error('server_error', __('Llamly is not ready to authorize new clients.', 'llamly'));
		}
		return true;
	}

	private static function beginTransaction(): bool
	{
		global $wpdb;
		return $wpdb->query('START TRANSACTION') !== false && $wpdb->last_error === '';
	}

	private static function commitTransaction(): bool
	{
		global $wpdb;
		return $wpdb->query('COMMIT') !== false && $wpdb->last_error === '';
	}

}
