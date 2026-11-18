<?php

declare(strict_types=1);

namespace Llamly\Free\Mcp;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Auth\CapabilityCeiling;
use Llamly\Free\Auth\OriginGuard;
use Llamly\Free\Core\Settings;
use Llamly\Free\Core\ToolRegistry;

final class Protocol
{
	public const MAX_BODY_BYTES = 1048576;
	private const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

	/**
	 * Everything the MCP client reads (errors, instructions, tool texts) is English, even on a site or for a user set to
	 * another language. The wp-admin screens are not served here and keep the user's locale.
	 * The caller was already authenticated by the route's permission_callback (Endpoint::permission()).
	 */
	public static function handle(\WP_REST_Request $request): \WP_REST_Response
	{
		return self::inEnglish(static fn(): \WP_REST_Response => self::handleRequest($request));
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function inEnglish(callable $callback): mixed
	{
		$switched = function_exists('switch_to_locale') && determine_locale() !== 'en_US' && switch_to_locale('en_US');
		try {
			return $callback();
		} finally {
			if ($switched) {
				restore_previous_locale();
			}
		}
	}

	private static function handleRequest(\WP_REST_Request $request): \WP_REST_Response
	{
		$method = strtoupper($request->get_method());
		$origin = OriginGuard::check($request);
		if (is_wp_error($origin)) {
			return self::errorResponse($origin);
		}
		if ($method === 'OPTIONS') {
			return self::response(null, 204, ['Allow' => 'POST, DELETE, OPTIONS']);
		}

		// Defense in depth: the permission_callback already ran this check for this request; Endpoint::authorize()
		// returns its stored result, so the credential is not checked (or counted as a failure) twice.
		$auth = Endpoint::authorize($request);
		if (is_wp_error($auth)) {
			return self::errorResponse($auth);
		}
		if (!is_array($auth)) {
			return self::errorResponse(new \WP_Error('unauthorized', __('A valid Bearer token is required.', 'llamly'), ['status' => 401]));
		}
		$protocolVersion = trim((string) $request->get_header('mcp-protocol-version'));
		if ($protocolVersion !== '' && !in_array($protocolVersion, self::SUPPORTED_VERSIONS, true)) {
			return self::errorResponse(new \WP_Error('unsupported_protocol_version', __('The requested MCP protocol version is not supported.', 'llamly'), ['status' => 400]));
		}
		$protocolHeaders = $protocolVersion !== '' ? ['Mcp-Protocol-Version' => $protocolVersion] : [];

		if ($method === 'DELETE') {
			$sessionId = trim((string) $request->get_header('mcp-session-id'));
			return self::response($sessionId === '' ? null : ['error' => 'session_not_found'], $sessionId === '' ? 204 : 404, ['Allow' => 'POST, DELETE, OPTIONS'] + $protocolHeaders);
		}
		if ($method !== 'POST') {
			return self::response(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST, DELETE, OPTIONS'] + $protocolHeaders);
		}
		$accept = strtolower((string) $request->get_header('accept'));
		if ($accept !== '' && strpos($accept, '*/*') === false && strpos($accept, 'application/json') === false && strpos($accept, 'text/event-stream') === false) {
			return self::response(['error' => 'not_acceptable'], 406, $protocolHeaders);
		}
		$contentType = strtolower(trim(explode(';', (string) $request->get_header('content-type'))[0]));
		if ($contentType !== 'application/json') {
			return self::response(['error' => 'unsupported_media_type'], 415, $protocolHeaders);
		}
		$body = (string) $request->get_body();
		if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
			return self::response(['error' => $body === '' ? 'invalid_json' : 'request_too_large'], $body === '' ? 400 : 413, $protocolHeaders);
		}
		try {
			$message = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			return self::response(['error' => 'invalid_json'], 400, $protocolHeaders);
		}
		if (!$message instanceof \stdClass || ($message->jsonrpc ?? null) !== '2.0' || !is_string($message->method ?? null)) {
			return self::response(['error' => 'invalid_jsonrpc'], 400, $protocolHeaders);
		}
		$idPresent = property_exists($message, 'id');
		$id = $idPresent ? $message->id : null;
		if ($idPresent && !is_string($id) && !is_int($id) && !is_float($id) && $id !== null) {
			return self::response(['error' => 'invalid_request_id'], 400, $protocolHeaders);
		}
		$paramsValue = $message->params ?? new \stdClass();
		if (!$paramsValue instanceof \stdClass) {
			return self::withHeaders(self::rpcError($id, -32600, 'Invalid request parameters.', 400), $protocolHeaders);
		}
		$params = get_object_vars($paramsValue);

		if (!$idPresent && strpos($message->method, 'notifications/') === 0) {
			return self::response(null, 202, $protocolHeaders);
		}
		if (!$idPresent) {
			return self::withHeaders(self::rpcError(null, -32600, 'Requests must include an id; notifications are not accepted for tool calls.', 400), $protocolHeaders);
		}

		// The request runs as the credential's user under the capability ceiling: content capabilities only, never the
		// administrative ones, whatever the user's role (CapabilityCeiling restores the previous user afterwards).
		try {
			$result = CapabilityCeiling::run($auth, fn(): \WP_REST_Response => self::dispatch($message->method, $params, $auth, $id));
		} catch (\Throwable $error) {
			$result = self::rpcError($id, -32603, 'The server could not complete the request.', 500);
		}
		return self::withHeaders($result, $protocolHeaders);
	}

	public static function serveSse($served, $result, $server, $request): bool
	{
		if (!$request instanceof \WP_REST_Request || $request->get_route() !== '/llamly/v1/endpoint'
			|| stripos((string) $request->get_header('accept'), 'text/event-stream') === false
			|| !$result instanceof \WP_REST_Response
		) {
			return (bool) $served;
		}
		if ($result->get_status() !== 200) {
			return (bool) $served;
		}
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache, no-transform');
		header('X-Accel-Buffering: no');
		$data = $result->get_data();
		if ($data !== null) {
			echo "event: message\ndata: " . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
		}
		return true;
	}

	private static function dispatch(string $method, array $params, array $auth, mixed $id): \WP_REST_Response
	{
		switch ($method) {
			case 'initialize':
				// MCP version negotiation: answer with the requested version when supported,
				// otherwise with the newest one this server supports; the client decides whether to continue.
				$version = (string) ($params['protocolVersion'] ?? '');
				return self::rpcResult($id, [
					'protocolVersion' => in_array($version, self::SUPPORTED_VERSIONS, true) ? $version : self::SUPPORTED_VERSIONS[0],
					'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['subscribe' => false, 'listChanged' => false], 'prompts' => ['listChanged' => false]],
					'serverInfo' => ['name' => 'Llamly', 'version' => LLAMLY_FREE_VERSION],
					'instructions' => self::instructions($auth),
				]);
			case 'ping':
				return self::rpcResult($id, new \stdClass());
			case 'tools/list':
				return self::rpcResult($id, ['tools' => Dispatcher::gateways()]);
			case 'tools/call':
				return self::callGateway($id, $params, $auth);
			case 'resources/list':
				return self::rpcResult($id, ['resources' => self::resources()]);
			case 'resources/read':
				return self::readResource($id, $params);
			case 'prompts/list':
				return self::rpcResult($id, ['prompts' => self::prompts($auth)]);
			case 'prompts/get':
				return self::getPrompt($id, $params, $auth);
			default:
				return self::rpcError($id, -32601, 'Method not found.', 404);
		}
	}

	/** Budget of the instructions text in bytes (PLANO_LLP-406, "≤ 3 KB"); LLP-476 D4 keeps the settings links inside it. */
	public const INSTRUCTIONS_BUDGET = 3072;
	/** Bytes of the site name in the first line (LLP-476 D3): longer names end in "…" on a character boundary. */
	public const SITE_NAME_BYTES = 48;

	/** Usage guide for the connected agent, built from the live state (site, scope, writes, safe mode, queue). Kept under 3 KB. */
	public static function instructions(array $auth): string
	{
		$text = self::buildInstructions($auth, false);
		// LLP-476 D4: only if the text is still over the budget (home_url over ~106 characters in the worst case), the two
		// settings links share the admin URL.
		return strlen($text) > self::INSTRUCTIONS_BUDGET ? self::buildInstructions($auth, true) : $text;
	}

	/** Site name cut at SITE_NAME_BYTES bytes, never inside a character, with "…" when cut (LLP-476 D3). */
	public static function siteNameForInstructions(string $name, bool $useMbstring = true): string
	{
		if (strlen($name) <= self::SITE_NAME_BYTES) {
			return $name;
		}
		$keep = self::SITE_NAME_BYTES - strlen('…');
		if ($useMbstring && function_exists('mb_strcut')) {
			$cut = mb_strcut($name, 0, $keep, 'UTF-8');
		} else {
			$cut = substr($name, 0, $keep);
			while ($cut !== '' && !preg_match('//u', $cut)) {
				$cut = substr($cut, 0, -1);
			}
		}
		return rtrim($cut) . '…';
	}

	/** @param array<string, mixed> $auth */
	private static function buildInstructions(array $auth, bool $shortLinks): string
	{
		$settings = Settings::get();
		$safe = !empty($settings['safe_mode']);
		$queue = !empty($settings['require_approval']);
		$scopeMode = (string) ($auth['scope_mode'] ?? 'full');
		$writes = \Llamly\Free\History\MutationRunner::writeEnabled() && $scopeMode !== 'read_only';
		$scope = $scopeMode === 'read_only' ? 'read-only' : ($scopeMode === 'custom' ? 'custom' : 'full');
		$name = self::siteNameForInstructions(trim(wp_strip_all_tags((string) get_bloginfo('name'))));
		$categories = implode(', ', Dispatcher::categories());

		$text = 'You are connected to the WordPress site "' . $name . '" (' . home_url() . ') as user #' . (int) ($auth['user_id'] ?? 0) . ".\n"
			. 'Connection scope: ' . $scope . '. Writes: ' . ($writes ? ($scopeMode === 'custom' ? 'enabled (only for the tools in scope)' : 'enabled') : 'disabled') . '. Safe mode: ' . ($safe ? 'ON' : 'OFF') . '. Human approval queue: ' . ($queue ? 'ON' : 'OFF') . ".\n\n"
			. "Flow: llamly_discover (query or category, paged) finds a tool and its inputSchema; llamly_run runs it with {tool, arguments}. Categories: " . $categories . ".\n\n"
			. "Reading: list/get tools return revision_hash for items you can change (users are read-only). To change an existing item, send it as expected_hash; on stale_revision, read the item again and redo the edit.\n"
			. "Every write needs a unique idempotency_key (16-128 chars [A-Za-z0-9._:-], e.g. a UUID, never a date or counter). Reuse a key only to retry that exact change; if a result has replayed:true and you meant a new change, use a new key. dry_run: true returns the plan, risk and current hash without changing anything.\n\n";
		if (!$writes) {
			$text .= "This connection cannot change the site, so only read tools are available.\n\n";
		} else {
			$text .= 'Approval: ' . ($safe
				? "with safe mode ON, destructive and irreversible actions, every undo, and a redo that is destructive or irreversible return confirmation_required with requires: \"confirm\" and a preview; nothing changed. Ask the user; only if they agree, repeat the call with confirm: true and the SAME idempotency_key. "
				: "safe mode is OFF, so changes run without a confirmation step. ")
				. ($queue
					? "The human queue is ON: some changes return requires: \"human\" and an approval_id; tell the user an administrator must approve it in Llamly > Activity, then repeat the call with the same key. "
					: "The human queue is OFF. ")
				. "approval_expired (after 7 days): send it again with a new key. policy_changed: retry the same call.\n\n"
				. "Undo/redo: every change is journaled; wp_list_changes lists them, wp_undo_change and wp_redo_change take a change id. already_undone means it was already undone, not a failure. Delete moves posts to the trash, never deletes them permanently.\n\n";
		}
		// Site settings left the Free (LLP-449): say so and give the wp-admin links, unless a module tool can change them.
		$siteSettings = \Llamly\Free\Tools\SiteSettings::instructions($auth, $shortLinks);
		if ($siteSettings !== '') {
			$text .= $siteSettings . "\n\n";
		}
		// LLP-476: what Llamly Pro adds, only while it is not active (no line for modules turned off: decision 9605, D5a).
		$pro = \Llamly\Free\Pro\ProFeatures::instructions();
		if ($pro !== '') {
			$text .= $pro . "\n\n";
		}
		$guides = self::moduleGuides($auth, $writes);
		if (!$guides) {
			$text .= "Elementor tools are read-only. Use block (gutenberg) tools or post content for edits.";
			return $text;
		}
		// Active add-ons this connection can use: their own guide, so an agent that was never told about them still finds them.
		$text .= "Add-ons active on this site (prefer their tools when the request fits them):\n";
		$writers = [];
		foreach ($guides as $guide) {
			$text .= '- ' . $guide['label'] . ' (category ' . $guide['id'] . ')' . ($guide['instructions'] !== '' ? ': ' . $guide['instructions'] : '.') . "\n";
			if ($guide['writes_elementor']) {
				$writers[] = $guide['label'] . ' (category ' . $guide['id'] . ')';
			}
		}
		$text .= "\n";
		$text .= $writers
			? 'Elementor: the core elementor tools are read-only; to create or change Elementor pages use the tools of ' . implode(', ', $writers) . '.'
			: "Elementor tools are read-only. Use block (gutenberg) tools or post content for edits.";
		return $text;
	}

	/**
	 * Active add-on modules with at least one tool this connection can use, with their guide for agents. Inactive or
	 * refused modules never appear (they are not in ModuleRegistry's active list).
	 *
	 * @return list<array{id:string,label:string,instructions:string,writes_elementor:bool}>
	 */
	private static function moduleGuides(array $auth, bool $writes): array
	{
		if (!\Llamly\Free\Modules\ModuleRegistry::ready()) {
			return [];
		}
		$visible = ToolRegistry::visibleTo($auth);
		$out = [];
		foreach (\Llamly\Free\Modules\ModuleRegistry::agentGuides() as $guide) {
			$usable = array_values(array_filter($guide['tools'], static fn(string $tool): bool => isset($visible[$tool])));
			if (!$usable) {
				continue;
			}
			$canWrite = $writes && (bool) array_filter($usable, static fn(string $tool): bool => ($visible[$tool]['access'] ?? '') === 'write');
			$out[] = [
				'id' => $guide['id'],
				'label' => $guide['label'],
				'instructions' => $guide['instructions'],
				'writes_elementor' => $guide['elementor'] && $canWrite,
			];
		}
		return $out;
	}

	private static function callGateway(mixed $id, array $params, array $auth): \WP_REST_Response
	{
		$name = (string) ($params['name'] ?? '');
		$argsValue = $params['arguments'] ?? new \stdClass();
		$gateway = null;
		foreach (Dispatcher::gateways() as $candidate) {
			if ($candidate['name'] === $name) {
				$gateway = $candidate;
				break;
			}
		}
		if (!$gateway) {
			return self::rpcError($id, -32602, 'Unknown gateway tool.', 200);
		}
		$validation = SchemaValidator::validate($argsValue, $gateway['inputSchema']);
		if (is_wp_error($validation)) {
			return self::toolError($id, $validation);
		}
		$args = $argsValue instanceof \stdClass ? get_object_vars($argsValue) : [];
		$started = hrtime(true);
		$result = $name === 'llamly_discover'
			? Dispatcher::discover($args, $auth)
			: Dispatcher::run($args, $auth);
		self::logCall($name === 'llamly_discover' ? $name : (string) ($args['tool'] ?? ''), $args, $auth, $result, $started);
		if (is_wp_error($result)) {
			// LLP-476: a refusal Llamly Pro covers names it (only while Llamly Pro is not active); a batch names its own call.
			$data = $result->get_error_data();
			$tool = is_array($data) && isset($data['tool']) ? (string) $data['tool'] : (string) ($args['tool'] ?? '');
			return self::toolError($id, \Llamly\Free\Pro\ProFeatures::decorate($result, $tool));
		}
		$encoded = wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return self::rpcResult($id, [
			'content' => [['type' => 'text', 'text' => is_string($encoded) ? $encoded : '{}']],
			'structuredContent' => $result,
			'isError' => false,
		]);
	}

	/**
	 * Per-request log row for the Logs tab, including refusals. Mutations keep their own transactional audit
	 * (MutationRunner); this row is the request trail, so a failure to write it does not fail a read.
	 */
	private static function logCall(string $tool, array $args, array $auth, mixed $result, int $started): void
	{
		$code = is_wp_error($result) ? (string) $result->get_error_code() : '';
		$denied = ['forbidden', 'confirmation_required', 'write_not_ready', 'tool_not_found', 'invalid_scope'];
		$outcome = $code === '' ? 'ok' : ($code === 'rate_limited' ? 'rate' : (in_array($code, $denied, true) ? 'denied' : 'error'));
		$context = $code === '' ? [] : ['code' => $code];
		if (!empty(Settings::get()['debug_logging'])) {
			$inner = $args['arguments'] ?? null;
			$names = is_object($inner) ? array_keys(get_object_vars($inner)) : (is_array($inner) ? array_keys($inner) : []);
			$context['argument_names'] = array_map(static fn($name): string => substr((string) $name, 0, 64), array_slice($names, 0, 50));
		}
		$request = [
			'result' => $outcome,
			'tool' => $tool,
			'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
			'ip' => sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? ''))),
		];
		$userId = (int) ($auth['user_id'] ?? 0);
		$credentialId = isset($auth['credential_id']) ? (int) $auth['credential_id'] : null;
		$credentialKey = isset($auth['credential_key']) ? (string) $auth['credential_key'] : null;
		// The request row must exist even if its optional context cannot be stored.
		if (!\Llamly\Free\Core\AuditLog::append('tool_call', $context, $userId, $credentialId, $credentialKey, $request)) {
			\Llamly\Free\Core\AuditLog::append('tool_call', ['context_dropped' => true], $userId, $credentialId, $credentialKey, $request);
		}
	}

	private static function toolError(mixed $id, \WP_Error $error): \WP_REST_Response
	{
		$code = $error->get_error_code();
		$message = $error->get_error_message();
		$structured = ['error' => $code];
		// The agent needs these to follow up: approval_id to quote to the administrator, and the risk that triggered it.
		$data = $error->get_error_data($code);
		if (is_array($data)) {
			foreach (['approval_id', 'risk', 'index', 'suggested_slug', 'requires_confirmation', 'requires', 'preview', 'pro'] as $key) {
				if (array_key_exists($key, $data)) {
					$structured[$key] = $data[$key];
				}
			}
		}
		$text = isset($structured['approval_id']) ? $message . ' approval_id: ' . $structured['approval_id'] : $message;
		$result = ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $structured, 'isError' => true];
		return self::rpcResult($id, $result);
	}

	private static function readResource(mixed $id, array $params): \WP_REST_Response
	{
		$uri = (string) ($params['uri'] ?? '');
		$resources = [
			'llamly://guide/connection' => __('Connect with an OAuth connector or a scoped Bearer key. Keys are shown only once. Verify client calls in Activity and revoke a key to deny the next request.', 'llamly'),
			'llamly://guide/safety' => __('Use read-only access unless writing is needed. Every write must have a durable journal entry, pass the WordPress user capability check, and include the current post revision hash. If storage or concurrency checks fail, Llamly stops before reporting success.', 'llamly'),
		];
		if (!isset($resources[$uri])) {
			return self::rpcError($id, -32602, 'Resource not found.', 404);
		}
		return self::rpcResult($id, ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => $resources[$uri]]]]);
	}

	private static function prompts(array $auth): array
	{
		$visible = ToolRegistry::visibleTo($auth);
		if (!isset($visible['wp_list_posts'])) {
			return [];
		}
		return [[
			'name' => 'llamly_review_drafts',
			'description' => __('Review available draft posts without changing them.', 'llamly'),
			'arguments' => [],
		]];
	}

	private static function getPrompt(mixed $id, array $params, array $auth): \WP_REST_Response
	{
		if ((string) ($params['name'] ?? '') !== 'llamly_review_drafts' || !in_array('llamly_review_drafts', array_column(self::prompts($auth), 'name'), true)) {
			return self::rpcError($id, -32602, 'Prompt not found.', 404);
		}
		return self::rpcResult($id, ['description' => __('Review posts that are accessible to this connection.', 'llamly'), 'messages' => [[
			'role' => 'user',
			'content' => ['type' => 'text', 'text' => 'Use llamly_discover to find wp_list_posts, then call llamly_run with status "draft". Do not change any content.'],
		]]]);
	}

	private static function resources(): array
	{
		return [
			['uri' => 'llamly://guide/connection', 'name' => __('Connection and access', 'llamly'), 'mimeType' => 'text/plain'],
			['uri' => 'llamly://guide/safety', 'name' => __('Writing safety', 'llamly'), 'mimeType' => 'text/plain'],
		];
	}

	private static function rpcResult(mixed $id, mixed $result): \WP_REST_Response
	{
		return self::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
	}

	private static function rpcError(mixed $id, int $code, string $message, int $status): \WP_REST_Response
	{
		return self::response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
	}

	public static function errorResponse(\WP_Error $error): \WP_REST_Response
	{
		$code = $error->get_error_code();
		$data = $error->get_error_data($code);
		$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
		$response = self::response(['error' => $code, 'message' => $error->get_error_message()], $status);
		if ($status === 401) {
			$metadata = home_url('/.well-known/oauth-protected-resource');
			$response->header('WWW-Authenticate', 'Bearer resource_metadata="' . esc_url_raw($metadata) . '"');
		}
		return $response;
	}

	private static function response($data, int $status, array $headers = []): \WP_REST_Response
	{
		$response = new \WP_REST_Response($data, $status);
		$response->header('Cache-Control', 'no-store');
		foreach ($headers as $name => $value) {
			$response->header((string) $name, (string) $value);
		}
		return $response;
	}

	private static function withHeaders(\WP_REST_Response $response, array $headers): \WP_REST_Response
	{
		foreach ($headers as $name => $value) {
			$response->header((string) $name, (string) $value);
		}
		return $response;
	}
}
