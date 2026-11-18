<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Admin;

use Llamly\Free\Auth\ConnectFunnel;
use Llamly\Free\Auth\CredentialStore;
use Llamly\Free\Auth\OAuthRedirectPolicy;
use Llamly\Free\Core\Abilities;
use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;
use Llamly\Free\Core\ToolRegistry;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationRunner;
use Llamly\Free\History\PolicyMigration;

final class AdminController
{
	public static function registerHooks(): void
	{
		add_action('rest_api_init', [self::class, 'registerRoutes']);
		add_action('admin_post_llamly_free_export_logs', [self::class, 'exportLogs']);
	}

	public static function registerRoutes(): void
	{
		$permission = [self::class, 'permissions'];
		register_rest_route('llamly/v1', '/admin/state', ['methods' => 'GET', 'callback' => [self::class, 'state'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/connections', ['methods' => 'GET', 'callback' => [self::class, 'connections'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/connections/keys', ['methods' => 'POST', 'callback' => [self::class, 'createKey'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/connections/keys/(?P<id>[0-9]+)', [
			[
				'methods' => 'PUT',
				'callback' => [self::class, 'updateKeyScope'],
				'permission_callback' => $permission,
			],
			[
				'methods' => 'DELETE',
				'callback' => [self::class, 'revokeKey'],
				'permission_callback' => $permission,
			],
		]);
		register_rest_route('llamly/v1', '/admin/connections/oauth-window', ['methods' => 'POST', 'callback' => [self::class, 'oauthWindow'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/connections/grants/(?P<id>[a-fA-F0-9-]{36})', ['methods' => 'DELETE', 'callback' => [self::class, 'revokeGrant'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/connections/clients/(?P<id>llc_[a-f0-9]{48})', ['methods' => 'DELETE', 'callback' => [self::class, 'revokeClient'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/settings', [
			[
				'methods' => 'GET',
				'callback' => [self::class, 'getSettings'],
				'permission_callback' => $permission,
			],
			[
				'methods' => 'PUT',
				'callback' => [self::class, 'saveSettings'],
				'permission_callback' => $permission,
			],
		]);
		register_rest_route('llamly/v1', '/admin/activity', ['methods' => 'GET', 'callback' => [self::class, 'activity'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/approve/(?P<action>undo|redo)', ['methods' => 'POST', 'callback' => [self::class, 'approve'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/approve-force', ['methods' => 'POST', 'callback' => [self::class, 'approveForce'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/force-undo', ['methods' => 'POST', 'callback' => [self::class, 'forceUndo'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/resolve', ['methods' => 'POST', 'callback' => [self::class, 'resolveRecovery'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/undo', ['methods' => 'POST', 'callback' => [self::class, 'undo'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/redo', ['methods' => 'POST', 'callback' => [self::class, 'redo'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/(?P<id>[a-fA-F0-9-]{36})/request/(?P<decision>approve|reject)', ['methods' => 'POST', 'callback' => [self::class, 'decideRequest'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/activity/batch/(?P<batch>[a-f0-9-]{36})/undo', ['methods' => 'POST', 'callback' => [self::class, 'undoBatch'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/logs', ['methods' => 'GET', 'callback' => [self::class, 'logs'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/logs/clear', ['methods' => 'POST', 'callback' => [self::class, 'clearLogs'], 'permission_callback' => $permission]);
		register_rest_route('llamly/v1', '/admin/doctor', ['methods' => ['GET', 'POST'], 'callback' => [self::class, 'doctor'], 'permission_callback' => $permission]);
	}

	public static function permissions(\WP_REST_Request $request): bool|\WP_Error
	{
		if (!is_user_logged_in() || !current_user_can('manage_options')) {
			return new \WP_Error('forbidden', __('Only a signed-in site administrator can use Llamly settings.', 'llamly'), ['status' => 403]);
		}
		$nonce = (string) $request->get_header('X-WP-Nonce');
		if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
			return new \WP_Error('rest_cookie_invalid_nonce', __('Reload the Llamly page to refresh its security token.', 'llamly'), ['status' => 403]);
		}
		return true;
	}

	public static function state(): \WP_REST_Response
	{
		ConnectFunnel::settle(false);
		$settings = self::publicSettings(Settings::get());
		return new \WP_REST_Response([
			'site' => [
				'name' => wp_strip_all_tags(get_bloginfo('name')),
				'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
				'user' => wp_strip_all_tags(wp_get_current_user()->display_name),
				'wordpress' => (string) get_bloginfo('version'),
				'php' => PHP_VERSION,
				'theme' => wp_strip_all_tags((string) wp_get_theme()->get('Name')),
				'is_local' => OAuthRedirectPolicy::hostLooksLocal((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
				'endpoint' => esc_url_raw(rest_url('llamly/v1/endpoint')),
				'claude_install_url' => self::claudeInstallUrl(false),
				'claude_org_install_url' => self::claudeInstallUrl(true),
				'elementor' => defined('ELEMENTOR_VERSION'),
				'links' => self::helpLinks(),
			],
			'server' => [
				'enabled' => !empty($settings['enabled']),
				'write_enabled' => MutationRunner::writeEnabled(),
				'plugin_version' => LLAMLY_FREE_VERSION,
				'abilities_available' => Abilities::available(),
				'modules' => self::moduleSummary(),
			],
			'settings' => $settings,
			'connections' => self::connectionRows(),
			'attempt' => ConnectFunnel::status(),
			'tools' => self::toolOptions(),
			'activity' => self::activityModel(['page' => 1, 'per_page' => 15]),
			'logs' => AuditLog::list(1, 20),
		], 200);
	}

	/** Add-on modules for About: no tool lists, only what the page shows. @return array<string, mixed> */
	private static function moduleSummary(): array
	{
		$report = \Llamly\Free\Modules\ModuleRegistry::report();
		$pick = static fn(array $rows, array $keys): array => array_map(static fn(array $row): array => array_intersect_key($row, array_flip($keys)), $rows);
		return [
			'api' => $report['api'],
			'active' => $pick($report['active'], ['id', 'label', 'version', 'api', 'status']),
			'refused' => $pick($report['refused'], ['id', 'label', 'version', 'api', 'reason']),
			'inactive' => $pick($report['inactive'], ['id', 'label', 'version']),
		];
	}

	public static function connections(): \WP_REST_Response
	{
		return new \WP_REST_Response([
			'items' => self::connectionRows(),
			'window' => self::windowStatus(),
			'attempt' => ConnectFunnel::status(),
			'write_enabled' => MutationRunner::writeEnabled(),
			'endpoint' => esc_url_raw(rest_url('llamly/v1/endpoint')),
			'tools' => self::toolOptions(),
		], 200);
	}

	public static function createKey(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		if (!is_array($body)) {
			return self::error('invalid_credential', __('Send a JSON key request.', 'llamly'), 400);
		}
		$name = $body['label'] ?? null;
		$scope = $body['scope_mode'] ?? null;
		$tools = $body['allowed_tools'] ?? [];
		if (!is_string($name) || !is_string($scope) || !is_array($tools)) {
			return self::error('invalid_credential', __('Provide a key label and a valid access level.', 'llamly'), 400);
		}
		if (trim($name) === 'Temporary Llamly Doctor probe') {
			return self::error('invalid_credential', __('This key name is reserved for the connection Doctor.', 'llamly'), 400);
		}
		$key = CredentialStore::createApiKey(get_current_user_id(), $name, $scope, $tools);
		if (is_wp_error($key)) {
			return $key;
		}
		return new \WP_REST_Response(['key' => $key, 'items' => CredentialStore::listForAdmin()], 201);
	}

	public static function revokeKey(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = CredentialStore::revokeForAdmin((int) $request['id']);
		return is_wp_error($result) ? $result : new \WP_REST_Response(['items' => CredentialStore::listForAdmin()], 200);
	}

	public static function updateKeyScope(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		if (!is_array($body) || !is_string($body['scope_mode'] ?? null) || !is_array($body['allowed_tools'] ?? [])) {
			return self::error('invalid_credential', __('Provide a valid access level and tool list.', 'llamly'), 400);
		}
		$result = CredentialStore::updateScopeForAdmin((int) $request['id'], $body['scope_mode'], $body['allowed_tools'] ?? []);
		return is_wp_error($result) ? $result : new \WP_REST_Response(['items' => CredentialStore::listForAdmin()], 200);
	}

	public static function oauthWindow(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		$action = is_array($body) ? (string) ($body['action'] ?? '') : '';
		$settings = Settings::get();
		if (empty($settings['_valid']) || !in_array($action, ['open', 'close'], true)) {
			return self::error('invalid_settings', __('Choose whether to open or close the connection window.', 'llamly'), 400);
		}
		$revision = isset($body['revision']) ? (int) $body['revision'] : 0;
		// An attempt whose window already ended is settled against that window, before a new one opens.
		ConnectFunnel::settle(false);
		global $wpdb;
		if ($wpdb->query('START TRANSACTION') === false) {
			return self::error('storage_unavailable', __('The OAuth registration window could not be updated.', 'llamly'), 503);
		}
		$saved = Settings::save(['oauth_window_until' => $action === 'open' ? time() + 1800 : 0], $revision);
		if (is_wp_error($saved)) {
			$wpdb->query('ROLLBACK');
			return $saved;
		}
		if (!AuditLog::append('oauth_window_' . $action, ['until' => (int) $saved['oauth_window_until']], get_current_user_id(), null, null)
			|| $wpdb->query('COMMIT') === false || $wpdb->last_error !== ''
		) {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The OAuth window and its audit event could not be saved.', 'llamly'), 503);
		}
		Settings::flushCache();
		// LLP-467: the "Add to <app>" button sends its client, so the page can follow how far the app gets.
		$client = is_array($body) ? (string) ($body['client'] ?? '') : '';
		if ($action === 'open' && $client !== '') {
			ConnectFunnel::start($client, get_current_user_id());
		} elseif ($action === 'close') {
			ConnectFunnel::settle(true);
		}
		return new \WP_REST_Response(['settings' => self::publicSettings($saved), 'window' => self::windowStatus(),
			'attempt' => ConnectFunnel::status()], 200);
	}

	public static function revokeGrant(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		global $wpdb;
		$id = sanitize_text_field((string) $request['id']);
		$tables = Schema::tableNames();
		if ($wpdb->query('START TRANSACTION') === false) {
			return self::error('storage_unavailable', __('The OAuth connection could not be revoked.', 'llamly'), 503);
		}
		$row = $wpdb->get_row($wpdb->prepare('SELECT id, client_id, user_id FROM %i WHERE id = %s AND revoked_at IS NULL FOR UPDATE', $tables['oauth_grants'], $id), ARRAY_A);
		if (!$row) {
			$wpdb->query('ROLLBACK');
			return self::error('connection_not_found', __('The active OAuth connection was not found.', 'llamly'), 404);
		}
		$now = gmdate('Y-m-d H:i:s');
		$grant = $wpdb->update($tables['oauth_grants'], ['revoked_at' => $now], ['id' => $id, 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$tokens = $wpdb->update($tables['oauth_tokens'], ['revoked_at' => $now], ['grant_id' => $id, 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$audited = $grant === 1 && $tokens !== false && AuditLog::append('oauth_grant_revoked', ['client_id' => (string) $row['client_id'], 'grant_id' => $id], get_current_user_id(), null, 'oauth:' . $id);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The OAuth connection could not be revoked with its audit record.', 'llamly'), 503);
		}
		return new \WP_REST_Response(['items' => self::connectionRows()], 200);
	}

	public static function revokeClient(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		global $wpdb;
		$clientId = sanitize_text_field((string) $request['id']);
		$table = Schema::tableNames()['oauth_clients'];
		if ($wpdb->query('START TRANSACTION') === false) {
			return self::error('storage_unavailable', __('The OAuth client could not be revoked.', 'llamly'), 503);
		}
		$client = $wpdb->get_row($wpdb->prepare('SELECT client_id FROM %i WHERE client_id = %s AND revoked_at IS NULL FOR UPDATE', $table, $clientId), ARRAY_A);
		if (!$client) {
			$wpdb->query('ROLLBACK');
			return self::error('connection_not_found', __('The active OAuth client was not found.', 'llamly'), 404);
		}
		$now = gmdate('Y-m-d H:i:s');
		$updated = $wpdb->update($table, ['revoked_at' => $now], ['client_id' => $clientId, 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$grants = $wpdb->update(Schema::tableNames()['oauth_grants'], ['revoked_at' => $now], ['client_id' => $clientId, 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$tokens = $wpdb->update(Schema::tableNames()['oauth_tokens'], ['revoked_at' => $now], ['client_id' => $clientId, 'revoked_at' => null], ['%s'], ['%s', '%s']);
		$audited = $updated === 1 && $grants !== false && $tokens !== false && AuditLog::append('oauth_client_revoked', ['client_id' => $clientId], get_current_user_id(), null, null);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The OAuth client and its audit event could not be revoked.', 'llamly'), 503);
		}
		return new \WP_REST_Response(['items' => self::connectionRows()], 200);
	}

	public static function getSettings(): \WP_REST_Response
	{
		return new \WP_REST_Response(self::publicSettings(Settings::get()), 200);
	}

	public static function saveSettings(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		if (!is_array($body) || !isset($body['settings']) || !is_array($body['settings']) || !isset($body['revision'])) {
			return self::error('invalid_settings', __('Settings and their current revision are required.', 'llamly'), 400);
		}
		$confirmRisk = ($body['confirm_risk'] ?? false) === true;
		global $wpdb;
		if ($wpdb->query('START TRANSACTION') === false) {
			return self::error('storage_unavailable', __('The settings transaction could not start.', 'llamly'), 503);
		}
		$saved = Settings::save($body['settings'], (int) $body['revision'], $confirmRisk);
		if (is_wp_error($saved)) {
			$wpdb->query('ROLLBACK');
			return $saved;
		}
		if (!AuditLog::append('settings_updated', ['fields' => array_keys($body['settings']), 'revision' => (int) $saved['revision']], get_current_user_id(), null, null)
			|| $wpdb->query('COMMIT') === false || $wpdb->last_error !== ''
		) {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The settings and audit event could not be committed.', 'llamly'), 503);
		}
		Settings::flushCache();
		// Re-read after commit: the saved array has no validity flag, and the UI must see the stored, validated state.
		return new \WP_REST_Response(self::publicSettings(Settings::fresh()), 200);
	}

	/**
	 * Activity tab (ACT-01..12): history rows with server-derived state, batches grouped, filters, search, pagination,
	 * and the agent requests waiting for an administrator's approval.
	 */
	public static function activity(\WP_REST_Request $request): \WP_REST_Response
	{
		$response = new \WP_REST_Response(self::activityModel($request->get_params()), 200);
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	/** @return array<string, mixed> */
	private static function activityModel(array $params): array
	{
		global $wpdb;
		// The D1 policy migration cancels requests left over from the old policy before they are listed.
		PolicyMigration::maybeRun();
		$page = max(1, (int) ($params['page'] ?? 1));
		$perPage = max(5, min(100, (int) ($params['per_page'] ?? 15) ?: 15));
		$state = in_array($params['state'] ?? 'all', ['all', 'active', 'undone', 'irreversible'], true) ? (string) ($params['state'] ?? 'all') : 'all';
		$credential = (string) ($params['credential'] ?? '');
		$credential = preg_match('/^(?:key:\d+|oauth:[a-f0-9-]{36}|admin:\d+|ability:\d+)$/', $credential) ? $credential : '';
		$objectType = sanitize_key((string) ($params['object_type'] ?? ''));
		$objectType = $objectType !== '' && Engine::adapter($objectType) ? $objectType : '';
		$q = function_exists('mb_strtolower') ? mb_strtolower(trim(sanitize_text_field((string) ($params['q'] ?? ''))), 'UTF-8') : strtolower(trim(sanitize_text_field((string) ($params['q'] ?? ''))));
		$q = substr($q, 0, 80);

		// Filters, state buckets, order and the page window run in SQL; only the current page is read.
		$filters = array_filter(['credential' => $credential, 'object_type' => $objectType]);
		$listing = MutationRunner::listOperations($page, $perPage, $filters + ['light' => true, 'statuses' => ['committed', 'recovery_required', 'applying'], 'state' => $state, 'q' => $q]);
		$scanned = $listing['items'];
		$page = (int) ($listing['page'] ?? 1);
		$pages = (int) ($listing['pages'] ?? 1);
		$counts = $listing['counts'] ?? ['all' => (int) $listing['total'], 'active' => 0, 'undone' => 0, 'irreversible' => 0];
		$total = (int) $listing['total'];
		$ops = Schema::tableNames()['operations'];

		$ids = array_column($scanned, 'id');
		$undoOf = [];
		$redoOf = [];
		$linkById = [];
		$redoTargets = array_values(array_unique(array_filter(array_column($scanned, 'redo_of'))));
		if ($ids || $redoTargets) {
			// One query: the undos of this page's changes, and the undo each redo on this page points to. An empty list
			// becomes [''], which matches no change id.
			$undoneIds = $ids ?: [''];
			$redoIds = $redoTargets ?: [''];
			$links = $wpdb->get_results($wpdb->prepare(
				"SELECT id, undo_of, redo_of, after_hash, credential_key, generation, created_at, expires_at FROM %i WHERE status = 'committed' AND (undo_of IN (" . implode(',', array_fill(0, count($undoneIds), '%s')) . ') OR id IN (' . implode(',', array_fill(0, count($redoIds), '%s')) . ')) ORDER BY created_at DESC, seq DESC, id DESC',
				array_merge([$ops], $undoneIds, $redoIds)
			), ARRAY_A);
			foreach (is_array($links) ? $links : [] as $link) {
				$linkById[(string) $link['id']] = $link;
				if ($link['undo_of'] !== null && !isset($undoOf[(string) $link['undo_of']])) {
					$undoOf[(string) $link['undo_of']] = $link;
				}
			}
			$undoIds = array_values(array_map(static fn(array $l): string => (string) $l['id'], $undoOf));
			if ($undoIds) {
				$redos = $wpdb->get_results($wpdb->prepare(
					"SELECT id, undo_of, redo_of, after_hash, credential_key, generation, created_at, expires_at FROM %i WHERE status = 'committed' AND redo_of IN (" . implode(',', array_fill(0, count($undoIds), '%s')) . ') ORDER BY created_at DESC, seq DESC, id DESC',
					array_merge([$ops], $undoIds)
				), ARRAY_A);
				foreach (is_array($redos) ? $redos : [] as $link) {
					$linkById[(string) $link['id']] = $link;
					if ($link['redo_of'] !== null && !isset($redoOf[(string) $link['redo_of']])) {
						$redoOf[(string) $link['redo_of']] = $link;
					}
				}
			}
		}
		// The newest committed change of each object on this page.
		$latest = [];
		$pairs = [];
		foreach ($scanned as $item) {
			if ($item['status'] === 'committed' && (int) $item['object_id'] > 0) {
				$pairs[$item['object_type'] . ':' . (int) $item['object_id']] = [(string) $item['object_type'], (int) $item['object_id']];
			}
		}
		if ($pairs) {
			// Type and id lists narrow the (object_type, object_id) index; the type:id key keeps exactly the pairs.
			$types = array_values(array_unique(array_column($pairs, 0)));
			$objectIds = array_values(array_unique(array_column($pairs, 1)));
			$keys = array_keys($pairs);
			$latestRows = $wpdb->get_results($wpdb->prepare(
				"SELECT o.id, o.object_type, o.object_id, o.after_hash, o.redo_of, o.generation, o.created_at, o.expires_at FROM %i o
				 WHERE o.status = 'committed' AND o.object_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ') AND o.object_id IN (' . implode(',', array_fill(0, count($objectIds), '%d')) . ") AND CONCAT(o.object_type, ':', o.object_id) IN (" . implode(',', array_fill(0, count($keys), '%s')) . ")
				 AND NOT EXISTS (SELECT 1 FROM %i n WHERE n.status = 'committed' AND n.object_type = o.object_type AND n.object_id = o.object_id
				   AND (n.created_at > o.created_at OR (n.created_at = o.created_at AND (n.seq > o.seq OR (n.seq = o.seq AND n.id > o.id)))))",
				array_merge([$ops], $types, $objectIds, $keys, [$ops])
			), ARRAY_A);
			foreach (is_array($latestRows) ? $latestRows : [] as $row) {
				$latest[$row['object_type'] . ':' . (int) $row['object_id']] = $row;
			}
		}
		$forceOf = [];
		foreach ($scanned as $i => $item) {
			if (str_starts_with((string) $item['tool'], 'force_undo_')) {
				$forceOf[(string) $item['id']] = (string) ($item['intent_of'] ?? '');
			}
			// No capture() per item: the object is assumed to be as the newest Llamly change left it. Undo re-checks the real hash.
			$key = $item['object_type'] . ':' . (int) $item['object_id'];
			$scanned[$i]['object_current_hash'] = isset($latest[$key]) ? (string) $latest[$key]['after_hash'] : null;
		}
		// Titles of the originals that undo/redo/force rows point to, in one query for the ones not on this page.
		$titles = [];
		foreach ($scanned as $item) {
			$titles[$item['id']] = ['title' => $item['title'], 'tool' => $item['tool'], 'created_at' => $item['created_at'], 'object_type' => $item['object_type'], 'object_id' => $item['object_id']];
		}
		$wanted = [];
		foreach ($scanned as $item) {
			if ($item['undo_of'] !== null) {
				$wanted[] = (string) $item['undo_of'];
			} elseif ($item['redo_of'] !== null) {
				$wanted[] = (string) (($linkById[(string) $item['redo_of']]['undo_of'] ?? '') ?: '');
			}
			if (isset($forceOf[$item['id']])) {
				$wanted[] = $forceOf[$item['id']];
			}
		}
		$wanted = array_values(array_unique(array_filter($wanted, static fn(string $id): bool => $id !== '' && !isset($titles[$id]))));
		if ($wanted) {
			$originals = $wpdb->get_results($wpdb->prepare(
				'SELECT id, tool, object_type, object_id, created_at, label FROM %i WHERE id IN (' . implode(',', array_fill(0, count($wanted), '%s')) . ')',
				array_merge([$ops], $wanted)
			), ARRAY_A);
			foreach (is_array($originals) ? $originals : [] as $row) {
				$titles[(string) $row['id']] = ['title' => $row['label'] !== null && $row['label'] !== '' ? Engine::displayLabel((string) $row['label']) : (string) $row['object_type'], 'tool' => (string) $row['tool'], 'created_at' => (string) $row['created_at'], 'object_type' => (string) $row['object_type'], 'object_id' => (int) $row['object_id']];
			}
		}

		$settings = Settings::get();
		$writesReady = MutationRunner::writeEnabled() && !empty($settings['enabled']) && !empty($settings['journal_enabled']);
		$generation = Engine::currentGeneration();
		$retention = max(1, (int) ($settings['journal_retention_days'] ?? 7));
		$linkExpired = static function (array $link) use ($generation, $retention): bool {
			if ((int) $link['generation'] !== $generation) {
				return true;
			}
			$expiry = $link['expires_at'] !== null ? strtotime($link['expires_at'] . ' UTC') : strtotime($link['created_at'] . ' UTC') + $retention * DAY_IN_SECONDS;
			return $expiry === false || $expiry <= time();
		};

		$rows = [];
		$batches = [];
		foreach ($scanned as $item) {
			$row = self::activityItem($item, $undoOf, $redoOf, $latest, $linkById, $titles, $linkExpired, $writesReady, $forceOf);
			if ($item['batch_id'] !== null && $item['undo_of'] === null && $item['redo_of'] === null) {
				$batchId = (string) $item['batch_id'];
				if (!isset($batches[$batchId])) {
					$batches[$batchId] = count($rows);
					$rows[] = ['kind' => 'batch', 'batch_id' => $batchId, 'created_at' => $item['created_at'], 'credential' => $item['credential'], 'members' => []];
				}
				$rows[$batches[$batchId]]['members'][] = $row;
				continue;
			}
			$rows[] = $row;
		}
		// Batch undo acts on every change of the batch, so a batch row also says which of its changes this page left out
		// (other filters, the search or another page) and which of those are still to undo. Counts per batch in SQL; the
		// names of the pending ones come from a capped list (the row shows at most 10 per batch).
		$outsideStats = [];
		$outsidePending = [];
		if ($batches) {
			$batchIds = array_map('strval', array_keys($batches));
			$shownIds = [];
			foreach ($batches as $index) {
				foreach ($rows[$index]['members'] as $member) {
					$shownIds[] = (string) $member['id'];
				}
			}
			// "Outside" = a change of the batch not shown on this page; "undone" = it has a committed undo without a redo.
			$stats = $wpdb->get_results($wpdb->prepare(
				'SELECT o.batch_id, SUM(o.id NOT IN (' . implode(',', array_fill(0, count($shownIds), '%s')) . ')) AS outside_total,
				 SUM(o.id NOT IN (' . implode(',', array_fill(0, count($shownIds), '%s')) . ") AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed' AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.redo_of = u.id AND r.status = 'committed'))) AS outside_pending
				 FROM %i o WHERE o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL AND o.batch_id IN (" . implode(',', array_fill(0, count($batchIds), '%s')) . ') GROUP BY o.batch_id',
				array_merge($shownIds, $shownIds, [$ops, $ops, $ops], $batchIds)
			), ARRAY_A);
			$pending = $wpdb->get_results($wpdb->prepare(
				"SELECT o.id, o.batch_id, o.tool, o.object_type, o.object_id, o.label FROM %i o WHERE o.status = 'committed' AND o.undo_of IS NULL AND o.redo_of IS NULL AND o.batch_id IN (" . implode(',', array_fill(0, count($batchIds), '%s')) . ') AND o.id NOT IN (' . implode(',', array_fill(0, count($shownIds), '%s')) . ")
				 AND NOT EXISTS (SELECT 1 FROM %i u WHERE u.undo_of = o.id AND u.status = 'committed' AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.redo_of = u.id AND r.status = 'committed')) ORDER BY o.created_at DESC, o.seq DESC, o.id DESC LIMIT 1000",
				array_merge([$ops], $batchIds, $shownIds, [$ops, $ops])
			), ARRAY_A);
			foreach (is_array($stats) ? $stats : [] as $stat) {
				$outsideStats[(string) $stat['batch_id']] = ['total' => (int) $stat['outside_total'], 'pending' => (int) $stat['outside_pending']];
			}
			foreach (is_array($pending) ? $pending : [] as $op) {
				$outsidePending[(string) $op['batch_id']][] = $op;
			}
		}
		foreach ($batches as $batchId => $index) {
			$rows[$index] = self::batchRow($rows[$index], $writesReady, $outsideStats[(string) $batchId] ?? ['total' => 0, 'pending' => 0], $outsidePending[(string) $batchId] ?? []);
		}

		$credentials = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT credential_key FROM %i WHERE status IN ('committed','recovery_required','applying') ORDER BY credential_key LIMIT 200", $ops));
		return [
			'items' => $rows,
			'total' => $total,
			'page' => $page,
			'per_page' => $perPage,
			'pages' => $pages,
			'counts' => $counts,
			'undoable' => count(array_filter($rows, static fn(array $row): bool => !empty($row['can_undo']))),
			'truncated' => false,
			'filters' => ['state' => $state, 'credential' => $credential, 'object_type' => $objectType, 'q' => $q],
			'credentials' => array_values(array_filter(array_map('strval', is_array($credentials) ? $credentials : []))),
			'object_types' => array_keys(Engine::adapters()),
			'writes_ready' => $writesReady,
			'approvals' => self::pendingApprovals(),
		];
	}

	/**
	 * One activity row with its state decided here, never by the browser.
	 *
	 * @return array<string, mixed>
	 */
	private static function activityItem(array $item, array $undoOf, array $redoOf, array $latest, array $linkById, array $titles, callable $linkExpired, bool $writesReady, array $forceOf = []): array
	{
		$row = [
			'kind' => 'item',
			'id' => $item['id'],
			'tool' => $item['tool'],
			'object_type' => $item['object_type'],
			'object_id' => (int) $item['object_id'],
			'title' => $item['title'],
			'status' => $item['status'],
			'error_code' => $item['error_code'],
			'credential' => $item['credential'],
			'changes' => $item['changes'],
			'batch_id' => $item['batch_id'],
			'undo_of' => $item['undo_of'],
			'redo_of' => $item['redo_of'],
			'created_at' => $item['created_at'],
			'expires_at' => $item['expires_at'],
			'state' => 'active',
			'can_undo' => false,
			'can_redo' => false,
			'reason' => null,
			'notice' => null,
			'undone' => null,
			'redone' => null,
			'relation' => null,
		];
		if ($item['status'] === 'recovery_required') {
			$row['state'] = 'recovery';
			$row['notice'] = __('This change was interrupted and blocks the item until it is reviewed.', 'llamly');
			return $row;
		}
		if ($item['status'] === 'applying') {
			$row['state'] = 'applying';
			return $row;
		}
		$owner = \Llamly\Free\Modules\ModuleRegistry::ownerOfType((string) $item['object_type']);
		if ($owner !== null) {
			$row['module'] = $owner;
		} else {
			// A core item (a block page, for example) written by an add-on tool: labelled with the add-on, undone by the core.
			$byTool = \Llamly\Free\Modules\ModuleRegistry::ownerOfTool((string) $item['tool']);
			if ($byTool !== null) {
				$row['module'] = $byTool;
			}
		}
		if ($owner !== null && !$owner['active'] && $item['undo_of'] === null && $item['redo_of'] === null) {
			// The add-on that made this change is deactivated or removed: the row stays, without undo, until it is back.
			$row['state'] = 'module_inactive';
			/* translators: %s: add-on name. */
			$row['notice'] = sprintf(__('Made by the add-on “%s”, which is not active. Activate it to undo or redo this change.', 'llamly'), $owner['label']);
			return $row;
		}
		if ($item['undo_of'] !== null || $item['redo_of'] !== null) {
			$action = $item['undo_of'] !== null ? 'undo' : 'redo';
			$originalId = $action === 'undo' ? (string) $item['undo_of'] : (string) (($linkById[(string) $item['redo_of']]['undo_of'] ?? '') ?: '');
			$original = $titles[$originalId] ?? null;
			if (!$original && $originalId !== '') {
				$op = Engine::operation($originalId);
				$original = $op ? ['title' => $item['title'], 'tool' => (string) $op['tool'], 'created_at' => (string) $op['created_at']] : null;
			}
			$row['state'] = 'inverse';
			$row['relation'] = ['action' => $action, 'of' => $originalId ?: null, 'of_title' => $original['title'] ?? null, 'of_tool' => $original['tool'] ?? null, 'of_created_at' => $original['created_at'] ?? null];
			return $row;
		}
		if (isset($forceOf[$item['id']])) {
			// ACT-12: a forced undo is a change of its own; undoing it brings back the state it replaced.
			$of = $forceOf[$item['id']];
			$original = $titles[$of] ?? null;
			if (!$original && $of !== '') {
				$op = Engine::operation($of);
				$original = $op ? ['title' => $item['title'], 'tool' => (string) $op['tool'], 'created_at' => (string) $op['created_at']] : null;
			}
			$row['relation'] = ['action' => 'force', 'of' => $of ?: null, 'of_title' => $original['title'] ?? null, 'of_tool' => $original['tool'] ?? null, 'of_created_at' => $original['created_at'] ?? null];
		}
		$current = $item['object_current_hash'];
		$undo = $undoOf[$item['id']] ?? null;
		$redo = $undo ? ($redoOf[(string) $undo['id']] ?? null) : null;
		if ($undo) {
			$row['undone'] = ['id' => (string) $undo['id'], 'at' => (string) $undo['created_at'], 'credential' => (string) $undo['credential_key']];
		}
		if ($redo) {
			$row['redone'] = ['id' => (string) $redo['id'], 'at' => (string) $redo['created_at'], 'credential' => (string) $redo['credential_key']];
		}
		if (!empty($item['undo_expired'])) {
			$row['state'] = 'irreversible';
			$row['reason'] = $item['undo_reason'] ?: __('The time to undo this change has ended.', 'llamly');
			$row['reason_code'] = !empty($item['undo_restored']) ? 'restore' : 'expired';
			if ($undo && !$redo) {
				$row['state'] = 'undone';
			}
			return $row;
		}
		if ($undo && $redo) {
			$row['state'] = 'irreversible';
			$row['reason'] = __('It was already undone and redone once. It cannot be undone again.', 'llamly');
			$row['reason_code'] = 'cycle_closed';
			return $row;
		}
		if ($undo) {
			$row['state'] = 'undone';
			if ($linkExpired($undo)) {
				$row['reason'] = __('The time to redo this change has ended.', 'llamly');
			} elseif ($current !== null && hash_equals((string) $undo['after_hash'], (string) $current)) {
				$row['can_redo'] = $writesReady;
			} else {
				$row['notice'] = __('The item changed after this change was undone. Redo is unavailable.', 'llamly');
			}
			return $row;
		}
		if ($current !== null && $item['after_hash'] !== null && hash_equals((string) $item['after_hash'], (string) $current)) {
			$row['can_undo'] = $writesReady;
			return $row;
		}
		$newest = $latest[$item['object_type'] . ':' . (int) $item['object_id']] ?? null;
		if ($newest && (string) $newest['id'] !== $item['id'] && $current !== null && hash_equals((string) $newest['after_hash'], (string) $current)) {
			// A newer change that is itself a redo (closed cycle) or past its window can never be undone,
			// so "undo the newer ones first" would be a dead end: say so and offer the forced path.
			$newestRedo = $newest['redo_of'] !== null;
			if ($newestRedo || $linkExpired($newest)) {
				$canForce = $writesReady && (string) $item['tool'] !== 'wp_edit_blocks' && current_user_can('manage_options');
				$row['state'] = 'review';
				$row['can_force'] = $canForce;
				$row['reason_code'] = $newestRedo ? 'newer_cycle_closed' : 'newer_expired';
				$what = $newestRedo
					? __('A newer change to this item was already undone and redone and cannot be undone again.', 'llamly')
					: __('A newer change to this item can no longer be undone because its time has ended.', 'llamly');
				$row['notice'] = $canForce
					? $what . ' ' . __('To go back to this point, use Undo anyway.', 'llamly')
					: $what . ' ' . __('Check the item in WordPress.', 'llamly');
				return $row;
			}
			$row['notice'] = __('Undo the newer changes to this item first.', 'llamly');
			return $row;
		}
		$adapter = Engine::adapter((string) $item['object_type']);
		$removed = $adapter && $current !== null && hash_equals(Engine::absentHash($adapter), (string) $current);
		$row['state'] = 'review';
		// Forced undo (ACT-12) is offered to administrators only, and never for Gutenberg block batches.
		$row['can_force'] = $writesReady && (string) $item['tool'] !== 'wp_edit_blocks' && current_user_can('manage_options');
		$row['notice'] = $removed
			? __('The item was deleted outside Llamly after this change.', 'llamly')
			: __('The item was changed outside Llamly after this change.', 'llamly');
		return $row;
	}

	/** @return array<string, mixed> */
	private static function batchRow(array $batch, bool $writesReady, array $outside = ['total' => 0, 'pending' => 0], array $pending = []): array
	{
		$members = $batch['members'];
		$states = array_count_values(array_map(static fn(array $m): string => $m['state'], $members));
		$n = count($members);
		$state = 'active';
		if (($states['undone'] ?? 0) === $n && !$outside['pending']) {
			$state = 'undone';
		} elseif (($states['irreversible'] ?? 0) === $n && !$outside['pending']) {
			$state = 'irreversible';
		} elseif (!empty($states['recovery'])) {
			$state = 'recovery';
		} elseif (!empty($states['review'])) {
			$state = 'review';
		}
		$types = array_values(array_unique(array_map(static fn(array $m): string => $m['object_type'], $members)));
		$tools = array_values(array_unique(array_map(static fn(array $m): string => $m['tool'], $members)));
		return $batch + [
			'id' => 'batch:' . $batch['batch_id'],
			'count' => $n,
			'state' => $state,
			'states' => $states,
			'object_types' => $types,
			'tools' => $tools,
			'title' => $members[0]['title'] ?? '',
			'tool' => implode(', ', $tools),
			'object_type' => $types[0] ?? '',
			'object_id' => 0,
			'can_undo' => $writesReady && ($outside['pending'] > 0 || count(array_filter($members, static fn(array $m): bool => in_array($m['state'], ['active', 'review'], true))) > 0),
			// Undo batch acts on the whole batch: its size, the changes this listing left out, and up to 10 of those still to undo.
			'total_count' => $n + $outside['total'],
			'outside_count' => $outside['total'],
			'outside_pending' => $outside['pending'],
			'outside' => array_map(static fn(array $op): array => [
				'tool' => (string) $op['tool'],
				'object_type' => (string) $op['object_type'],
				'object_id' => (int) $op['object_id'],
				'title' => $op['label'] !== null && $op['label'] !== '' ? Engine::displayLabel((string) $op['label']) : (string) $op['object_type'],
				'module' => \Llamly\Free\Modules\ModuleRegistry::ownerOfType((string) $op['object_type']) ?? \Llamly\Free\Modules\ModuleRegistry::ownerOfTool((string) $op['tool']),
			], array_slice($pending, 0, 10)),
		];
	}

	/**
	 * Agent requests stopped at awaiting_approval (the optional human queue), with the risk recorded when the request was
	 * made and the preview of what would change. A request older than its lifetime is not listed, and identical requests
	 * from the same connection are grouped into one card.
	 */
	private static function pendingApprovals(): array
	{
		global $wpdb;
		$cutoff = gmdate('Y-m-d H:i:s', time() - Engine::REQUEST_TTL);
		$listing = MutationRunner::listOperations(1, 50, ['awaiting_approval' => true, 'light' => true, 'counts' => false, 'intent' => 'full', 'updated_after' => $cutoff]);
		$items = $listing['items'];
		if (!$items) {
			return [];
		}
		$tables = Schema::tableNames();
		$ids = array_column($items, 'id');
		// Risk recorded with each request: one audit query for the whole list.
		$risks = [];
		// The audit row was written when the request was made: nothing older than the oldest listed request is read.
		$since = gmdate('Y-m-d H:i:s', max(0, min(array_map(static fn(array $i): int => (int) strtotime($i['created_at'] . ' UTC'), $items)) - HOUR_IN_SECONDS));
		// The request's operation_id is a top-level key of the audit context (always JSON written by AuditLog).
		$audit = $wpdb->get_results($wpdb->prepare(
			"SELECT context FROM %i WHERE event = 'approval_requested' AND created_at >= %s AND (CASE WHEN JSON_VALID(context) THEN JSON_UNQUOTE(JSON_EXTRACT(context, '$.operation_id')) END) IN (" . implode(',', array_fill(0, count($ids), '%s')) . ') ORDER BY id DESC LIMIT 200',
			array_merge([$tables['audit'], $since], $ids)
		), ARRAY_A);
		foreach (is_array($audit) ? $audit : [] as $row) {
			$decoded = json_decode((string) $row['context'], true);
			$opId = is_array($decoded) && is_string($decoded['operation_id'] ?? null) ? $decoded['operation_id'] : '';
			if ($opId !== '' && !isset($risks[$opId]) && is_array($decoded['risk'] ?? null)) {
				$risks[$opId] = $decoded['risk'];
			}
		}
		$approved = [];
		$permits = $wpdb->get_results($wpdb->prepare(
			'SELECT operation_id, MAX(expires_at) AS until FROM %i WHERE operation_id IN (' . implode(',', array_fill(0, count($ids), '%s')) . ') AND consumed_at IS NULL AND expires_at > %s GROUP BY operation_id',
			array_merge([$tables['permits']], $ids, [gmdate('Y-m-d H:i:s')])
		), ARRAY_A);
		foreach (is_array($permits) ? $permits : [] as $row) {
			$approved[(string) $row['operation_id']] = (string) $row['until'];
		}
		$out = [];
		$groups = [];
		foreach ($items as $item) {
			$risk = $risks[$item['id']] ?? null;
			$intent = is_array($item['intent'] ?? null) ? $item['intent'] : [];
			$preview = Engine::previewPending((string) $item['object_type'], (int) $item['object_id'], $intent);
			$createdTs = strtotime($item['updated_at'] . ' UTC') ?: time();
			$card = [
				'id' => $item['id'],
				'ids' => [$item['id']],
				'count' => 1,
				'tool' => $item['tool'],
				'object_type' => $item['object_type'],
				'object_id' => (int) $item['object_id'],
				'title' => $item['title'],
				'credential' => $item['credential'],
				'changes' => $item['changes'],
				'preview' => $preview ? ['changes' => $preview['changes'] ?? [], 'truncated' => !empty($preview['changes_truncated'])] : null,
				'created_at' => $item['created_at'],
				'expires_at' => gmdate('Y-m-d H:i:s', $createdTs + Engine::REQUEST_TTL),
				'risk' => $risk ? [
					'public' => !empty($risk['public']),
					'sensitive' => !empty($risk['sensitive']),
					'destructive' => !empty($risk['destructive']),
					'external_effects' => array_values(array_filter(array_map('strval', (array) ($risk['external_effects'] ?? [])))),
				] : null,
				'approved_until' => isset($approved[$item['id']]) && $approved[$item['id']] !== '' ? $approved[$item['id']] : null,
			];
			// One card only for requests that are the same request (same hash of the whole call and the same item state) and
			// differ only in the idempotency key. The shown text is shortened and redacted, so it is never the grouping key.
			$key = hash('sha256', wp_json_encode([$item['credential'], $item['tool'], $item['object_type'], (int) $item['object_id'], $item['request_hash'], $item['before_hash']]));
			if (isset($groups[$key])) {
				$lead = &$out[$groups[$key]];
				$lead['ids'][] = $item['id'];
				$lead['count']++;
				if ($card['approved_until'] !== null && ($lead['approved_until'] === null || $card['approved_until'] > $lead['approved_until'])) {
					$lead['approved_until'] = $card['approved_until'];
				}
				unset($lead);
				continue;
			}
			$groups[$key] = count($out);
			$out[] = $card;
		}
		return $out;
	}

	/** Approve or reject an agent request that stopped at awaiting_approval. The agent repeats the call to apply it. */
	public static function decideRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$id = strtolower((string) $request['id']);
		$result = (string) $request['decision'] === 'approve'
			? Engine::approveOperation($id, get_current_user_id())
			: Engine::rejectOperation($id, get_current_user_id());
		if (is_wp_error($result)) {
			return $result;
		}
		$response = new \WP_REST_Response(['result' => $result, 'approvals' => self::pendingApprovals()], 200);
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	/**
	 * Undo a whole batch newest first, each item through the same approve → permit → undo path as a single undo.
	 * Items already undone are skipped; the first other failure stops the run and reports what remains.
	 */
	public static function undoBatch(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		if (!is_array($body) || ($body['confirmed'] ?? false) !== true) {
			return self::error('confirmation_required', __('Confirm undoing the batch in the dialog before continuing.', 'llamly'), 409);
		}
		$batchId = strtolower((string) $request['batch']);
		$ids = [];
		$titles = [];
		for ($page = 1; $page <= 5; $page++) {
			$listing = MutationRunner::listOperations($page, 100, ['batch_id' => $batchId, 'status' => 'committed']);
			foreach ($listing['items'] as $item) {
				if ($item['undo_of'] === null && $item['redo_of'] === null) {
					$ids[] = (string) $item['id'];
					$titles[(string) $item['id']] = (string) $item['title'];
				}
			}
			if ($page * 100 >= (int) $listing['total']) {
				break;
			}
		}
		if (!$ids) {
			return self::error('operation_not_found', __('No committed changes were found for this batch.', 'llamly'), 404);
		}
		$user = get_current_user_id();
		$done = [];
		$skipped = [];
		foreach ($ids as $index => $id) {
			$approval = MutationRunner::approveInverse($id, 'undo', $user);
			$result = is_wp_error($approval) ? $approval : MutationRunner::undo($id, $user, !empty($approval['required']) ? (string) ($approval['permit'] ?? '') : '');
			if (is_wp_error($result)) {
				if (in_array($result->get_error_code(), ['already_undone', 'undo_cycle_closed'], true)) {
					$skipped[] = $id;
					continue;
				}
				return new \WP_REST_Response([
					'batch_id' => $batchId,
					'total' => count($ids),
					'undone_count' => count($done),
					'undone' => $done,
					'skipped' => $skipped,
					'stopped_at' => $id,
					'stopped_title' => $titles[$id] ?? '',
					'error' => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'remaining' => array_slice($ids, $index),
				], 200);
			}
			$done[] = $id;
		}
		return new \WP_REST_Response(['batch_id' => $batchId, 'total' => count($ids), 'undone_count' => count($done), 'undone' => $done, 'skipped' => $skipped, 'remaining' => []], 200);
	}

	public static function approve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$action = (string) $request['action'];
		$result = MutationRunner::approveInverse((string) $request['id'], $action === 'redo' ? 'redo' : 'undo', get_current_user_id());
		if (is_wp_error($result)) {
			return $result;
		}
		$response = new \WP_REST_Response($result, 201);
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	/** ACT-12: the permit is always required, because a forced undo overwrites newer work. */
	public static function approveForce(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = \Llamly\Free\History\Engine::approveForce((string) $request['id'], get_current_user_id());
		if (is_wp_error($result)) {
			return $result;
		}
		$response = new \WP_REST_Response($result, 201);
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	public static function forceUndo(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = \Llamly\Free\History\Engine::forceUndo((string) $request['id'], get_current_user_id(), self::permitParam($request));
		return is_wp_error($result) ? $result : new \WP_REST_Response(['result' => $result], 200);
	}

	public static function resolveRecovery(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = MutationRunner::resolveRecovery((string) $request['id'], get_current_user_id());
		return is_wp_error($result) ? $result : new \WP_REST_Response(['result' => $result, 'activity' => MutationRunner::listOperations(1, 25)], 200);
	}

	public static function undo(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = MutationRunner::undo((string) $request['id'], get_current_user_id(), self::permitParam($request));
		return is_wp_error($result) ? $result : new \WP_REST_Response(['result' => $result, 'activity' => MutationRunner::listOperations(1, 25)], 200);
	}

	public static function redo(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$result = MutationRunner::redo((string) $request['id'], get_current_user_id(), self::permitParam($request));
		return is_wp_error($result) ? $result : new \WP_REST_Response(['result' => $result, 'activity' => MutationRunner::listOperations(1, 25)], 200);
	}

	private static function permitParam(\WP_REST_Request $request): string
	{
		$body = $request->get_json_params();
		$permit = is_array($body) ? ($body['permit'] ?? '') : '';
		return is_string($permit) ? $permit : '';
	}

	public static function logs(\WP_REST_Request $request): \WP_REST_Response
	{
		$page = max(1, (int) $request->get_param('page'));
		$perPage = max(10, min(100, (int) ($request->get_param('per_page') ?: 20)));
		ConnectFunnel::settle(false); // an attempt whose window ended shows up here with the stage where it stopped
		return new \WP_REST_Response(AuditLog::list($page, $perPage, self::logFilters($request->get_params())), 200);
	}

	/** @return array{event:string,result:string,credential:string,q:string} */
	private static function logFilters(array $params): array
	{
		return [
			'event' => sanitize_key((string) ($params['event'] ?? '')),
			'result' => sanitize_key((string) ($params['result'] ?? '')),
			'credential' => sanitize_text_field((string) ($params['credential'] ?? '')),
			'q' => sanitize_text_field((string) ($params['q'] ?? '')),
			'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($params['date_from'] ?? '')) ? (string) $params['date_from'] : '',
			'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($params['date_to'] ?? '')) ? (string) $params['date_to'] : '',
		];
	}

	public static function clearLogs(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$body = $request->get_json_params();
		if (!is_array($body) || ($body['confirmed'] ?? false) !== true) {
			return self::error('confirmation_required', __('Confirm clearing the audit log in the dialog before continuing.', 'llamly'), 409);
		}
		global $wpdb;
		$table = Schema::tableNames()['audit'];
		if ($wpdb->query('START TRANSACTION') === false) {
			return self::error('storage_unavailable', __('The audit log transaction could not start.', 'llamly'), 503);
		}
		$count = $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
		if ($count === false) {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The audit log could not be cleared.', 'llamly'), 503);
		}
		$count = (int) $count;
		if (!AuditLog::append('audit_cleared', ['deleted_entries' => $count], get_current_user_id(), null, null)) {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The audit log was cleared but the confirmation event could not be recorded.', 'llamly'), 503);
		}
		if ($wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return self::error('storage_unavailable', __('The audit log clear could not be committed.', 'llamly'), 503);
		}
		ConnectFunnel::forget(); // its steps were in the cleared log
		return new \WP_REST_Response(['deleted_entries' => $count], 200);
	}

	public static function doctor(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$report = Doctor::report();
		if ($request->get_method() === 'POST') {
			$probe = Doctor::runLoopbackProbes(get_current_user_id());
			if (is_wp_error($probe)) {
				return $probe;
			}
			$report = array_merge($report, $probe);
		}
		return new \WP_REST_Response($report, 200);
	}

	public static function exportLogs(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to export Llamly logs.', 'llamly'), '', ['response' => 403]);
		}
		check_admin_referer('llamly_free_export_logs');
		// The export follows the filters on screen; the cap keeps one request bounded.
		$params = [];
		foreach (['event', 'result', 'credential', 'q', 'date_from', 'date_to'] as $key) {
			if (isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
				$params[$key] = sanitize_text_field(wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
			}
		}
		$filters = self::logFilters($params);
		$all = [];
		for ($page = 1; $page <= 100; $page++) {
			$data = AuditLog::list($page, 100, $filters);
			$all = array_merge($all, $data['items']);
			if (count($all) >= $data['total'] || !$data['items']) {
				break;
			}
		}
		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="llamly-audit.csv"');
		$out = fopen('php://output', 'wb');
		if (!$out) {
			wp_die(esc_html__('The CSV export could not start.', 'llamly'), '', ['response' => 500]);
		}
		fputcsv($out, ['id', 'created_at_utc', 'event', 'tool', 'result', 'duration_ms', 'ip', 'credential', 'context']);
		foreach ($all as $item) {
			fputcsv($out, [
				self::safeCsv((string) $item['id']),
				self::safeCsv((string) $item['created_at']),
				self::safeCsv((string) $item['event']),
				self::safeCsv((string) ($item['tool'] ?? '')),
				self::safeCsv((string) $item['result']),
				self::safeCsv((string) ($item['duration_ms'] ?? '')),
				self::safeCsv((string) ($item['ip'] ?? '')),
				self::safeCsv((string) ($item['credential'] ?? '')),
				self::safeCsv((string) wp_json_encode($item['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
			]);
		}
		if (count($all) >= 10000) {
			// Make truncation explicit in the file instead of silently cutting the export.
			fputcsv($out, ['', '', 'export_truncated', '', '', '', '', '', 'Only the newest 10000 rows were exported. Narrow the filters to export the rest.']);
		}
		// php://output is flushed and closed by exit.
		exit;
	}

	/** @return list<array<string, mixed>> */
	private static function connectionRows(): array
	{
		global $wpdb;
		$rows = [];
		foreach (CredentialStore::listForAdmin() as $key) {
			$rows[] = [
				'id' => 'key:' . $key['id'],
				'kind' => 'key',
				'label' => $key['label'],
				'scope_mode' => $key['scope_mode'],
				'allowed_tools' => $key['allowed_tools'],
				'prefix' => $key['prefix'],
				'user_name' => self::userName((int) $key['user_id']),
				'status' => $key['revoked_at'] ? 'revoked' : 'active',
				'created_at' => $key['created_at'],
				'last_used_at' => $key['last_used_at'],
				'revoked_at' => $key['revoked_at'],
			];
		}
		$tables = Schema::tableNames();
		$grants = $wpdb->get_results($wpdb->prepare(
			"SELECT g.id, g.client_id, g.user_id, g.resource, g.scopes, g.created_at, g.revoked_at,
				c.name AS client_name, c.redirect_uris,
				MAX(t.last_used_at) AS last_used_at, c.revoked_at AS client_revoked
			 FROM %i g
			 INNER JOIN %i c ON c.client_id = g.client_id
			 LEFT JOIN %i t ON t.grant_id = g.id
			 GROUP BY g.id, g.client_id, g.user_id, g.resource, g.scopes, g.created_at, g.revoked_at, c.name, c.redirect_uris, c.revoked_at
			 ORDER BY g.created_at DESC LIMIT 200",
			$tables['oauth_grants'],
			$tables['oauth_clients'],
			$tables['oauth_tokens']
		), ARRAY_A);
		foreach (is_array($grants) ? $grants : [] as $grant) {
			$scopes = json_decode((string) $grant['scopes'], true);
			$uris = json_decode((string) $grant['redirect_uris'], true);
			$rows[] = [
				'id' => 'oauth:' . (string) $grant['id'],
				'kind' => 'oauth',
				'grant_id' => (string) $grant['id'],
				'client_id' => (string) $grant['client_id'],
				'label' => (string) $grant['client_name'],
				'redirect_host' => is_array($uris) && isset($uris[0]) ? OAuthRedirectPolicy::hostForLabel((string) $uris[0]) : '',
				'resource' => (string) $grant['resource'],
				'scopes' => is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
				'user_id' => (int) $grant['user_id'],
				'user_name' => self::userName((int) $grant['user_id']),
				'created_at' => (string) $grant['created_at'],
				'last_used_at' => $grant['last_used_at'] === null ? null : (string) $grant['last_used_at'],
				'revoked_at' => ($grant['revoked_at'] ?? $grant['client_revoked']) === null ? null : (string) ($grant['revoked_at'] ?: $grant['client_revoked']),
			];
		}
		$clients = $wpdb->get_results($wpdb->prepare(
			"SELECT c.client_id, c.name, c.redirect_uris, c.created_at, c.revoked_at
			 FROM %i c LEFT JOIN %i g ON g.client_id = c.client_id
			 WHERE g.id IS NULL ORDER BY c.created_at DESC LIMIT 100",
			$tables['oauth_clients'],
			$tables['oauth_grants']
		), ARRAY_A);
		foreach (is_array($clients) ? $clients : [] as $client) {
			$uris = json_decode((string) $client['redirect_uris'], true);
			$rows[] = [
				'id' => 'client:' . (string) $client['client_id'],
				'client_id' => (string) $client['client_id'],
				'kind' => 'oauth_client',
				'label' => (string) $client['name'],
				'redirect_host' => is_array($uris) && isset($uris[0]) ? OAuthRedirectPolicy::hostForLabel((string) $uris[0]) : '',
				'status' => $client['revoked_at'] === null ? 'registered' : 'revoked',
				'created_at' => (string) $client['created_at'],
				'last_used_at' => null,
				'revoked_at' => $client['revoked_at'] === null ? null : (string) $client['revoked_at'],
			];
		}
		usort($rows, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
		return $rows;
	}

	/** Display name of the WordPress user a connection acts as; shown in Apps conectados. */
	private static function userName(int $userId): string
	{
		$user = $userId > 0 ? get_userdata($userId) : false;
		return $user ? wp_strip_all_tags((string) $user->display_name) : '';
	}

	/**
	 * Claude's documented custom-connector install link (modelled on Cowboy MCP): opens “Add custom connector”
	 * with the name and URL prefilled; the person still reviews and confirms in Claude.
	 */
	private static function claudeInstallUrl(bool $org): string
	{
		$query = http_build_query([
			'modal' => 'add-custom-connector',
			'connectorName' => (string) wp_parse_url(home_url(), PHP_URL_HOST),
			// Byte-identical to the protected resource: /authorize compares it strictly.
			'connectorUrl' => untrailingslashit(rest_url('llamly/v1/endpoint')),
		], '', '&', PHP_QUERY_RFC3986);
		return 'https://claude.ai/' . ($org ? 'admin-settings/connectors' : 'customize/connectors') . '?' . $query;
	}

	/**
	 * Help destinations shown in Sobre and the Doctor. Undefined links are omitted instead of rendered as placeholders.
	 *
	 * @return array{docs:string,support:string,issues:string}
	 */
	private static function helpLinks(): array
	{
		$links = apply_filters('llamly_free_help_links', [
			'docs' => defined('LLAMLY_FREE_DOCS_URL') ? (string) LLAMLY_FREE_DOCS_URL : '',
			'support' => defined('LLAMLY_FREE_SUPPORT_URL') ? (string) LLAMLY_FREE_SUPPORT_URL : '',
			'issues' => defined('LLAMLY_FREE_ISSUES_URL') ? (string) LLAMLY_FREE_ISSUES_URL : '',
		]);
		$clean = [];
		foreach (['docs', 'support', 'issues'] as $key) {
			$url = is_array($links) ? esc_url_raw((string) ($links[$key] ?? '')) : '';
			$clean[$key] = str_starts_with($url, 'https://') ? $url : '';
		}
		return $clean;
	}

	private static function windowStatus(): array
	{
		$settings = Settings::get();
		$until = (int) ($settings['oauth_window_until'] ?? 0);
		return ['open' => $until > time(), 'until' => $until > time() ? $until : 0, 'seconds_left' => max(0, $until - time())];
	}

	/** @return list<array{name:string,description:string,category:string,access:string,available:bool}> */
	private static function toolOptions(): array
	{
		$items = [];
		foreach (ToolRegistry::all() as $name => $definition) {
			$access = (string) ($definition['access'] ?? 'read');
			if ($access === 'write' && !MutationRunner::writeEnabled()) {
				continue;
			}
			$items[] = [
				'name' => (string) $name,
				'description' => sanitize_text_field((string) ($definition['description'] ?? '')),
				'category' => sanitize_key((string) ($definition['category'] ?? 'general')),
				'access' => $access,
				'available' => true,
			];
		}
		return $items;
	}

	private static function publicSettings(array $settings): array
	{
		$settings['valid'] = !empty($settings['_valid']);
		unset($settings['_valid']);
		$settings['window'] = self::windowStatus();
		$settings['write_enabled'] = MutationRunner::writeEnabled();
		return $settings;
	}

	private static function safeCsv(string $value): string
	{
		return preg_match('/^[\s]*[=+\-@\t\r]/', $value) ? "'" . $value : $value;
	}

	private static function error(string $code, string $message, int $status): \WP_Error
	{
		return new \WP_Error($code, $message, ['status' => $status]);
	}
}
