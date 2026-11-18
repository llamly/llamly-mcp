<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Core;

final class AuditLog
{
	public const RESULTS = ['ok', 'denied', 'rate', 'error'];

	/**
	 * @param array{result?:string,tool?:string,duration_ms?:int,ip?:string} $request Columns of the per-request log;
	 *        events without them get a result derived from the event name.
	 */
	public static function append(string $event, array $context, ?int $userId, ?int $credentialId, ?string $credentialKey, array $request = []): bool
	{
		global $wpdb;
		$event = sanitize_key($event);
		if ($event === '' || strlen($event) > 96) {
			return false;
		}
		$redacted = self::redact($context);
		$json = wp_json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || strlen($json) > 16000) {
			return false;
		}
		$result = (string) ($request['result'] ?? self::defaultResult($event, $context));
		$tool = isset($request['tool']) ? substr(sanitize_key((string) $request['tool']), 0, 64) : null;
		$ip = isset($request['ip']) && filter_var((string) $request['ip'], FILTER_VALIDATE_IP) ? (string) $request['ip'] : null;
		$inserted = $wpdb->insert(Schema::tableNames()['audit'], [
			'user_id' => $userId,
			'credential_id' => $credentialId,
			'credential_key' => $credentialKey,
			'event' => $event,
			'result' => in_array($result, self::RESULTS, true) ? $result : 'ok',
			'tool' => $tool === '' ? null : $tool,
			'duration_ms' => isset($request['duration_ms']) ? max(0, (int) $request['duration_ms']) : null,
			'ip' => $ip,
			'context' => $json,
			'created_at' => gmdate('Y-m-d H:i:s'),
		], ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
		return $inserted === 1;
	}

	private static function defaultResult(string $event, array $context): string
	{
		if ($event === 'auth_failed') {
			return !empty($context['rate_limited']) ? 'rate' : 'denied';
		}
		if ($event === 'tool_denied') {
			return 'denied';
		}
		return str_ends_with($event, '_failed') ? 'error' : 'ok';
	}

	/**
	 * @param array{event?:string,result?:string,credential?:string,q?:string,date_from?:string,date_to?:string} $filters `result=denied` also matches
	 *        rate-limit refusals, as in the Logs filter chips.
	 * @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public static function list(int $page, int $perPage, array $filters = []): array
	{
		global $wpdb;
		$table = Schema::tableNames()['audit'];
		$page = max(1, $page);
		$perPage = max(10, min(100, $perPage));
		[$event, $credential, $like, $from, $to, $result] = self::filterArgs($filters);

		// One constant WHERE: each filter is "(%s = '' OR ...)", so an empty filter matches every row.
		$total = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE (%s = '' OR event = %s) AND (%s = '' OR credential_key = %s) AND (%s = '' OR tool LIKE %s OR event LIKE %s) AND (%s = '' OR created_at >= %s) AND (%s = '' OR created_at <= %s) AND (%s = '' OR result = %s OR (%s = 'denied' AND result = 'rate'))",
			$table, $event, $event, $credential, $credential, $like, $like, $like, $from, $from, $to, $to, $result, $result, $result
		));
		$countRows = $wpdb->get_results($wpdb->prepare(
			"SELECT result, COUNT(*) AS n FROM %i WHERE (%s = '' OR event = %s) AND (%s = '' OR credential_key = %s) AND (%s = '' OR tool LIKE %s OR event LIKE %s) AND (%s = '' OR created_at >= %s) AND (%s = '' OR created_at <= %s) AND (%s = '' OR result = %s OR (%s = 'denied' AND result = 'rate')) GROUP BY result",
			$table, $event, $event, $credential, $credential, $like, $like, $like, $from, $from, $to, $to, '', '', ''
		), ARRAY_A);
		$counts = ['all' => 0, 'ok' => 0, 'denied' => 0, 'error' => 0];
		foreach (is_array($countRows) ? $countRows : [] as $row) {
			$n = (int) $row['n'];
			$counts['all'] += $n;
			$bucket = $row['result'] === 'rate' ? 'denied' : (string) $row['result'];
			if (isset($counts[$bucket])) {
				$counts[$bucket] += $n;
			}
		}

		$offset = ($page - 1) * $perPage;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, user_id, credential_id, credential_key, event, result, tool, duration_ms, ip, context, created_at FROM %i WHERE (%s = '' OR event = %s) AND (%s = '' OR credential_key = %s) AND (%s = '' OR tool LIKE %s OR event LIKE %s) AND (%s = '' OR created_at >= %s) AND (%s = '' OR created_at <= %s) AND (%s = '' OR result = %s OR (%s = 'denied' AND result = 'rate')) ORDER BY id DESC LIMIT %d OFFSET %d",
			$table, $event, $event, $credential, $credential, $like, $like, $like, $from, $from, $to, $to, $result, $result, $result, $perPage, $offset
		), ARRAY_A);
		$items = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$context = json_decode((string) $row['context'], true);
			$items[] = [
				'id' => (int) $row['id'],
				'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
				'credential' => $row['credential_key'] ?: ($row['credential_id'] ? 'key:' . (int) $row['credential_id'] : null),
				'event' => (string) $row['event'],
				'result' => (string) ($row['result'] ?? 'ok'),
				'tool' => $row['tool'] === null ? null : (string) $row['tool'],
				'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
				'ip' => $row['ip'] === null ? null : (string) $row['ip'],
				'context' => is_array($context) ? $context : [],
				'created_at' => (string) $row['created_at'],
			];
		}
		return ['items' => $items, 'total' => $total, 'counts' => $counts];
	}

	/**
	 * Filter values for the constant WHERE of list(): event, credential, LIKE pattern, from, to and result. An empty
	 * string turns that filter off; `result=denied` matches result 'denied' and 'rate'.
	 *
	 * @return array{0:string,1:string,2:string,3:string,4:string,5:string}
	 */
	private static function filterArgs(array $filters): array
	{
		global $wpdb;
		$event = sanitize_key((string) ($filters['event'] ?? ''));
		$credential = (string) ($filters['credential'] ?? '');
		if (!preg_match(\Llamly\Free\History\Engine::CREDENTIAL_KEY_PATTERN, $credential)) {
			$credential = '';
		}
		$q = trim((string) ($filters['q'] ?? ''));
		$like = $q !== '' ? '%' . $wpdb->esc_like(substr($q, 0, 64)) . '%' : '';
		$dates = [];
		foreach (['date_from' => ' 00:00:00', 'date_to' => ' 23:59:59'] as $key => $time) {
			$date = (string) ($filters[$key] ?? '');
			$dates[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . $time : '';
		}
		$result = (string) ($filters['result'] ?? '');
		$result = in_array($result, ['ok', 'denied', 'error'], true) ? $result : '';
		return [$event, $credential, $like, $dates['date_from'], $dates['date_to'], $result];
	}

	public static function clearExpired(int $retentionDays): bool
	{
		global $wpdb;
		$retentionDays = max(1, min(365, $retentionDays));
		$cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * DAY_IN_SECONDS);
		return $wpdb->query($wpdb->prepare(
			'DELETE FROM %i WHERE created_at < %s',
			Schema::tableNames()['audit'],
			$cutoff
		)) !== false;
	}

	private static function redact(array $value): array
	{
		$clean = [];
		foreach ($value as $key => $item) {
			$keyName = is_string($key) ? $key : (string) $key;
			if (preg_match('/(?:secret|token|authorization|password|api[_-]?key|code[_-]?verifier|permit|bearer)/i', $keyName)) {
				$clean[$key] = '[redacted]';
			} elseif (is_array($item)) {
				$clean[$key] = self::redact($item);
			} elseif (is_string($item)) {
				$clean[$key] = self::redactString($item);
			} elseif (is_scalar($item) || $item === null) {
				$clean[$key] = $item;
			} else {
				$clean[$key] = '[omitted]';
			}
		}
		return $clean;
	}

	private static function redactString(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		// Llamly API keys (llm_), OAuth access (llat_) and refresh (llrt_) tokens, and
		// authorization codes (llc_ + 64 hex; client IDs are llc_ + 48 hex and stay visible).
		$value = preg_replace('/(?:llm|llat|llrt|llp)_[A-Fa-f0-9]{32,}/', '[redacted]', $value) ?? '[redacted]';
		$value = preg_replace('/llc_[A-Fa-f0-9]{64,}/', '[redacted]', $value) ?? '[redacted]';
		$value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i', 'Bearer [redacted]', $value) ?? '[redacted]';
		return $value;
	}
}
