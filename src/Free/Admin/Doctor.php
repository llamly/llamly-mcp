<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Admin;

use Llamly\Free\Auth\CredentialStore;
use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;
use Llamly\Free\History\MutationRunner;

/** Local, bounded diagnostics for the current WordPress site. */
final class Doctor
{
	private const MAX_GET_RESPONSE_BYTES = 1048576;
	private const MAX_POST_RESPONSE_BYTES = 65536;
	private const TEMPORARY_KEY_LABEL = 'Temporary Llamly Doctor probe';

	private const BROWSER_CHECKS = [
		['id' => 'browser_rest_index', 'label' => 'REST API reachable from this browser'],
		['id' => 'browser_endpoint_unauth', 'label' => 'MCP endpoint returns an unauthenticated challenge'],
		['id' => 'browser_well_known_pr', 'label' => 'Protected-resource discovery reachable from this browser'],
		['id' => 'browser_well_known_as', 'label' => 'Authorization-server discovery reachable from this browser'],
	];

	/** @return array<string, mixed> */
	public static function report(): array
	{
		$checks = self::configurationChecks();
		$counts = self::counts($checks);
		return [
			'summary' => self::summary($counts),
			'counts' => $counts,
			'checks' => $checks,
			'browser_checks' => array_map(static function (array $check): array {
				return $check + ['status' => 'pending', 'detail' => __('Run the browser checks to confirm this path.', 'llamly')];
			}, self::BROWSER_CHECKS),
			'environment' => self::environment(),
			'generated_at' => gmdate('c'),
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function runLoopbackProbes(int $userId): array|\WP_Error
	{
		if ($userId < 1 || !user_can($userId, 'manage_options')) {
			return new \WP_Error('forbidden', __('A site administrator must run the connection checks.', 'llamly'), ['status' => 403]);
		}

		// A crash during an earlier run could leave its temporary key active; close those before creating a new one.
		\Llamly\Free\Core\Maintenance::revokeStaleDoctorKeys();
		$report = self::report();
		$checks = $report['checks'];
		$endpoint = rest_url('llamly/v1/endpoint');
		$restIndex = rest_url();
		$checks[] = self::probeRestIndex($restIndex);
		$checks[] = self::probeUnauthenticatedEndpoint($endpoint);
		$checks = array_merge($checks, self::probeAuthenticatedEndpoint($endpoint, $userId));
		$checks[] = self::probeDiscovery(home_url('/.well-known/oauth-protected-resource'), 'well_known_pr', 'resource');
		$checks[] = self::probeDiscovery(home_url('/.well-known/oauth-authorization-server'), 'well_known_as', 'issuer');

		$counts = self::counts($checks);
		$summary = self::summary($counts);
		if (!AuditLog::append('doctor_run', ['summary' => $summary, 'counts' => $counts], $userId, null, null)) {
			$checks[] = self::result(
				'doctor_audit',
				__('Diagnostic audit record', 'llamly'),
				'fail',
				__('The diagnostic results were collected, but the required audit record could not be saved.', 'llamly'),
				[],
				'journal_unavailable',
				__('Check database storage and the Logs tab before relying on this report.', 'llamly')
			);
			$counts = self::counts($checks);
			$summary = 'fail';
		}

		return [
			'summary' => $summary,
			'counts' => $counts,
			'checks' => $checks,
			'browser_checks' => $report['browser_checks'],
			'environment' => $report['environment'],
			'generated_at' => gmdate('c'),
		];
	}

	/** @return list<array<string, mixed>> */
	private static function configurationChecks(): array
	{
		$settings = Settings::get();
		$valid = !empty($settings['_valid']);
		$home = home_url('/');
		$host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
		$scheme = strtolower((string) wp_parse_url($home, PHP_URL_SCHEME));
		$local = self::isLocalHost($host);
		$keys = CredentialStore::listForAdmin();
		$activeKeys = array_values(array_filter($keys, static fn(array $key): bool => empty($key['revoked_at'])));
		$oauthWindow = (int) ($settings['oauth_window_until'] ?? 0) > time();
		$restBlockers = self::restBlockers();
		$domainTables = [];
		foreach (\Llamly\Free\History\Engine::adapters() as $adapter) {
			$domainTables = array_merge($domainTables, $adapter->tables());
		}
		$domainTables = array_values(array_unique($domainTables));
		$preflight = MutationRunner::preflight($domainTables);
		$checks = [];

		$checks[] = self::result('settings', __('Llamly settings are valid', 'llamly'), $valid ? 'pass' : 'fail', $valid ? __('Stored settings pass validation.', 'llamly') : __('Stored settings are missing or invalid; MCP requests fail closed.', 'llamly'), [], $valid ? null : 'settings_unavailable', $valid ? null : __('Review settings and database availability before connecting a client.', 'llamly'));
		$enabled = $valid && !empty($settings['enabled']);
		$checks[] = self::result('server_enabled', __('MCP server enabled', 'llamly'), $enabled ? 'pass' : 'fail', $enabled ? __('Authenticated MCP requests are enabled.', 'llamly') : __('The MCP server is disabled or settings are invalid.', 'llamly'), [], $enabled ? null : 'server_disabled', $enabled ? null : __('Turn the server on in Settings.', 'llamly'));

		$https = $scheme === 'https';
		$httpsStatus = $https || ($local && !$oauthWindow) ? 'pass' : ($oauthWindow ? 'fail' : 'warn');
		$checks[] = self::result('https', __('Site uses HTTPS', 'llamly'), $httpsStatus, $https ? __('The WordPress site URL uses HTTPS.', 'llamly') : ($local ? __('Plain HTTP is allowed for this development host; remote OAuth clients still require public HTTPS.', 'llamly') : __('The WordPress site URL does not use HTTPS.', 'llamly')), [], $https ? null : 'https_required', $https ? null : __('Use a public HTTPS site for remote OAuth clients. Keep local HTTP limited to local development.', 'llamly'));

		$checks[] = self::result('public_hostname', __('Hostname suitable for cloud clients', 'llamly'), $local ? 'warn' : 'pass', $local ? __('This host appears local or private. Terminal clients may reach it, but cloud-hosted clients cannot be assumed to do so.', 'llamly') : __('The site hostname is not a recognized loopback or private IP literal. Public DNS reachability is not tested.', 'llamly'), ['host: ' . self::safeHost($host)], $local ? 'local_host' : null, $local ? __('Use terminal clients on a network that can reach this site, or provide a public HTTPS address for cloud clients.', 'llamly') : null);

		$pretty = (string) get_option('permalink_structure');
		// Since 1.1.1 the discovery paths are matched without rewrite rules, so plain permalinks alone are not a problem;
		// the discovery probes say whether the web server hands /.well-known/ to WordPress.
		$checks[] = self::result('permalinks', __('Permalinks', 'llamly'), 'pass', $pretty !== '' ? __('A pretty permalink structure is configured.', 'llamly') : __('Plain permalinks are in use. Llamly serves its OAuth discovery paths without rewrite rules.', 'llamly'), [$pretty !== '' ? 'permalinks: ' . $pretty : 'permalinks: plain'], null, null);

		$checks[] = self::result('api_keys', __('Bearer key available', 'llamly'), 'pass', $activeKeys ? sprintf(/* translators: %d: number of active API keys. */ _n('%d active API key is stored; its secret is shown only at creation.', '%d active API keys are stored; their secrets are shown only at creation.', count($activeKeys), 'llamly'), count($activeKeys)) : __('No access key yet. That is normal until you connect a terminal app; create one in Connections when you are ready. OAuth grants are listed separately.', 'llamly'), [], null, null);

		$oauthPossible = $https && !$local;
		$checks[] = self::result('oauth_prereqs', __('Remote OAuth prerequisites', 'llamly'), $oauthPossible ? 'pass' : 'warn', $oauthPossible ? __('The site uses HTTPS and does not use a recognized local hostname. Client reachability still needs a real OAuth test.', 'llamly') : __('Remote OAuth needs a publicly reachable HTTPS site; local or plain HTTP URLs cannot establish that.', 'llamly'), [], $oauthPossible ? null : 'oauth_public_https_required', $oauthPossible ? null : __('Use the OAuth registration window only after the remote client can reach this public HTTPS site.', 'llamly'));

		$checks[] = self::result('registration_window', __('New OAuth registration window', 'llamly'), 'pass', $oauthWindow ? sprintf(/* translators: %d: seconds until the registration window closes. */ __('Open for about %d more seconds. Existing grants are unaffected when it closes.', 'llamly'), max(0, (int) $settings['oauth_window_until'] - time())) : __('Closed. This is the safe default: new clients cannot register and existing OAuth grants keep working. Open the 30-minute window in Connections only when you are ready to connect a client.', 'llamly'), [], null, null);

		$checks[] = self::result('rest_blockers', __('Known REST-blocking plugins', 'llamly'), $restBlockers ? 'warn' : 'pass', $restBlockers ? sprintf(/* translators: %s: comma-separated plugin names. */ __('Security or cache plugins that sometimes restrict or cache REST requests are active: %s. This is only a heads-up; they do not necessarily block Llamly.', 'llamly'), implode(', ', $restBlockers)) : __('No known REST-blocking plugin from the Llamly check list is active.', 'llamly'), [], $restBlockers ? 'rest_blocker_plugin' : null, $restBlockers ? __('If connections fail, allow the llamly/v1 REST namespace and the /.well-known/ paths in these plugins and exclude them from caching.', 'llamly') : null);

		$abilitiesApi = \Llamly\Free\Core\Abilities::available();
		$abilitiesOn = !empty($settings['abilities_enabled']);
		$checks[] = self::result('abilities_bridge', __('WordPress Abilities API', 'llamly'), $abilitiesApi ? 'pass' : 'skip', $abilitiesApi
			? ($abilitiesOn
				? __('The Abilities API is present and Llamly exposes its tools as abilities. Every call still goes through the journal and the same safe mode and approval rules.', 'llamly')
				: __('The Abilities API is present. Exposing Llamly tools as abilities is off; turn it on in Settings if another integration needs it.', 'llamly'))
			: sprintf(/* translators: %s: WordPress version. */ __('This site runs WordPress %s, which has no Abilities API. MCP connections work normally.', 'llamly'), (string) get_bloginfo('version')));

		$checks[] = self::modulesCheck();

		$schemaOk = (int) get_option(Schema::OPTION_VERSION, 0) === Schema::VERSION;
		$checks[] = self::result('schema', __('Llamly database schema', 'llamly'), $schemaOk ? 'pass' : 'fail', $schemaOk ? __('All current Llamly tables are installed at the expected version.', 'llamly') : __('The installed Llamly schema does not match this plugin version.', 'llamly'), [], $schemaOk ? null : 'schema_mismatch', $schemaOk ? null : __('Use the schema verification action on the Llamly page before connecting.', 'llamly'));

		$preflightOk = $preflight === true;
		$checks[] = self::result('mutation_storage', __('Journal storage preflight', 'llamly'), $preflightOk ? 'pass' : 'fail', $preflightOk ? (\Llamly\Free\History\MutationRunner::writeEnabled()
				? __('The current database connection and required InnoDB tables pass the mutation preflight. Writing is enabled and every change is journaled.', 'llamly')
				: __('The database passes the mutation preflight, but writing is turned off on this site (LLAMLY_FREE_MUTATIONS_VERIFIED is false).', 'llamly')) : self::safeFailureMessage($preflight), $preflightOk ? ['engine: InnoDB'] : ['reason: ' . self::safeFailureCode($preflight)], $preflightOk ? null : 'storage_preflight_failed', $preflightOk ? null : __('Fix the requirement marked as failed below (or send this message to your host), then rerun the Doctor. Do not enable mutations manually.', 'llamly'));

		foreach (MutationRunner::requirements($domainTables) as $requirement) {
			$evidence = $requirement['evidence'] ?? [];
			array_unshift($evidence, 'value: ' . $requirement['value']);
			$checks[] = self::result($requirement['id'], $requirement['label'], $requirement['status'], $requirement['detail'], $evidence, $requirement['code'], $requirement['host']);
		}
		$checks[] = self::getLockCheck();
		$checks[] = self::dbVersionCheck();
		$checks[] = self::result('req_loopback', __('Loopback requests', 'llamly'), 'skip', __('Whether this server can call its own REST endpoint is tested by the connection checks, which need an administrator to run them.', 'llamly'), ['value: not tested yet'], null, __('Run the connection checks above. If they fail, ask your host to allow the site to request its own URL.', 'llamly'));

		return $checks;
	}

	private static function probeRestIndex(string $url): array
	{
		$response = self::getLocal($url);
		if (is_wp_error($response)) {
			return self::result('loopback_rest_index', __('REST API reachable from the server', 'llamly'), 'fail', __('The server could not request its own REST index.', 'llamly'), [], 'loopback_unreachable', __('Check DNS and loopback requests from the web server to this WordPress site.', 'llamly'));
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);
		$ok = $status === 200 && is_array($json) && in_array('llamly/v1', (array) ($json['namespaces'] ?? []), true);
		[$fingerprint, $fix] = $ok ? [null, null] : self::classify($response, $body);
		return self::result('loopback_rest_index', __('REST API reachable from the server', 'llamly'), $ok ? 'pass' : 'fail', $ok ? __('The REST index returns JSON and lists llamly/v1.', 'llamly') : sprintf(/* translators: %d: HTTP status code. */ __('The REST index did not expose the expected namespace (HTTP %d).', 'llamly'), $status), ['HTTP ' . $status, 'content-type: ' . self::contentType($response)], $fingerprint, $fix ?? __('Review rewrite rules, proxy rules, and REST access controls.', 'llamly'));
	}

	private static function probeUnauthenticatedEndpoint(string $url): array
	{
		$response = self::postLocal($url, self::initializeBody());
		if (is_wp_error($response)) {
			return self::result('loopback_endpoint_unauth', __('Unauthenticated MCP challenge from the server', 'llamly'), 'fail', __('The server could not POST to its own MCP endpoint.', 'llamly'), [], 'loopback_unreachable', __('Check DNS and loopback requests from the web server to this WordPress site.', 'llamly'));
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$hasChallenge = self::responseHeader($response, 'www-authenticate') !== '';
		$ok = $status === 401 && str_starts_with(ltrim($body), '{') && is_array(json_decode($body, true));
		$fingerprint = $ok && !$hasChallenge ? 'missing_www_authenticate' : null;
		$state = !$ok ? self::classify($response, $body) : [$fingerprint, $fingerprint ? __('A proxy may be stripping the WWW-Authenticate header.', 'llamly') : null];
		$overall = $ok ? ($hasChallenge ? 'pass' : 'warn') : 'fail';
		$detail = $ok ? ($hasChallenge ? __('The endpoint returns a JSON 401 with its Bearer discovery challenge.', 'llamly') : __('The endpoint returns a JSON 401, but the WWW-Authenticate header is missing.', 'llamly')) : sprintf(/* translators: %d: HTTP status code. */ __('Expected an unauthenticated JSON 401 response (HTTP %d received).', 'llamly'), $status);
		return self::result('loopback_endpoint_unauth', __('Unauthenticated MCP challenge from the server', 'llamly'), $overall, $detail, ['HTTP ' . $status, 'content-type: ' . self::contentType($response), 'www-authenticate: ' . ($hasChallenge ? 'present' : 'missing')], $state[0], $state[1] ?? __('Check whether a security plugin, WAF, or proxy intercepts the MCP route.', 'llamly'));
	}

	private static function probeAuthenticatedEndpoint(string $url, int $userId): array
	{
		$key = CredentialStore::createApiKey($userId, self::TEMPORARY_KEY_LABEL, 'read_only');
		if (is_wp_error($key)) {
			return [self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), 'fail', __('A temporary read-only key could not be created for the handshake check.', 'llamly'), [], 'temporary_key_unavailable', __('Check that the current administrator and credential table are valid.', 'llamly'))];
		}
		$id = (int) ($key['id'] ?? 0);
		$secret = (string) ($key['secret'] ?? '');
		$check = self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), 'fail', __('The authenticated initialize handshake did not complete.', 'llamly'), [], 'temporary_key_unavailable', __('Check the endpoint authentication path and the matching Logs event.', 'llamly'));
		$cleanupFailed = $id < 1;
		try {
			if ($id < 1 || $secret === '') {
				$check = self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), 'fail', __('The temporary read-only key was incomplete.', 'llamly'), [], 'temporary_key_unavailable', __('Check the credential store before retrying.', 'llamly'));
			} else {
				$response = self::postLocal($url, self::initializeBody(), ['Authorization' => 'Bearer ' . $secret]);
				if (is_wp_error($response)) {
					$check = self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), 'fail', __('The server could not complete an authenticated request to its own MCP endpoint.', 'llamly'), [], 'loopback_unreachable', __('Check the web server loopback route and the matching Logs event.', 'llamly'));
				} else {
					$status = (int) wp_remote_retrieve_response_code($response);
					$payload = json_decode((string) wp_remote_retrieve_body($response), true);
					$ok = $status === 200 && is_array($payload) && isset($payload['result']['serverInfo']['name']);
					[$fingerprint, $fix] = $ok ? [null, null] : self::classify($response, (string) wp_remote_retrieve_body($response));
					$check = self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), $ok ? 'pass' : 'fail', $ok ? __('A temporary read-only key completed initialize; the key is revoked after this check.', 'llamly') : sprintf(/* translators: %d: HTTP status code. */ __('The authenticated initialize handshake did not complete (HTTP %d).', 'llamly'), $status), ['HTTP ' . $status, 'content-type: ' . self::contentType($response)], $fingerprint, $fix ?? __('Review the endpoint authentication path and the matching Logs event.', 'llamly'));
				}
			}
		} catch (\Throwable $error) {
			$check = self::result('loopback_endpoint_auth', __('Authenticated MCP handshake from the server', 'llamly'), 'error', __('The authenticated handshake probe stopped unexpectedly. No response content or credential was recorded.', 'llamly'), [], 'probe_error', __('Review the server error log and retry the Doctor.', 'llamly'));
		} finally {
			unset($secret, $key);
			if ($id > 0) {
				$revoked = CredentialStore::revokeForAdmin($id);
				global $wpdb;
				$row = $wpdb->get_row($wpdb->prepare(
					'SELECT id, user_id, kind, label, revoked_at FROM %i WHERE id = %d',
					Schema::tableNames()['credentials'],
					$id
				), ARRAY_A);
				$identityMatches = is_array($row)
					&& (int) $row['id'] === $id
					&& (int) $row['user_id'] === $userId
					&& (string) $row['kind'] === 'api_key'
					&& (string) $row['label'] === self::TEMPORARY_KEY_LABEL;
				$revokeFailed = $revoked !== true
					&& !(is_wp_error($revoked) && $revoked->get_error_code() === 'credential_not_found');
				if ($revokeFailed || !$identityMatches || empty($row['revoked_at']) || $wpdb->last_error !== '') {
					$cleanupFailed = true;
					AuditLog::append('doctor_temporary_key_cleanup_failed', ['credential_id' => $id], $userId, $id, 'doctor-temp:' . $id);
				}
			}
		}
		$results = [$check];
		if ($cleanupFailed) {
			$results[] = self::result('loopback_temp_key_cleanup', __('Temporary Doctor key cleanup', 'llamly'), 'fail', __('The temporary read-only key could not be confirmed revoked. Revoke temporary Doctor credentials before using this site.', 'llamly'), [], 'temporary_key_cleanup_failed', __('Review Connections and revoke the temporary Doctor key, then rerun the Doctor.', 'llamly'));
		}
		return $results;
	}

	private static function probeDiscovery(string $url, string $id, string $requiredField): array
	{
		$response = self::getLocal($url);
		if (is_wp_error($response)) {
			return self::result($id, __('OAuth discovery reachable from the server', 'llamly'), 'fail', __('The server could not request the site discovery document.', 'llamly'), [], 'loopback_unreachable', __('Check root rewrite rules and loopback requests from the web server.', 'llamly'));
		}
		$status = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);
		$ok = $status === 200 && is_array($json) && isset($json[$requiredField]);
		[$fingerprint, $fix] = $ok ? [null, null] : self::classify($response, $body);
		$evidence = ['HTTP ' . $status, 'content-type: ' . self::contentType($response)];
		if (!$ok && (string) get_option('permalink_structure') === '' && ($status === 404 || in_array($fingerprint, ['html_intercept', 'unexpected_content_type', 'unexpected_response'], true))) {
			$evidence[] = 'permalinks: plain';
			$fix = __('Plain permalinks are in use and the web server did not pass this /.well-known/ address to WordPress (on Apache, plain permalinks write no rewrite rules). Choose any other structure in Settings > Permalinks, or have the server send /.well-known/oauth-* to index.php, then rerun the Doctor.', 'llamly');
		}
		return self::result($id, __('OAuth discovery reachable from the server', 'llamly'), $ok ? 'pass' : 'fail', $ok ? __('The discovery document is JSON with its required field.', 'llamly') : sprintf(/* translators: %d: HTTP status code. */ __('The discovery document did not return valid JSON with the expected field (HTTP %d).', 'llamly'), $status), $evidence, $fingerprint, $fix ?? __('Review root .well-known rewrites, cache rules, and the public HTTPS setup.', 'llamly'));
	}

	private static function getLocal(string $url): array|\WP_Error
	{
		if (!self::isAllowedSiteUrl($url)) {
			return new \WP_Error('doctor_target_rejected', 'doctor_target_rejected');
		}
		return wp_remote_get($url, [
			'timeout' => 5,
			'redirection' => 0,
			'limit_response_size' => self::MAX_GET_RESPONSE_BYTES,
			'headers' => ['Accept' => 'application/json'],
		]);
	}

	private static function postLocal(string $url, string $body, array $headers = []): array|\WP_Error
	{
		if (!self::isAllowedSiteUrl($url)) {
			return new \WP_Error('doctor_target_rejected', 'doctor_target_rejected');
		}
		return wp_remote_post($url, [
			'timeout' => 5,
			'redirection' => 0,
			'limit_response_size' => self::MAX_POST_RESPONSE_BYTES,
			'headers' => array_merge(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $headers),
			'body' => $body,
		]);
	}

	private static function isAllowedSiteUrl(string $url): bool
	{
		$target = wp_parse_url($url);
		$site = wp_parse_url(home_url('/'));
		$siteUrl = wp_parse_url(site_url('/'));
		if (!$target || !$site || empty($target['host']) || empty($site['host'])
			|| isset($target['user']) || isset($target['pass']) || isset($target['fragment'])
			|| !in_array(strtolower((string) ($target['scheme'] ?? '')), ['http', 'https'], true)
		) {
			return false;
		}
		$origin = self::origin($target);
		return $origin !== null && ($origin === self::origin($site) || ($siteUrl && $origin === self::origin($siteUrl)));
	}

	private static function origin(array $parts): ?string
	{
		if (empty($parts['host']) || empty($parts['scheme'])) {
			return null;
		}
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower((string) $parts['host']);
		$port = isset($parts['port']) ? (int) $parts['port'] : null;
		$default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
		return $scheme . '://' . $host . ($port && !$default ? ':' . $port : '');
	}

	private static function isLocalHost(string $host): bool
	{
		$host = trim($host, '[]');
		if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
			return true;
		}
		return filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}

	private static function safeHost(string $host): string
	{
		return preg_match('/^[a-z0-9.\-:\[\]]{1,253}$/i', $host) ? $host : '[invalid host]';
	}

	private static function restBlockers(): array
	{
		$known = [
			'better-wp-security/better-wp-security.php' => 'Solid Security (iThemes Security)',
			'ithemes-security-pro/ithemes-security-pro.php' => 'Solid Security Pro',
			'disable-json-api/disable-json-api.php' => 'Disable REST API',
			'disable-wp-rest-api/disable-wp-rest-api.php' => 'Disable WP REST API',
			'wp-rest-api-controller/wp-rest-api-controller.php' => 'REST API Controller',
			'wordfence/wordfence.php' => 'Wordfence',
			'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
			'sucuri-scanner/sucuri.php' => 'Sucuri Security',
			'wp-hide-security-enhancer/wp-hide.php' => 'WP Hide & Security Enhancer',
			'wp-rocket/wp-rocket.php' => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-super-cache/wp-cache.php' => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
		];
		$active = (array) get_option('active_plugins', []);
		if (is_multisite()) {
			$active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
		}
		return array_values(array_intersect_key($known, array_flip($active)));
	}

	private static function initializeBody(): string
	{
		return wp_json_encode([
			'jsonrpc' => '2.0',
			'id' => 'doctor-' . wp_generate_uuid4(),
			'method' => 'initialize',
			'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'llamly-doctor', 'version' => LLAMLY_FREE_VERSION]],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
	}

	private static function contentType(array $response): string
	{
		$value = self::responseHeader($response, 'content-type');
		return preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+(?:\s*;\s*charset=[a-z0-9._-]+)?$/i', $value) ? $value : 'unknown';
	}

	private static function responseHeader(array $response, string $name): string
	{
		$value = wp_remote_retrieve_header($response, $name);
		if (is_array($value)) {
			$value = implode(', ', array_map(static fn($item): string => is_scalar($item) ? (string) $item : '', $value));
		}
		return is_scalar($value) ? (string) $value : '';
	}

	private static function classify(array $response, string $body): array
	{
		$headers = wp_remote_retrieve_headers($response);
		$status = (int) wp_remote_retrieve_response_code($response);
		$contentType = self::contentType($response);
		$headerValues = is_array($headers) ? $headers : ($headers instanceof \Traversable ? iterator_to_array($headers) : []);
		$headerValues = array_change_key_case($headerValues, CASE_LOWER);
		$headerNames = array_keys($headerValues);
		$serverValue = $headerValues['server'] ?? '';
		$server = strtolower(is_scalar($serverValue) ? (string) $serverValue : '');
		$bodyStart = substr($body, 0, 512);
		$basicAuth = $status === 401 && stripos(self::responseHeader($response, 'www-authenticate'), 'basic') !== false;
		if ($basicAuth) {
			return ['basic_auth_interference', __('The response advertises HTTP Basic authentication before reaching the Llamly endpoint.', 'llamly')];
		}
		if (strpos($server, 'cloudflare') !== false || preg_match('/just a moment|cf-chl|challenge-platform/i', $bodyStart)) {
			return ['cloudflare_challenge', __('Cloudflare challenged the diagnostic request before it reached WordPress.', 'llamly')];
		}
		if (in_array($status, [403, 406], true) && preg_match('/mod_security|modsecurity|not acceptable/i', $bodyStart)) {
			return ['modsecurity', __('A ModSecurity rule rejected the JSON request before it reached the MCP endpoint.', 'llamly')];
		}
		if (strpos($contentType, 'text/html') === 0 || preg_match('/^\s*<(?:!doctype\s+html|html|head|body)\b/i', $bodyStart)) {
			return ['html_intercept', __('The site returned HTML where the Doctor expected JSON; a login page, WAF, or proxy may have intercepted the request.', 'llamly')];
		}
		if (preg_match('/^(?:\xEF\xBB\xBF|\s+)[\{\[]/', $bodyStart)) {
			return ['dirty_json', __('The JSON response has a byte-order mark or leading whitespace before its first object/array byte.', 'llamly')];
		}
		$wafHeaders = array_intersect($headerNames, ['cf-ray', 'x-sucuri-id', 'x-waf-event', 'x-cache', 'x-proxy-cache', 'x-akamai-transformed']);
		if ($wafHeaders || in_array($status, [403, 429, 503], true)) {
			return ['waf_or_cache_interference', __('A WAF, cache, or reverse proxy may have intercepted the request.', 'llamly')];
		}
		if (stripos($contentType, 'application/json') === false && $status >= 200 && $status < 400) {
			return ['unexpected_content_type', __('The response content type is not JSON.', 'llamly')];
		}
		if (json_decode($body, true) === null && trim($body) !== 'null') {
			return ['invalid_json', __('The response body is not valid JSON.', 'llamly')];
		}
		if (in_array('age', $headerNames, true) || in_array('x-cache', $headerNames, true) || in_array('cf-cache-status', $headerNames, true)) {
			return ['cache_interference', __('A cache header is present on a dynamic API response; verify this path is excluded from caching.', 'llamly')];
		}
		return ['unexpected_response', null];
	}

	/** Add-on modules: active ones (id, version, API, status), refused ones with the reason, inactive ones with history. */
	private static function modulesCheck(): array
	{
		$report = \Llamly\Free\Modules\ModuleRegistry::report();
		$evidence = ['module API: ' . $report['api']];
		foreach ($report['active'] as $module) {
			$evidence[] = 'active: ' . $module['id'] . ' ' . $module['version'] . ' (API ' . $module['api'] . ')' . ($module['status'] !== '' ? ', ' . $module['status'] : '');
		}
		foreach ($report['refused'] as $module) {
			$evidence[] = 'refused: ' . ($module['id'] !== '' ? $module['id'] : '?') . ($module['version'] !== '' ? ' ' . $module['version'] : '') . ' (' . $module['reason'] . ')';
		}
		foreach ($report['inactive'] as $module) {
			$evidence[] = 'inactive: ' . $module['id'] . ($module['version'] !== '' ? ' ' . $module['version'] : '');
		}
		if ($report['refused']) {
			return self::result('modules', __('Add-on modules', 'llamly'), 'warn', sprintf(/* translators: %d: number of refused add-on modules. */ _n('%d add-on module was refused and is not loaded. Llamly and the other add-ons keep working.', '%d add-on modules were refused and are not loaded. Llamly and the other add-ons keep working.', count($report['refused']), 'llamly'), count($report['refused'])), $evidence, 'module_refused', __('Update the add-on and Llamly to compatible versions, or contact the add-on author with the reason shown here.', 'llamly'));
		}
		return self::result('modules', __('Add-on modules', 'llamly'), 'pass', $report['active']
			? sprintf(/* translators: %d: number of active add-on modules. */ _n('%d add-on module is active. Its changes use the same journal, safe mode and undo as Llamly.', '%d add-on modules are active. Their changes use the same journal, safe mode and undo as Llamly.', count($report['active']), 'llamly'), count($report['active']))
			: __('No add-on module is active.', 'llamly'), $evidence);
	}

	private static function getLockCheck(): array
	{
		global $wpdb;
		$name = 'llamly_doctor_' . substr(md5(wp_generate_uuid4()), 0, 16);
		$got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
		$ok = (string) $got === '1';
		if ($ok) {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
		}
		return self::result('req_get_lock', __('Named database locks', 'llamly'), $ok ? 'pass' : 'fail', $ok ? __('GET_LOCK works, so Llamly can serialize writes per object.', 'llamly') : __('GET_LOCK did not grant a lock, so Llamly cannot serialize writes per object.', 'llamly'), ['value: GET_LOCK=' . ($got === null ? 'null' : (string) $got)], $ok ? null : 'get_lock_unavailable', $ok ? null : __('Ask your host to allow GET_LOCK() for the WordPress database user (it is blocked by some proxies and managed databases).', 'llamly'));
	}

	private static function dbVersionCheck(): array
	{
		global $wpdb;
		$raw = (string) $wpdb->get_var('SELECT VERSION()');
		$version = preg_match('/^\d+(?:\.\d+){0,2}/', $raw, $match) ? $match[0] : '';
		$maria = stripos($raw, 'mariadb') !== false;
		$ok = $version !== '' && version_compare($version, $maria ? '10.3.0' : '5.7.8', '>=');
		$label = $version !== '' ? ($maria ? 'MariaDB ' : 'MySQL ') . $version : __('unreadable', 'llamly');
		return self::result('req_db_version', __('Database version', 'llamly'), $ok ? 'pass' : ($version === '' ? 'warn' : 'fail'), $ok
			? __('The database version is supported (MySQL 5.7.8+ or MariaDB 10.3+).', 'llamly')
			: __('Llamly needs MySQL 5.7.8 or newer, or MariaDB 10.3 or newer.', 'llamly'), ['value: ' . $label], $ok ? null : 'db_version_unsupported', $ok ? null : __('Ask your host to upgrade the database to MySQL 5.7.8+ or MariaDB 10.3+.', 'llamly'));
	}

	private static function safeFailureMessage(mixed $preflight): string
	{
		if (is_wp_error($preflight)) {
			return wp_strip_all_tags($preflight->get_error_message());
		}
		return __('The database does not pass the current journal and transaction preflight.', 'llamly');
	}

	private static function safeFailureCode(mixed $preflight): string
	{
		if (is_wp_error($preflight)) {
			return sanitize_key($preflight->get_error_code());
		}
		return 'unavailable';
	}

	private static function environment(): array
	{
		global $wpdb;
		$dbVersion = '';
		$dbServer = $wpdb->get_var('SELECT VERSION()');
		if (is_string($dbServer)) {
			$dbVersion = preg_match('/^[A-Za-z0-9.\-]+/', $dbServer, $match) ? $match[0] : 'unknown';
		}
		return [
			'wordpress' => (string) get_bloginfo('version'),
			'php' => PHP_VERSION,
			'database' => $dbVersion,
			'host' => self::safeHost(strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))),
			'server' => self::safeServer(sanitize_text_field(wp_unslash((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')))),
			'plugin_version' => LLAMLY_FREE_VERSION,
			'write_enabled' => MutationRunner::writeEnabled(),
		];
	}

	private static function safeServer(string $server): string
	{
		$server = preg_replace('/[^A-Za-z0-9._+()\/-]/', '', $server) ?? '';
		return substr($server, 0, 128);
	}

	private static function result(string $id, string $label, string $status, string $detail, array $evidence = [], ?string $fingerprint = null, ?string $fix = null): array
	{
		return [
			'id' => $id,
			'label' => $label,
			'status' => $status,
			'detail' => $detail,
			'evidence' => array_values($evidence),
			'fingerprint' => $fingerprint,
			'fix' => $fix,
		];
	}

	private static function counts(array $checks): array
	{
		$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'error' => 0, 'pending' => 0];
		foreach ($checks as $check) {
			$status = (string) ($check['status'] ?? 'error');
			if (array_key_exists($status, $counts)) {
				++$counts[$status];
			}
		}
		return $counts;
	}

	private static function summary(array $counts): string
	{
		return ($counts['fail'] ?? 0) > 0 || ($counts['error'] ?? 0) > 0 ? 'fail' : ((($counts['warn'] ?? 0) > 0) ? 'warn' : 'pass');
	}
}
