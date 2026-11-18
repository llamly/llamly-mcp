<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

use Llamly\Free\Core\Schema;

final class ObjectVersionStore
{
	/** @return array{version:int,pending_op:?string,last_op:?string}|null */
	public static function get(string $type, int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		$table = Schema::tableNames()['object_versions'];
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare('SELECT version, pending_op, last_op FROM %i WHERE object_type = %s AND object_id = %d FOR UPDATE', $table, $type, $id), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare('SELECT version, pending_op, last_op FROM %i WHERE object_type = %s AND object_id = %d', $table, $type, $id), ARRAY_A);
		if (!is_array($row)) {
			return null;
		}
		return [
			'version' => (int) $row['version'],
			'pending_op' => $row['pending_op'] === null ? null : (string) $row['pending_op'],
			'last_op' => $row['last_op'] === null ? null : (string) $row['last_op'],
		];
	}

	public static function ensure(string $type, int $id): bool
	{
		global $wpdb;
		$table = Schema::tableNames()['object_versions'];
		$result = $wpdb->query($wpdb->prepare(
			'INSERT INTO %i (object_type, object_id, version, updated_at) VALUES (%s, %d, 0, %s)
			 ON DUPLICATE KEY UPDATE object_id = VALUES(object_id)',
			$table,
			$type,
			$id,
			gmdate('Y-m-d H:i:s')
		));
		return $result !== false && $wpdb->last_error === '';
	}

	public static function editorToken(int $postId): string
	{
		$version = self::get('post', $postId);
		return $postId . '.' . ($version['version'] ?? 0);
	}
}
