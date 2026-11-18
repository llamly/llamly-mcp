<?php

declare(strict_types=1);

namespace Llamly\Free\History;

final class EditorGuard
{
	private static ?int $openedPostId = null;
	private static ?string $openedToken = null;
	/** @var array<string, array{type:string,id:int}> */
	private static array $heldPostLocks = [];
	/** Post types whose version row lives under object type `template` (the template adapter), keyed by REST base. */
	private const TEMPLATE_TYPES = ['wp_template', 'wp_template_part'];

	public static function registerHooks(): void
	{
		add_action('load-post.php', [self::class, 'captureEditorToken'], 0);
		add_action('edit_form_top', [self::class, 'renderClassicToken'], 10, 1);
		add_action('block_editor_meta_box_hidden_fields', [self::class, 'renderMetaBoxToken'], 10, 1);
		add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorGuard']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueClassicGuard']);
		add_filter('rest_request_before_callbacks', [self::class, 'beforeRestSave'], 10, 3);
		add_filter('rest_request_after_callbacks', [self::class, 'afterRestSave'], 10, 3);
		add_filter('rest_post_dispatch', [self::class, 'addEditToken'], 20, 3);
		add_action('admin_action_editpost', [self::class, 'guardClassicSave'], 0);
		add_filter('heartbeat_received', [self::class, 'guardHeartbeat'], 1, 2);
		add_filter('heartbeat_received', [self::class, 'releaseHeartbeat'], 501, 2);
		add_action('shutdown', [self::class, 'releaseAll']);
	}

	public static function captureEditorToken(): void
	{
		$postId = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post ID of the editor screen being loaded (read only, capability checked below).
		if ($postId < 1 || !current_user_can('edit_post', $postId)) {
			return;
		}
		$postType = get_post_type($postId);
		if (!self::guarded((string) $postType)) {
			return;
		}
		self::$openedPostId = $postId;
		self::$openedToken = ObjectVersionStore::editorToken($postId);
	}

	public static function renderClassicToken(\WP_Post $post): void
	{
		if (!self::guarded((string) $post->post_type) || !current_user_can('edit_post', $post->ID)) {
			return;
		}
		$token = self::tokenFor($post->ID);
		echo '<input type="hidden" name="_llamly_edit_token" value="' . esc_attr($token) . '">';
	}

	/** `block_editor_meta_box_hidden_fields` is an action that receives the post being edited. */
	public static function renderMetaBoxToken($post): void
	{
		$postId = $post instanceof \WP_Post ? (int) $post->ID : (int) (self::$openedPostId ?? 0);
		if ($postId < 1 || !current_user_can('edit_post', $postId)) {
			return;
		}
		echo '<input type="hidden" name="_llamly_edit_token" value="' . esc_attr(self::tokenFor($postId)) . '">';
	}

	public static function enqueueEditorGuard(): void
	{
		if (!is_user_logged_in()) {
			return;
		}
		$postId = self::$openedPostId ?? (isset($_GET['post']) ? absint($_GET['post']) : 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post ID of the editor screen being loaded (read only, capability checked below).
		if ($postId > 0 && (!current_user_can('edit_post', $postId) || !self::guarded((string) get_post_type($postId)))) {
			$postId = 0;
		}
		$initial = [];
		if ($postId > 0) {
			$key = self::restKeyForPost($postId);
			if ($key !== '') {
				$initial[$key] = self::tokenFor($postId);
			}
		} else {
			$siteEditor = self::siteEditorEntity();
			if ($siteEditor !== null) {
				$initial[$siteEditor['key']] = $siteEditor['token'];
			}
		}
		$handle = 'llamly-free-editor-guard';
		wp_enqueue_script($handle, LLAMLY_FREE_ASSET_URL . 'js/editor-guard.js', ['wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n'], LLAMLY_FREE_VERSION, true);
		wp_set_script_translations($handle, 'llamly');
		wp_localize_script($handle, 'LLAMLY_FREE_EDITOR', [
			'postId' => $postId,
			'token' => $postId > 0 ? self::tokenFor($postId) : '',
			'isBlockEditor' => true,
			'routes' => self::guardedRestRoutes(),
			'initial' => (object) $initial,
		]);
	}

	/**
	 * REST namespace/base of every guarded type, as `{prefix, type}`; the editor script injects the token into writes
	 * that target `<prefix>/<id>` (and `/autosaves`), including sub-requests of /batch/v1.
	 *
	 * @return list<array{prefix:string,type:string}>
	 */
	public static function guardedRestRoutes(): array
	{
		$routes = [];
		foreach (array_merge(ContentGuard::allowedPostTypes(), self::TEMPLATE_TYPES) as $type) {
			$prefix = self::restPrefix((string) $type);
			if ($prefix !== '') {
				$routes[] = ['prefix' => $prefix, 'type' => (string) $type];
			}
		}
		return $routes;
	}

	private static function restPrefix(string $type): string
	{
		$object = get_post_type_object($type);
		if (!$object instanceof \WP_Post_Type || empty($object->show_in_rest)) {
			return '';
		}
		$base = !empty($object->rest_base) ? (string) $object->rest_base : $type;
		$namespace = !empty($object->rest_namespace) ? (string) $object->rest_namespace : 'wp/v2';
		return trim($namespace, '/') . '/' . trim($base, '/');
	}

	private static function restKeyForPost(int $postId): string
	{
		$prefix = self::restPrefix((string) get_post_type($postId));
		return $prefix === '' ? '' : $prefix . '/' . $postId;
	}

	/**
	 * The entity named by the site editor URL (`postType` + `postId`), so its token is the one in force when the page
	 * loaded even when the editor serves the record from a preload.
	 *
	 * @return array{key:string,token:string}|null
	 */
	private static function siteEditorEntity(): ?array
	{
		global $pagenow;
		if (($pagenow ?? '') !== 'site-editor.php') {
			return null;
		}
		// Older editors name the entity with `postType` + `postId`; newer ones with `p=/<postType>/<id>`.
		$type = '';
		$id = '';
		if (isset($_GET['postType'], $_GET['postId'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only; the entity is capability checked below.
			$type = sanitize_key(wp_unslash((string) $_GET['postType'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = sanitize_text_field(wp_unslash((string) $_GET['postId'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif (isset($_GET['p']) && preg_match('#^/([a-z0-9_-]+)/(.+)$#', sanitize_text_field(wp_unslash((string) $_GET['p'])), $match)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = $match[1];
			$id = $match[2];
		}
		if ($type === '' || $id === '') {
			return null;
		}
		if (in_array($type, self::TEMPLATE_TYPES, true)) {
			$wpId = self::templateWpId($id, $type);
			$prefix = self::restPrefix($type);
			if ($wpId > 0 && $prefix !== '' && current_user_can('edit_post', $wpId)) {
				return ['key' => $prefix . '/' . $id, 'token' => self::versionToken($wpId, 'template')];
			}
			return null;
		}
		$postId = absint($id);
		if ($postId > 0 && current_user_can('edit_post', $postId) && self::guarded((string) get_post_type($postId))) {
			return ['key' => self::restKeyForPost($postId), 'token' => self::tokenFor($postId)];
		}
		return null;
	}

	public static function enqueueClassicGuard(string $hook): void
	{
		if (!in_array($hook, ['post.php', 'post-new.php'], true) || (function_exists('use_block_editor_for_post') && self::$openedPostId && use_block_editor_for_post(self::$openedPostId))) {
			return;
		}
		$postId = self::$openedPostId ?? (isset($_GET['post']) ? absint($_GET['post']) : 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post ID of the editor screen being loaded (read only, capability checked below).
		if ($postId < 1 || !current_user_can('edit_post', $postId) || !self::guarded((string) get_post_type($postId))) {
			return;
		}
		$handle = 'llamly-free-classic-editor-guard';
		wp_enqueue_script($handle, LLAMLY_FREE_ASSET_URL . 'js/editor-guard.js', ['wp-i18n'], LLAMLY_FREE_VERSION, true);
		wp_set_script_translations($handle, 'llamly');
		wp_localize_script($handle, 'LLAMLY_FREE_EDITOR', [
			'postId' => $postId,
			'token' => self::tokenFor($postId),
			'isBlockEditor' => false,
		]);
	}

	/** @param mixed $response
	 *  @param array<string, mixed> $handler
	 */
	public static function beforeRestSave($response, array $handler, \WP_REST_Request $request): mixed
	{
		if (is_wp_error($response) || !self::isRestPostWrite($request) || !is_user_logged_in()) {
			return $response;
		}
		// Every authenticated client is guarded (cookie sessions, /batch/v1 sub-requests, Application Passwords): a save
		// of an object Llamly changed needs the token the client read with context=edit, or it is refused with 409.
		$target = self::restTarget($request);
		if ($target === null || !current_user_can('edit_post', $target['id'])) {
			return $response;
		}
		global $wp_rest_auth_cookie;
		$cookieSession = $wp_rest_auth_cookie === true || wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest');
		$error = self::acquireAndValidate($target['id'], (string) $request->get_header('X-Llamly-Edit-Token'), $target['type'], !$cookieSession);
		return is_wp_error($error) ? $error : $response;
	}

	/** @param mixed $response
	 *  @param array<string, mixed> $handler
	 */
	public static function afterRestSave($response, array $handler, \WP_REST_Request $request): mixed
	{
		if (self::isRestPostWrite($request)) {
			$target = self::restTarget($request);
			if ($target !== null) {
				self::release($target['id'], $target['type']);
			}
		}
		return $response;
	}

	/**
	 * Adds `llamly_edit_token` to a single-item GET with `context=edit` of a guarded type, for users who can edit it.
	 * The editor keeps the first token it sees; API clients resend it as `X-Llamly-Edit-Token`.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function addEditToken($result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
	{
		if (!$result instanceof \WP_REST_Response || $result->is_error() || $result->get_status() !== 200 || strtoupper((string) $request->get_method()) !== 'GET' || (string) $request->get_param('context') !== 'edit' || !is_user_logged_in()) {
			return $result;
		}
		$target = self::restTarget($request);
		if ($target === null || $target['autosave'] || !current_user_can('edit_post', $target['id'])) {
			return $result;
		}
		$data = $result->get_data();
		if (is_array($data) && !isset($data[0])) {
			$data['llamly_edit_token'] = self::versionToken($target['id'], $target['type']);
			$result->set_data($data);
		}
		return $result;
	}

	public static function guardClassicSave(): void
	{
		$postId = isset($_POST['post_ID']) ? absint($_POST['post_ID']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the update-post nonce is verified right below, before any effect.
		if ($postId < 1 || !self::guarded((string) get_post_type($postId)) || !current_user_can('edit_post', $postId)) {
			return;
		}
		// Same nonce core checks for this save; on failure core's own check_admin_referer() rejects the request.
		if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'update-post_' . $postId)) {
			return;
		}
		$token = isset($_POST['_llamly_edit_token']) ? sanitize_text_field(wp_unslash((string) $_POST['_llamly_edit_token'])) : '';
		$error = self::acquireAndValidate($postId, $token);
		if (is_wp_error($error)) {
			wp_die(
				esc_html($error->get_error_message()) . '<p><a href="' . esc_url(get_edit_post_link($postId, 'raw')) . '">' . esc_html__('Reload this editor after copying your unsaved text.', 'llamly') . '</a></p>',
				esc_html__('Llamly blocked a stale editor save', 'llamly'),
				['response' => 409, 'back_link' => true]
			);
		}
	}

	/** @param array<string, mixed> $response
	 *  @param array<string, mixed> $data
	 *  @return array<string, mixed>
	 */
	public static function guardHeartbeat(array $response, array $data): array
	{
		$autosave = $data['wp_autosave'] ?? null;
		if (!is_array($autosave) || empty($autosave['post_id'])) {
			return $response;
		}
		$postId = absint($autosave['post_id']);
		if ($postId < 1 || !self::guarded((string) get_post_type($postId)) || !current_user_can('edit_post', $postId)) {
			return $response;
		}
		$token = isset($data['llamly_edit_token']) ? sanitize_text_field((string) $data['llamly_edit_token']) : '';
		$error = self::acquireAndValidate($postId, $token);
		if (is_wp_error($error)) {
			remove_filter('heartbeat_received', 'heartbeat_autosave', 500);
			$response['wp_autosave'] = [
				'success' => false,
				'llamly_stale' => true,
				'code' => $error->get_error_code(),
			];
			self::release($postId);
		}
		return $response;
	}

	/** @param array<string, mixed> $response
	 *  @param array<string, mixed> $data
	 *  @return array<string, mixed>
	 */
	public static function releaseHeartbeat(array $response, array $data): array
	{
		$autosave = $data['wp_autosave'] ?? null;
		if (is_array($autosave) && !empty($autosave['post_id'])) {
			self::release(absint($autosave['post_id']));
		}
		return $response;
	}

	public static function releaseAll(): void
	{
		foreach (self::$heldPostLocks as $held) {
			self::release($held['id'], $held['type']);
		}
	}

	private static function acquireAndValidate(int $postId, string $token, string $type = 'post', bool $apiClient = false): bool|\WP_Error
	{
		if (!ObjectMutex::acquire($type, $postId, 10)) {
			return self::editorError('llamly_operation_in_progress', __('Another Llamly or editor save is in progress. Try again shortly.', 'llamly'));
		}
		self::$heldPostLocks[$type . ':' . $postId] = ['type' => $type, 'id' => $postId];
		global $wpdb;
		$version = ObjectVersionStore::get($type, $postId);
		if ($version === null) {
			// No row means Llamly never changed this post; a failed lookup must not release a stale editor.
			if ($wpdb->last_error !== '') {
				return self::editorError('llamly_version_unavailable', __('Llamly could not confirm the post version. Copy your unsaved text and try again.', 'llamly'));
			}
			return true;
		}
		if ($version['version'] < 1) {
			// Row created by an aborted or not yet committed operation: Llamly never changed the object.
			return true;
		}
		if ($token === '') {
			return self::editorError('llamly_edit_token_required', $apiClient
				? __('Read the item with context=edit and resend its llamly_edit_token as the X-Llamly-Edit-Token header.', 'llamly')
				: __('This post changed through Llamly. Reload the editor before saving.', 'llamly'));
		}
		if (!preg_match('/^' . preg_quote((string) $postId, '/') . '\\.(0|[1-9][0-9]*)$/', $token, $match)) {
			return self::editorError('llamly_stale_editor', __('This editor token is invalid. Copy your unsaved text, then reload the editor.', 'llamly'));
		}
		if ((int) $match[1] !== $version['version']) {
			return self::editorError('llamly_stale_editor', __('This post changed while the editor was open. Copy your unsaved text, then reload before saving.', 'llamly'));
		}
		return true;
	}

	private static function isRestPostWrite(\WP_REST_Request $request): bool
	{
		// get_method() already reflects the override the core applied (`_method`, X-HTTP-Method-Override, batch).
		$method = strtoupper((string) $request->get_method());
		return in_array($method, ['POST', 'PUT', 'PATCH'], true) && self::restTarget($request) !== null;
	}

	/**
	 * The object a REST request targets, for every guarded post type: the route is matched against each type's own REST
	 * namespace and base (CPTs and wp_navigation use their own), then the post's type is confirmed. Block templates and
	 * template parts have a textual ID (`theme//slug`); it is resolved to the override post with the same
	 * `get_block_template()` call the controller makes, and the version row is the one of the template adapter. A
	 * template that is only a theme file has no override and no Llamly version, so it has no target.
	 *
	 * @return array{type:string,id:int,autosave:bool}|null
	 */
	private static function restTarget(\WP_REST_Request $request): ?array
	{
		$route = (string) $request->get_route();
		// `<namespace>/<rest_base>/<id>[/autosaves]`, any namespace shape; the prefix must be the one the post's type registered.
		if (preg_match('#^/(.+?)/([0-9]+)(/autosaves)?$#i', $route, $match)) {
			$postId = (int) $match[2];
			$type = (string) get_post_type($postId);
			if ($type === '' || !self::guarded($type)) {
				return null;
			}
			return strtolower(self::restPrefix($type)) === strtolower($match[1]) ? ['type' => 'post', 'id' => $postId, 'autosave' => ($match[3] ?? '') !== ''] : null;
		}
		if (!preg_match('#^/(wp/v2/(?:templates|template-parts))/(.+)$#i', $route, $match)) {
			return null;
		}
		$id = $match[2];
		$autosave = false;
		if (preg_match('#^(.+[^/])/autosaves$#i', $id, $stripped)) {
			$id = $stripped[1];
			$autosave = true;
		}
		foreach (self::TEMPLATE_TYPES as $templateType) {
			if (strtolower(self::restPrefix($templateType)) !== strtolower($match[1])) {
				continue;
			}
			$wpId = self::templateWpId($id, $templateType);
			return $wpId > 0 ? ['type' => 'template', 'id' => $wpId, 'autosave' => $autosave] : null;
		}
		return null;
	}

	private static function templateWpId(string $restId, string $type): int
	{
		if (strpos($restId, '//') === false) {
			return 0;
		}
		$template = get_block_template($restId, $type);
		return $template && !empty($template->wp_id) ? (int) $template->wp_id : 0;
	}

	private static function versionToken(int $objectId, string $type): string
	{
		$version = ObjectVersionStore::get($type, $objectId);
		return $objectId . '.' . ($version['version'] ?? 0);
	}

	private static function tokenFor(int $postId): string
	{
		if (self::$openedPostId === $postId && self::$openedToken !== null) {
			return self::$openedToken;
		}
		return ObjectVersionStore::editorToken($postId);
	}

	private static function release(int $postId, string $type = 'post'): void
	{
		$key = $type . ':' . $postId;
		if (!isset(self::$heldPostLocks[$key])) {
			return;
		}
		ObjectMutex::release($type, $postId);
		unset(self::$heldPostLocks[$key]);
	}

	private static function editorError(string $code, string $message): \WP_Error
	{
		return new \WP_Error($code, $message, ['status' => 409]);
	}

	/** Every post type Llamly can write through the post adapter gets the stale-editor guard. */
	private static function guarded(string $postType): bool
	{
		return ContentGuard::isAllowedType($postType);
	}
}
