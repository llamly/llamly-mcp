<?php

declare(strict_types=1);

namespace Llamly\Free\Core;

/**
 * Local filesystem access through WordPress' direct filesystem class, for files Llamly owns in uploads and the
 * temp directory (media retention, staged uploads). It never asks for FTP/SSH credentials: these files are written
 * by PHP itself, so the direct method is the only one that applies.
 */
final class Filesystem
{
	private static ?\WP_Filesystem_Direct $direct = null;

	public static function direct(): \WP_Filesystem_Direct
	{
		if (self::$direct === null) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			// Llamly passes its own modes; when the direct class falls back to a default (copy() inside move() across
			// devices) it uses these instead of the FS_CHMOD_* constants, which only WP_Filesystem() defines.
			self::$direct = new class (null) extends \WP_Filesystem_Direct {
				public function chmod($file, $mode = false, $recursive = false)
				{
					return parent::chmod($file, $mode ?: ($this->is_file($file) ? 0644 : 0755), $recursive);
				}

				public function mkdir($path, $chmod = false, $chown = false, $chgrp = false)
				{
					return parent::mkdir($path, $chmod ?: 0755, $chown, $chgrp);
				}
			};
		}
		return self::$direct;
	}
}
