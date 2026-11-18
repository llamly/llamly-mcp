<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\Schema;

final class RateLimiter
{
	public static function allow(string $subject, int $limit): bool
	{
		global $wpdb;
		$table = Schema::tableNames()['rate_limits'];
		$window = gmdate('Y-m-d H:i:s', intdiv(time(), 60) * 60);
		$inserted = $wpdb->query($wpdb->prepare(
			'INSERT INTO %i (subject, window_start, hits) VALUES (%s, %s, 1)
			 ON DUPLICATE KEY UPDATE hits = IF(window_start < VALUES(window_start), 1, hits + 1),
			 window_start = GREATEST(window_start, VALUES(window_start))',
			$table,
			$subject,
			$window
		));
		if ($inserted === false) {
			return false;
		}
		$hits = $wpdb->get_var($wpdb->prepare('SELECT hits FROM %i WHERE subject = %s', $table, $subject));
		return $hits !== null && (int) $hits <= $limit;
	}

	public static function remoteAddress(\WP_REST_Request $request): string
	{
		$address = sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
		if ($address === '' || !filter_var($address, FILTER_VALIDATE_IP)) {
			return 'unknown';
		}
		return $address;
	}
}
