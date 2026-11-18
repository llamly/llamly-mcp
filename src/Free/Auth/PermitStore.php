<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Auth;

use Llamly\Free\Core\AuditLog;
use Llamly\Free\Core\Schema;

/**
 * Single-use human approvals. Only an authenticated administrator in the Llamly admin UI can issue one;
 * the agent never emits its own permit. Consumption happens inside the mutation transaction so a failed
 * write leaves the permit unused.
 */
final class PermitStore
{
	public const TTL_SECONDS = 300;

	/** @return array{permit:string,expires_at:string}|\WP_Error */
	public static function issue(int $actorUserId, string $tool, string $objectType, int $objectId, string $operationId, string $expectedHash): array|\WP_Error
	{
		global $wpdb;
		if ($actorUserId < 1 || !user_can($actorUserId, 'manage_options') || $objectId < 1 || $operationId === '' || $expectedHash === '') {
			return new \WP_Error('forbidden', __('Only an administrator can approve this change.', 'llamly'), ['status' => 403]);
		}
		$token = 'llp_' . bin2hex(random_bytes(24));
		$now = time();
		$expiresAt = gmdate('Y-m-d H:i:s', $now + self::TTL_SECONDS);
		if ($wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('journal_unavailable', __('The approval could not be recorded.', 'llamly'), ['status' => 503]);
		}
		$inserted = $wpdb->insert(Schema::tableNames()['permits'], [
			'permit_hash' => hash('sha256', $token),
			'credential_id' => null,
			'credential_key' => 'admin:' . $actorUserId,
			'user_id' => $actorUserId,
			'tool' => $tool,
			'object_type' => $objectType,
			'object_id' => $objectId,
			'operation_id' => $operationId,
			'expected_hash' => hash('sha256', $expectedHash),
			'issued_at' => gmdate('Y-m-d H:i:s', $now),
			'expires_at' => $expiresAt,
		], ['%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
		$audited = $inserted === 1 && AuditLog::append('permit_issued', [
			'tool' => $tool,
			'object_type' => $objectType,
			'object_id' => $objectId,
			'operation_id' => $operationId,
		], $actorUserId, null, 'admin:' . $actorUserId);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('journal_unavailable', __('The approval could not be recorded.', 'llamly'), ['status' => 503]);
		}
		return ['permit' => $token, 'expires_at' => $expiresAt];
	}

	/**
	 * Consume a permit atomically. Must run inside the caller's open transaction.
	 */
	public static function consume(string $token, int $actorUserId, string $tool, string $objectType, int $objectId, string $operationId, string $expectedHash): bool
	{
		global $wpdb;
		if (!preg_match('/^llp_[a-f0-9]{48}$/', $token)) {
			return false;
		}
		$table = Schema::tableNames()['permits'];
		$updated = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET consumed_at = %s
			 WHERE permit_hash = %s AND consumed_at IS NULL AND expires_at > %s
				AND user_id = %d AND tool = %s AND object_type = %s AND object_id = %d
				AND operation_id = %s AND expected_hash = %s',
			$table,
			gmdate('Y-m-d H:i:s'),
			hash('sha256', $token),
			gmdate('Y-m-d H:i:s'),
			$actorUserId,
			$tool,
			$objectType,
			$objectId,
			$operationId,
			hash('sha256', $expectedHash)
		));
		return $updated === 1 && $wpdb->last_error === '';
	}

	/**
	 * An administrator approves one agent request (an operation stopped at awaiting_approval). The approval is bound to
	 * the requesting credential, the operation, its tool and object, and the state the agent expected; it is consumed
	 * inside that operation's own transaction when the agent repeats the call with the same idempotency key.
	 *
	 * @param array<string, mixed> $operation
	 * @return array{expires_at:string}|\WP_Error
	 */
	public static function approveOperation(int $actorUserId, array $operation, int $ttl): array|\WP_Error
	{
		global $wpdb;
		if ($actorUserId < 1 || !user_can($actorUserId, 'manage_options')) {
			return new \WP_Error('forbidden', __('Only an administrator can approve this change.', 'llamly'), ['status' => 403]);
		}
		$now = time();
		$expiresAt = gmdate('Y-m-d H:i:s', $now + max(60, $ttl));
		if ($wpdb->query('START TRANSACTION') === false) {
			return new \WP_Error('journal_unavailable', __('The approval could not be recorded.', 'llamly'), ['status' => 503]);
		}
		$inserted = $wpdb->insert(Schema::tableNames()['permits'], [
			'permit_hash' => hash('sha256', 'approval:' . bin2hex(random_bytes(24))),
			'credential_id' => $operation['credential_id'] === null ? null : (int) $operation['credential_id'],
			'credential_key' => (string) $operation['credential_key'],
			'user_id' => (int) $operation['user_id'],
			'tool' => (string) $operation['tool'],
			'object_type' => (string) $operation['object_type'],
			'object_id' => (int) $operation['object_id'],
			'operation_id' => (string) $operation['id'],
			'expected_hash' => hash('sha256', (string) $operation['before_hash']),
			'issued_at' => gmdate('Y-m-d H:i:s', $now),
			'expires_at' => $expiresAt,
		], ['%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
		$audited = $inserted === 1 && AuditLog::append('approval_granted', [
			'operation_id' => (string) $operation['id'],
			'tool' => (string) $operation['tool'],
			'object_type' => (string) $operation['object_type'],
			'object_id' => (int) $operation['object_id'],
			'requested_by' => (string) $operation['credential_key'],
		], $actorUserId, null, 'admin:' . $actorUserId);
		if (!$audited || $wpdb->query('COMMIT') === false || $wpdb->last_error !== '') {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('journal_unavailable', __('The approval could not be recorded.', 'llamly'), ['status' => 503]);
		}
		return ['expires_at' => $expiresAt];
	}

	public static function hasApproval(string $operationId, string $credentialKey, string $expectedHash): bool
	{
		global $wpdb;
		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			 WHERE operation_id = %s AND credential_key = %s AND expected_hash = %s AND consumed_at IS NULL AND expires_at > %s",
			Schema::tableNames()['permits'],
			$operationId,
			$credentialKey,
			hash('sha256', $expectedHash),
			gmdate('Y-m-d H:i:s')
		));
		return (int) $found > 0 && $wpdb->last_error === '';
	}

	/** Must run inside the operation's open transaction. */
	public static function consumeApproval(string $operationId, string $credentialKey, string $expectedHash): bool
	{
		global $wpdb;
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE %i SET consumed_at = %s
			 WHERE operation_id = %s AND credential_key = %s AND expected_hash = %s AND consumed_at IS NULL AND expires_at > %s
			 ORDER BY id ASC LIMIT 1",
			Schema::tableNames()['permits'],
			gmdate('Y-m-d H:i:s'),
			$operationId,
			$credentialKey,
			hash('sha256', $expectedHash),
			gmdate('Y-m-d H:i:s')
		));
		return $updated === 1 && $wpdb->last_error === '';
	}
}
