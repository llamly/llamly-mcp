<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History;

final class ObjectMutex
{
	/** @var array<string, true> */
	private static array $held = [];

	public static function name(string $type, int $id): string
	{
		global $wpdb;
		$identity = defined('DB_NAME') ? DB_NAME : '';
		return 'llamly:' . substr(hash('sha256', $identity . '|' . $wpdb->prefix . '|' . $type . '|' . $id), 0, 40);
	}

	public static function acquire(string $type, int $id, int $timeout = 5): bool
	{
		return self::acquireName(self::name($type, $id), $timeout);
	}

	public static function acquireName(string $name, int $timeout = 5): bool
	{
		global $wpdb;
		if (isset(self::$held[$name])) {
			return true;
		}
		$value = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, min(10, $timeout))));
		if ((string) $value !== '1' || $wpdb->last_error !== '') {
			return false;
		}
		self::$held[$name] = true;
		return true;
	}

	public static function owns(string $type, int $id): bool
	{
		return self::ownsName(self::name($type, $id));
	}

	public static function ownsName(string $name): bool
	{
		global $wpdb;
		$value = $wpdb->get_var($wpdb->prepare(
			'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), 1, 0)',
			$name
		));
		return (string) $value === '1' && $wpdb->last_error === '';
	}

	public static function release(string $type, int $id): void
	{
		self::releaseName(self::name($type, $id));
	}

	public static function releaseName(string $name): void
	{
		global $wpdb;
		if (!isset(self::$held[$name])) {
			return;
		}
		$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
		unset(self::$held[$name]);
	}

	public static function connectionId(): ?int
	{
		global $wpdb;
		$id = $wpdb->get_var('SELECT CONNECTION_ID()');
		return $id === null || $id === false || $wpdb->last_error !== '' ? null : (int) $id;
	}
}
