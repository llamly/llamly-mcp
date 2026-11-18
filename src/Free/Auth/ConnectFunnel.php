<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Llamly's own audit table; a cached read would hide the step that just happened.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;
use Llamly\Free\Core\Settings;

/**
 * LLP-467: the "Add to <app>" attempt and how far the app got, from the site's own audit log. Local only: nothing leaves
 * the site.
 *
 * A click on "Add to Claude" (or "Open ChatGPT") records `connect_started` and keeps one attempt in an option. The
 * stage comes from the OAuth audit events written after it, each tied to the attempt by identifiers the OAuth server
 * already records (no new public field):
 * - registered: `oauth_client_registered` of a client of that app. Registration is anonymous, so this step only says
 *   that a client with that app's redirect address registered (the page words it that way), never that the app did;
 * - approved: `oauth_grant_created` for a client of that app, by the administrator who clicked;
 * - connected: `oauth_tokens_issued` (authorization code) for one of those grants (`grant_id`), same user.
 * "A client of that app" = every redirect URI stored for the client (oauth_clients.redirect_uris, the ones /authorize
 * enforces) is https on the app's own domains, so only that app can receive the codes. The client name is never used.
 * claude.ai and Claude Desktop share Claude's redirect domains, so their attempts are not told apart from each other.
 * `connection_id` names the connection row (`oauth:<grant_id>`) of the connected grant, so the page's "Connected"
 * status follows that connection and no other.
 * A client registered before the click (the connector was already added) has no registration event: its grant
 * implies the registered step. Events of other apps, of loopback clients or of other users never move the attempt.
 * When the connection window ends, or a new attempt starts, an attempt that did not connect is written to the log as
 * `connect_incomplete` with the stage where it stopped (result "error", so the Logs "Errors" filter finds it).
 */
final class ConnectFunnel
{
	public const OPTION = 'llamly_free_connect_attempt';
	public const CLIENTS = ['claude-ai', 'claude-desktop', 'chatgpt'];
	/** Seconds after the click without `oauth_client_registered` before the page says the app did not reach the site. */
	public const QUIET_SECONDS = 180;
	private const STEPS = [
		'registered' => 'oauth_client_registered',
		'approved' => 'oauth_grant_created',
		'connected' => 'oauth_tokens_issued',
	];
	/** Redirect domains that identify each app's OAuth clients (subdomains included). */
	private const APP_DOMAINS = [
		'claude-ai' => ['claude.ai', 'claude.com', 'anthropic.com'],
		'claude-desktop' => ['claude.ai', 'claude.com', 'anthropic.com'],
		'chatgpt' => ['chatgpt.com', 'openai.com'],
	];
	/** Upper bound of OAuth events read per check (registrations plus the clicking administrator's, 30 minutes). */
	private const MAX_EVENTS = 1000;

	/** Records the click. Settles the previous attempt first, so each attempt gets at most one outcome in the log. */
	public static function start(string $client, int $userId): bool
	{
		global $wpdb;
		if (!in_array($client, self::CLIENTS, true)) {
			return false;
		}
		self::settle(true);
		if (!AuditLog::append('connect_started', ['client' => $client], $userId > 0 ? $userId : null, null, null)) {
			return false;
		}
		return update_option(self::OPTION, [
			'client' => $client,
			'audit_id' => (int) $wpdb->insert_id,
			'started' => time(),
			'user_id' => $userId,
		], false);
	}

	/**
	 * The current attempt while the connection window is open, or null. A finished window settles the attempt.
	 *
	 * @return array{client:string,stage:string,started_at:string,registered_at:?string,approved_at:?string,connected_at:?string,connection_id:?string,seconds:int,quiet:bool}|null
	 */
	public static function status(): ?array
	{
		$attempt = self::attempt();
		if ($attempt === null) {
			return null;
		}
		if (!self::windowOpen()) {
			self::settle(false);
			return null;
		}
		$steps = self::steps($attempt);
		$stage = 'waiting';
		foreach (array_keys(self::STEPS) as $name) {
			if ($steps[$name] !== null) {
				$stage = $name;
			}
		}
		$seconds = max(0, time() - $attempt['started']);
		return [
			'client' => $attempt['client'],
			'stage' => $stage,
			'started_at' => gmdate('Y-m-d H:i:s', $attempt['started']),
			'registered_at' => $steps['registered'],
			'approved_at' => $steps['approved'],
			'connected_at' => $steps['connected'],
			'connection_id' => $steps['grant'] !== null ? 'oauth:' . $steps['grant'] : null,
			'seconds' => $seconds,
			'quiet' => $stage === 'waiting' && $seconds >= self::QUIET_SECONDS,
		];
	}

	/**
	 * Ends the attempt when its window is over (or always, with $force). An attempt that did not connect is written to
	 * the log with the stage where it stopped. Only the request that deletes the option writes the row.
	 */
	public static function settle(bool $force): void
	{
		$attempt = self::attempt();
		if ($attempt === null || (!$force && self::windowOpen())) {
			return;
		}
		if (!delete_option(self::OPTION)) {
			return;
		}
		$steps = self::steps($attempt);
		if ($steps['connected'] !== null) {
			return;
		}
		$stage = $steps['approved'] !== null ? 'approved' : ($steps['registered'] !== null ? 'registered' : 'waiting');
		AuditLog::append('connect_incomplete', [
			'client' => $attempt['client'],
			'stage' => $stage,
			'started_at' => gmdate('Y-m-d H:i:s', $attempt['started']),
		], $attempt['user_id'] > 0 ? $attempt['user_id'] : null, null, null, ['result' => 'error']);
	}

	/** Drops the attempt without a log row: used when the audit log that held its steps is cleared. */
	public static function forget(): void
	{
		delete_option(self::OPTION);
	}

	/** @return array{client:string,audit_id:int,started:int,user_id:int}|null */
	private static function attempt(): ?array
	{
		$raw = get_option(self::OPTION, null);
		if (!is_array($raw) || !in_array($raw['client'] ?? '', self::CLIENTS, true) || (int) ($raw['audit_id'] ?? 0) < 1) {
			return null;
		}
		return [
			'client' => (string) $raw['client'],
			'audit_id' => (int) $raw['audit_id'],
			'started' => (int) ($raw['started'] ?? 0),
			'user_id' => (int) ($raw['user_id'] ?? 0),
		];
	}

	private static function windowOpen(): bool
	{
		$settings = Settings::get();
		return (int) ($settings['oauth_window_until'] ?? 0) > time();
	}

	/**
	 * First time of each step after the click, counting only events of this attempt's app and administrator.
	 *
	 * @param array{client:string,audit_id:int,started:int,user_id:int} $attempt
	 * @return array{registered:?string,approved:?string,connected:?string,grant:?string}
	 */
	private static function steps(array $attempt): array
	{
		global $wpdb;
		$out = ['registered' => null, 'approved' => null, 'connected' => null, 'grant' => null];
		$events = array_values(self::STEPS);
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT id, event, user_id, context, created_at FROM %i WHERE id > %d AND event IN (%s, %s, %s) AND (event = %s OR user_id = %d) ORDER BY id LIMIT %d',
			Schema::tableNames()['audit'],
			$attempt['audit_id'],
			$events[0],
			$events[1],
			$events[2],
			$events[0],
			$attempt['user_id'],
			self::MAX_EVENTS
		), ARRAY_A);
		$userId = $attempt['user_id'];
		$ownClient = [];
		$grants = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$context = json_decode((string) $row['context'], true);
			$clientId = is_array($context) ? (string) ($context['client_id'] ?? '') : '';
			if ($clientId === '') {
				continue;
			}
			$ownClient[$clientId] ??= self::clientBelongsTo($clientId, $attempt['client']);
			if (!$ownClient[$clientId]) {
				continue;
			}
			$at = (string) $row['created_at'];
			$eventUser = (int) ($row['user_id'] ?? 0);
			if ($row['event'] === 'oauth_client_registered') {
				$out['registered'] ??= $at;
			} elseif ($row['event'] === 'oauth_grant_created' && $userId > 0 && $eventUser === $userId) {
				$grantId = (string) ($context['grant_id'] ?? '');
				if ($grantId !== '') {
					$grants[$grantId] = true;
					$out['approved'] ??= $at;
				}
			} elseif ($row['event'] === 'oauth_tokens_issued' && $eventUser === $userId
				&& isset($grants[(string) ($context['grant_id'] ?? '')]) && ($context['flow'] ?? '') === 'authorization_code'
			) {
				if ($out['connected'] === null) {
					$out['connected'] = $at;
					$out['grant'] = (string) $context['grant_id'];
				}
			}
		}
		// A client added before the click registers no event: its approval means it reached the site.
		if ($out['registered'] === null && $out['approved'] !== null) {
			$out['registered'] = $out['approved'];
		}
		return $out;
	}

	/** Every stored redirect URI of the client is https on one of the app's domains. */
	private static function clientBelongsTo(string $clientId, string $app): bool
	{
		global $wpdb;
		$domains = self::APP_DOMAINS[$app] ?? [];
		$raw = $wpdb->get_var($wpdb->prepare(
			'SELECT redirect_uris FROM %i WHERE client_id = %s',
			Schema::tableNames()['oauth_clients'],
			$clientId
		));
		$uris = is_string($raw) ? json_decode($raw, true) : null;
		if ($domains === [] || !is_array($uris) || $uris === []) {
			return false;
		}
		foreach ($uris as $uri) {
			$parts = is_string($uri) ? wp_parse_url($uri) : false;
			$host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
			if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '') {
				return false;
			}
			$match = false;
			foreach ($domains as $domain) {
				if ($host === $domain || str_ends_with($host, '.' . $domain)) {
					$match = true;
					break;
				}
			}
			if (!$match) {
				return false;
			}
		}
		return true;
	}
}
