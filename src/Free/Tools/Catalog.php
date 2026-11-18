<?php

declare(strict_types=1);

namespace Llamly\Free\Tools;

use Llamly\Free\Core\AuditLog;

/**
 * Domain registry. Each domain class exposes:
 *   public static function register(): void      registers its read and write tools (Toolkit::read/write)
 *   public static function adapters(): array     returns its History\Adapter instances
 * A domain that fails to load (missing file, parse error, exception) is skipped and logged once, so one broken domain
 * never takes the MCP server or the other domains down.
 */
final class Catalog
{
	public const DOMAINS = [
		PostTools::class,
		RevisionTools::class,
		TermTools::class,
		MediaTools::class,
		MenuTools::class,
		CommentTools::class,
		UserTools::class,
		SiteTools::class,
		GutenbergTools::class,
		ElementorTools::class,
		HistoryTools::class,
		BatchTools::class,
		// Last, so the upload tools added in 1.1 come after every 1.0 tool in discover (the 1.0 listing is unchanged).
		MediaParts::class,
	];

	/** @var array<string, string> */
	private static array $failed = [];

	public static function registerTools(): void
	{
		foreach (self::DOMAINS as $class) {
			self::guard($class, 'register', static function () use ($class): void {
				$class::register();
			});
		}
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		$adapters = [];
		foreach (self::DOMAINS as $class) {
			self::guard($class, 'adapters', static function () use ($class, &$adapters): void {
				foreach ($class::adapters() as $adapter) {
					$adapters[] = $adapter;
				}
			});
		}
		return $adapters;
	}

	/** @return array<string, string> domain class => error code, for the Doctor and Sobre. */
	public static function failures(): array
	{
		return self::$failed;
	}

	private static function guard(string $class, string $method, callable $run): void
	{
		try {
			if (!class_exists($class) || !method_exists($class, $method)) {
				return;
			}
			$run();
		} catch (\Throwable $error) {
			if (!isset(self::$failed[$class])) {
				self::$failed[$class] = get_class($error);
				if (function_exists('error_log')) {
					error_log('[llamly] domain ' . $class . ' failed to load: ' . get_class($error) . ': ' . $error->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logged once when a tool domain fails to load, so the site owner can see why tools are missing.
				}
			}
		}
	}
}
