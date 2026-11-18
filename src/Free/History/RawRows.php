<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

/**
 * Raw row helpers for adapters. They run inside the engine transaction and throw
 * MutationFailure on any database error, so a partial restore always rolls back.
 */
final class RawRows
{
	/**
	 * Capture meta rows of one object, ordered by meta id. $keys limits the capture to an allowlist; null captures all
	 * keys except those matching $exclude.
	 *
	 * @param list<string>|null $keys
	 * @return list<array{key:string,value:string}>|null
	 */
	public static function captureMeta(string $table, string $objectColumn, int $objectId, ?array $keys, string $exclude = '', bool $forUpdate = false): ?array
	{
		global $wpdb;
		$idColumn = self::metaIdColumn($table);
		if ($keys === null) {
			$rows = $forUpdate
				? $wpdb->get_results($wpdb->prepare('SELECT %i AS id, meta_key, meta_value FROM %i WHERE %i = %d ORDER BY %i ASC FOR UPDATE', $idColumn, $table, $objectColumn, $objectId, $idColumn), ARRAY_A)
				: $wpdb->get_results($wpdb->prepare('SELECT %i AS id, meta_key, meta_value FROM %i WHERE %i = %d ORDER BY %i ASC', $idColumn, $table, $objectColumn, $objectId, $idColumn), ARRAY_A);
		} elseif (!$keys) {
			return [];
		} else {
			$args = array_merge([$idColumn, $table, $objectColumn, $objectId], array_values($keys), [$idColumn]);
			$rows = $forUpdate
				? $wpdb->get_results($wpdb->prepare('SELECT %i AS id, meta_key, meta_value FROM %i WHERE %i = %d AND meta_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ') ORDER BY %i ASC FOR UPDATE', $args), ARRAY_A)
				: $wpdb->get_results($wpdb->prepare('SELECT %i AS id, meta_key, meta_value FROM %i WHERE %i = %d AND meta_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ') ORDER BY %i ASC', $args), ARRAY_A);
		}
		if (!is_array($rows) || $wpdb->last_error !== '') {
			return null;
		}
		$out = [];
		foreach ($rows as $row) {
			$key = (string) $row['meta_key'];
			if ($exclude !== '' && preg_match($exclude, $key)) {
				continue;
			}
			$out[] = ['key' => $key, 'value' => (string) $row['meta_value']];
		}
		return $out;
	}

	/**
	 * Replace the meta rows in scope with $rows (delete + insert, byte-exact values, no sanitize/slash filters).
	 *
	 * @param list<array{key:string,value:string}> $rows
	 * @param list<string>|null $keys Same scope used at capture; null means every key not matching $exclude.
	 */
	public static function restoreMeta(string $table, string $objectColumn, int $objectId, array $rows, ?array $keys, string $exclude = '', string $cacheGroup = ''): void
	{
		global $wpdb;
		$current = self::captureMeta($table, $objectColumn, $objectId, $keys, $exclude, true);
		if ($current === null) {
			throw new MutationFailure('journal_unavailable', esc_html__('Metadata could not be read for restore.', 'llamly'), 503);
		}
		if ($current === $rows) {
			return;
		}
		$scopeKeys = array_values(array_unique(array_merge(array_column($current, 'key'), array_column($rows, 'key'))));
		foreach ($scopeKeys as $key) {
			if ($wpdb->delete($table, [$objectColumn => $objectId, 'meta_key' => $key], ['%d', '%s']) === false) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('Metadata could not be restored.', 'llamly'), 503);
			}
		}
		foreach ($rows as $row) {
			if ($wpdb->insert($table, [$objectColumn => $objectId, 'meta_key' => $row['key'], 'meta_value' => $row['value']], ['%d', '%s', '%s']) !== 1) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('Metadata could not be restored.', 'llamly'), 503);
			}
		}
		if ($cacheGroup !== '') {
			wp_cache_delete($objectId, $cacheGroup);
		}
	}

	/**
	 * Re-insert a deleted row with its original primary key. An occupied key is a conflict, never a merge (MySQL may
	 * reuse AUTO_INCREMENT values after a restart).
	 *
	 * @param array<string, mixed> $row
	 */
	public static function reinsert(string $table, string $pkColumn, array $row): void
	{
		global $wpdb;
		$pk = $row[$pkColumn] ?? null;
		if (!is_numeric($pk) || (int) $pk < 1) {
			throw new MutationFailure('journal_unavailable', esc_html__('The journaled row has no identifier.', 'llamly'), 503);
		}
		$taken = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d FOR UPDATE', $table, $pkColumn, (int) $pk));
		if ($wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The row identifier could not be checked.', 'llamly'), 503);
		}
		if ($taken > 0) {
			throw new MutationFailure('undo_conflict', esc_html__('Another item now uses the original identifier, so it cannot be restored.', 'llamly'), 409);
		}
		$formats = [];
		foreach ($row as $value) {
			$formats[] = is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s');
		}
		if ($wpdb->insert($table, $row, $formats) !== 1 || $wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The item could not be restored with its original identifier.', 'llamly'), 503);
		}
	}

	/** @return array<string, mixed>|null One full row as stored, for the retain scope of a delete. */
	public static function row(string $table, string $pkColumn, int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE %i = %d FOR UPDATE', $table, $pkColumn, $id), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE %i = %d', $table, $pkColumn, $id), ARRAY_A);
		return is_array($row) && $wpdb->last_error === '' ? $row : null;
	}

	private static function metaIdColumn(string $table): string
	{
		global $wpdb;
		return $table === $wpdb->usermeta ? 'umeta_id' : 'meta_id';
	}
}
