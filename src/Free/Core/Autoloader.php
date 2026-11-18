<?php

declare(strict_types=1);

namespace Llamly\Free\Core;

final class Autoloader
{
	public static function register(): void
	{
		spl_autoload_register(static function (string $class): void {
			$prefix = 'Llamly\\Free\\';
			if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
				return;
			}

			$relative = substr($class, strlen($prefix));
			$file = LLAMLY_FREE_ROOT . '/src/Free/' . str_replace('\\', '/', $relative) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
		});
	}
}
