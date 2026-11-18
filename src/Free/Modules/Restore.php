<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

use Llamly\Free\History\Engine;

/**
 * Restore generation for add-on modules (module API 2, LLP-447).
 *
 * Every journal row records the restore generation it was written in, and Llamly refuses to undo or redo a change of an
 * earlier generation (`restore_generation_changed`, 409), even when IDs and hashes match again. A module that replaces
 * site data wholesale (for example a database restore point of Llamly Pro) bumps the generation right after the new
 * data is in place and before Llamly writes reopen, so older changes can no longer be undone over the restored data.
 *
 * The generation lives in Llamly's own object version table, which a module must never snapshot or overwrite.
 * Llamly itself never bumps it.
 */
final class Restore
{
	/** The current restore generation (0 until a module bumps it). */
	public static function currentGeneration(): int
	{
		return Engine::currentGeneration();
	}

	/**
	 * Raise the restore generation by one. Returns the new generation, or false when it could not be stored (the caller
	 * must then keep Llamly writes paused and report it). Call it after the restored data is in place.
	 */
	public static function bumpGeneration(): int|false
	{
		return Engine::bumpGeneration();
	}
}
