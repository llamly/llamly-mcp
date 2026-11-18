<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

/**
 * A trusted extension of Llamly (PRO today, Forms and Backups later) that adds domains, write executors, tools and admin
 * screens without editing the core. It is registered once, inside the `llamly_free_register_modules` action, through
 * ModuleRegistry::add(). Every write it provides runs through the same Engine as the core: journal, CAS, permits,
 * safe mode, approval queue, audit, undo/redo and recovery.
 *
 * A module ships as an add-on: a separate plugin that requires Llamly (`Requires Plugins: llamly`) and checks at runtime,
 * through the `llamly_free_loaded` action, that Llamly is active and recent enough. The checks against namespace() and
 * root() guard against mistakes and accidental bypasses; they are not a security boundary (any active plugin already
 * runs trusted PHP).
 *
 * See ai_files/LLP-419-CONTRATO-MODULOS.md for the full contract.
 */
interface Module
{
	/** Stable identifier, `^[a-z][a-z0-9]{1,23}$`. Prefixes every adapter type, executor, tool and admin section of the module. */
	public function id(): string;

	/** Name shown in the Doctor, About and Activity (plain text, up to 80 characters). */
	public function label(): string;

	/** The module's own version, shown in the Doctor and About. */
	public function version(): string;

	/**
	 * Short plain-text status the add-on wants shown in About (for example "Active" or "No license"), or an empty
	 * string. Llamly only displays it: it never checks a license, never calls a licensing service and never locks
	 * anything. Whether the add-on registers its tools is the add-on's own decision.
	 */
	public function status(): string;

	/** Module API the module was written for. Compatible when ModuleRegistry::MIN_API <= value <= ModuleRegistry::API. */
	public function requiresApi(): int;

	/** PHP namespace that owns every class the module registers, ending with a backslash (for example `Llamly\Pro\`). Never `Llamly\Free\`. */
	public function namespace(): string;

	/** Absolute directory that holds every file the module registers. Never src/Free/ of Llamly, nor a folder that contains it. */
	public function root(): string;

	/** Registers everything through the context. Anything refused (or an exception) refuses the whole module. */
	public function register(ModuleContext $context): void;
}
