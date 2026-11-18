<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

use Llamly\Free\History\Adapter;

/**
 * Trusted add-on modules. Llamly fires `llamly_free_loaded` (Llamly version, module API) when it boots, so an add-on
 * knows it can register, and `llamly_free_register_modules` once, the first time adapters, executors, tools or admin
 * sections are needed after plugins_loaded. ModuleRegistry::add() only works inside that action.
 *
 * Modules are registered in id order. A module with an incompatible API, an invalid id, namespace or root, a refused
 * registration or an exception in register() is refused as a whole: nothing it staged is kept, the reason shows in the
 * Doctor and About, and Llamly keeps working.
 *
 * The object types each module registered are remembered (option llamly_free_modules_known), so the history of an
 * add-on that was deactivated or removed is still labelled and its undo answers `module_unavailable` instead of failing.
 */
final class ModuleRegistry
{
	/** 2 (LLP-447): Modules\Restore (read and bump the restore generation). Additive: API 1 modules keep working. */
	public const API = 2;
	/** Oldest module API this Llamly still accepts. Raised only by a breaking change of the contract. */
	public const MIN_API = 1;
	public const HOOK_LOADED = 'llamly_free_loaded';
	public const HOOK_REGISTER = 'llamly_free_register_modules';
	public const OPTION_KNOWN = 'llamly_free_modules_known';
	/** Ids that would collide with core tool, executor or journal names. */
	private const RESERVED = ['wp', 'llamly', 'free', 'core', 'undo', 'redo', 'force', 'admin', 'ability', 'key', 'oauth', 'batch'];

	private static bool $booted = false;
	private static bool $ready = false;
	private static bool $collecting = false;
	/** @var list<Module> */
	private static array $pending = [];
	/** @var array<string, array{module:Module,staged:array<string,mixed>}> */
	private static array $active = [];
	/** @var list<array{id:string,label:string,version:string,api:int|null,reason:string}> */
	private static array $refused = [];

	/** Called by Plugin::boot(). */
	public static function announce(): void
	{
		do_action('llamly_free_loaded', defined('LLAMLY_FREE_VERSION') ? LLAMLY_FREE_VERSION : '', self::API);
	}

	/** Register a module. Only inside the `llamly_free_register_modules` action; anywhere else it is refused. */
	public static function add(Module $module): bool
	{
		if (!self::$collecting || !doing_action(self::HOOK_REGISTER)) {
			return false;
		}
		self::$pending[] = $module;
		return true;
	}

	/** True once the modules were collected (never before plugins_loaded, so every add-on had the chance to hook in). */
	public static function ready(): bool
	{
		self::boot();
		return self::$ready;
	}

	public static function boot(): void
	{
		if (self::$booted || did_action('plugins_loaded') === 0) {
			return;
		}
		self::$booted = true;
		self::$collecting = true;
		try {
			do_action('llamly_free_register_modules');
		} catch (\Throwable $error) {
			// A callback that throws while adding loses only what it did not add yet.
			self::$refused[] = ['id' => '', 'label' => '', 'version' => '', 'api' => null, 'reason' => 'exception: ' . get_class($error)];
		} finally {
			self::$collecting = false;
		}
		$modules = self::$pending;
		self::$pending = [];
		usort($modules, static fn(Module $a, Module $b): int => strcmp(self::safeId($a), self::safeId($b)));
		foreach ($modules as $module) {
			self::accept($module);
		}
		self::$ready = true;
		self::remember();
		self::reportTrimmedGuides();
	}

	/** Whether at least one module was accepted (active and valid). */
	public static function hasActive(): bool
	{
		self::boot();
		return (bool) self::$active;
	}

	/** @return array<string, Adapter> */
	public static function adapters(): array
	{
		$adapters = [];
		foreach (self::$active as $entry) {
			$adapters += $entry['staged']['adapters'];
		}
		return $adapters;
	}

	/** @return array<string, callable> Executors wrapped so the module gets its WriteGateway as fourth argument. */
	public static function executors(): array
	{
		$executors = [];
		foreach (self::$active as $id => $entry) {
			foreach ($entry['staged']['executors'] as $name => $executor) {
				$executors[$name] = static function (array $arguments, array $auth, string $tool = '') use ($executor, $id): mixed {
					return $executor($arguments, $auth, $tool, new WriteGateway($id, $tool));
				};
			}
		}
		return $executors;
	}

	/**
	 * @return array<string, array{definition:array<string,mixed>,handler:?callable}> Read handlers wrapped so the module
	 * gets a HashReader for the connected user as third argument.
	 */
	public static function tools(): array
	{
		$tools = [];
		foreach (self::$active as $id => $entry) {
			foreach ($entry['staged']['tools'] as $name => $tool) {
				if ($tool['handler'] !== null) {
					$handler = $tool['handler'];
					$tool['handler'] = static function (array $arguments, array $auth) use ($handler, $id): mixed {
						return $handler($arguments, $auth, new HashReader($id, (int) ($auth['user_id'] ?? 0)));
					};
				}
				$tools += [$name => $tool];
			}
		}
		return $tools;
	}

	/**
	 * What the active modules tell connected agents (ModuleContext::describeForAgents and friends), in id order.
	 *
	 * @return list<array{id:string,label:string,instructions:string,category:string,related:array<string,list<string>>,elementor:bool,tools:list<string>}>
	 */
	public static function agentGuides(): array
	{
		self::boot();
		$guides = [];
		foreach (self::$active as $id => $entry) {
			$agents = $entry['staged']['agents'];
			$guides[] = [
				'id' => $id,
				'label' => self::text($entry['module']->label(), 80),
				'instructions' => (string) $agents['instructions'],
				'category' => (string) $agents['category'],
				'related' => (array) $agents['related'],
				'elementor' => (bool) $agents['elementor'],
				'tools' => array_map('strval', array_keys($entry['staged']['tools'])),
			];
		}
		return $guides;
	}

	/**
	 * Active module that registered a tool, for the Activity rows of changes it made on core items (a Gutenberg page
	 * written by an add-on tool keeps the core object type "post").
	 *
	 * @return array{id:string,label:string,active:bool}|null
	 */
	public static function ownerOfTool(string $tool): ?array
	{
		self::boot();
		foreach (self::$active as $id => $entry) {
			if (isset($entry['staged']['tools'][$tool])) {
				return ['id' => $id, 'label' => self::text($entry['module']->label(), 80), 'active' => true];
			}
		}
		return null;
	}

	/** @return array<string, array{label:string,renderer:callable,capability:string}> */
	public static function sections(): array
	{
		self::boot();
		$sections = [];
		foreach (self::$active as $entry) {
			$sections += $entry['staged']['sections'];
		}
		return $sections;
	}

	/**
	 * Active and refused modules for the Doctor, About and the history tools.
	 *
	 * @return array{api:int,active:list<array<string,mixed>>,refused:list<array<string,mixed>>,inactive:list<array<string,mixed>>}
	 */
	public static function report(): array
	{
		self::boot();
		$active = [];
		foreach (self::$active as $id => $entry) {
			$module = $entry['module'];
			$active[] = [
				'id' => $id,
				'label' => self::text($module->label(), 80),
				'version' => self::text($module->version(), 32),
				'api' => $module->requiresApi(),
				'status' => self::text(self::call(static fn(): string => $module->status(), ''), 60),
				'tools' => array_keys($entry['staged']['tools']),
				'types' => array_keys($entry['staged']['adapters']),
			];
		}
		$inactive = [];
		foreach (self::known() as $id => $known) {
			if (!isset(self::$active[$id])) {
				$inactive[] = ['id' => $id, 'label' => (string) ($known['label'] ?? $id), 'version' => (string) ($known['version'] ?? ''), 'types' => array_values((array) ($known['types'] ?? []))];
			}
		}
		return ['api' => self::API, 'active' => $active, 'refused' => self::$refused, 'inactive' => $inactive];
	}

	/**
	 * Module that owns a journal object type: an active module, or one that registered it before and is not active now.
	 *
	 * @return array{id:string,label:string,active:bool}|null
	 */
	public static function ownerOfType(string $type): ?array
	{
		self::boot();
		foreach (self::$active as $id => $entry) {
			if (isset($entry['staged']['adapters'][$type])) {
				return ['id' => $id, 'label' => self::text($entry['module']->label(), 80), 'active' => true];
			}
		}
		foreach (self::known() as $id => $known) {
			if (in_array($type, (array) ($known['types'] ?? []), true)) {
				return ['id' => (string) $id, 'label' => (string) ($known['label'] ?? $id), 'active' => false];
			}
		}
		return null;
	}

	/** Error for an undo, redo or approval of a change whose module is not active. */
	public static function unavailableError(array $owner): \WP_Error
	{
		return new \WP_Error(
			'module_unavailable',
			/* translators: %s: add-on name. */
			sprintf(__('This change was made by the add-on “%s”, which is not active. Activate it to undo or redo this change.', 'llamly'), $owner['label']),
			['status' => 409, 'module' => $owner['id']]
		);
	}

	/** Whether $file is inside the directory $root (both resolved, symlinks included). */
	public static function inside(string $file, string $root): bool
	{
		$real = realpath($file);
		$base = realpath($root);
		if ($real === false || $base === false) {
			return false;
		}
		$real = wp_normalize_path($real);
		$base = trailingslashit(wp_normalize_path($base));
		return strpos($real, $base) === 0;
	}

	private static function accept(Module $module): void
	{
		$id = self::safeId($module);
		$record = ['id' => $id, 'label' => '', 'version' => '', 'api' => null];
		try {
			$record['label'] = self::text($module->label(), 80);
			$record['version'] = self::text($module->version(), 32);
			$record['api'] = $module->requiresApi();
			$reason = self::validate($module, $id);
			if ($reason !== null) {
				self::$refused[] = $record + ['reason' => $reason];
				return;
			}
			$context = new ModuleContext($id, $module->namespace(), (string) realpath($module->root()));
			try {
				$module->register($context);
			} catch (\Throwable $error) {
				$context->fail('exception', get_class($error));
			}
			$context->seal();
			if ($context->error() !== null) {
				self::$refused[] = $record + ['reason' => (string) $context->error()];
				return;
			}
			self::$active[$id] = ['module' => $module, 'staged' => $context->staged()];
		} catch (\Throwable $error) {
			self::$refused[] = $record + ['reason' => 'exception: ' . get_class($error)];
		}
	}

	private static function validate(Module $module, string $id): ?string
	{
		if (!preg_match('/^[a-z][a-z0-9]{1,23}$/', $id) || in_array($id, self::RESERVED, true)) {
			return 'id_invalid';
		}
		if (isset(self::$active[$id])) {
			return 'id_duplicate';
		}
		$api = $module->requiresApi();
		if ($api < self::MIN_API || $api > self::API) {
			return 'api_incompatible: ' . $api;
		}
		$namespace = $module->namespace();
		if (!preg_match('/^Llamly\\\\(?:[A-Z][A-Za-z0-9_]*\\\\)+$/', $namespace) || stripos($namespace, 'Llamly\\Free\\') === 0) {
			return 'namespace_invalid';
		}
		$root = realpath($module->root());
		if ($root === false || !is_dir($root)) {
			return 'root_invalid';
		}
		$root = trailingslashit(wp_normalize_path($root));
		$free = realpath(LLAMLY_FREE_ROOT . '/src/Free');
		$free = $free !== false ? trailingslashit(wp_normalize_path($free)) : '';
		// A module root may not be the site, wp-content or the folder that holds the plugins (the parent of this plugin's
		// folder). These are only compared with the module root, never used to build a path.
		$broad = array_filter(array_map(static function (string $path): string {
			$real = realpath($path);
			return $real !== false ? trailingslashit(wp_normalize_path($real)) : '';
		}, [ABSPATH, WP_CONTENT_DIR, dirname(LLAMLY_FREE_DIR)]));
		if ($free === '' || strpos($root, $free) === 0 || strpos($free, $root) === 0 || in_array($root, $broad, true)) {
			return 'root_invalid';
		}
		$class = get_class($module);
		$file = (new \ReflectionClass($module))->getFileName();
		if (strpos($class, $namespace) !== 0 || !is_string($file) || !self::inside($file, $root)) {
			return 'module_not_owned';
		}
		return null;
	}

	private static function safeId(Module $module): string
	{
		try {
			return (string) $module->id();
		} catch (\Throwable $error) {
			return '';
		}
	}

	private static function call(callable $fn, string $fallback): string
	{
		try {
			return (string) $fn();
		} catch (\Throwable $error) {
			return $fallback;
		}
	}

	private static function text(string $value, int $max): string
	{
		return substr(sanitize_text_field($value), 0, $max);
	}

	/** @return array<string, array{label?:string,version?:string,types?:list<string>}> */
	private static function known(): array
	{
		$known = get_option(self::OPTION_KNOWN, []);
		return is_array($known) ? $known : [];
	}

	/** Option: module id => signature of the guide cut last logged (LLP-478), so each cut shows once in Logs. */
	private const OPTION_GUIDES_TRIMMED = 'llamly_free_modules_guides_trimmed';

	/** One Logs entry (module_guide_trimmed) per module guide cut to ModuleContext::AGENT_GUIDE_BYTES, when it changes. */
	private static function reportTrimmedGuides(): void
	{
		$next = [];
		foreach (self::$active as $id => $entry) {
			$bytes = (int) ($entry['staged']['agents']['trimmed'] ?? 0);
			if ($bytes > 0) {
				$next[$id] = $bytes . ':' . md5((string) $entry['staged']['agents']['instructions']);
			}
		}
		if ($next === []) {
			return; // nothing cut: no option read on a normal request
		}
		$logged = get_option(self::OPTION_GUIDES_TRIMMED, []);
		$logged = is_array($logged) ? $logged : [];
		// Entries of modules not cut in this request stay, so turning a module off and on does not log it again.
		$store = $logged;
		foreach ($next as $id => $signature) {
			if (($logged[$id] ?? '') === $signature) {
				continue;
			}
			// Remembered only once the Logs row exists: a failed insert is tried again on a later request.
			if (\Llamly\Free\Core\AuditLog::append('module_guide_trimmed', [
				'module' => $id,
				'bytes' => (int) strtok($signature, ':'),
				'limit' => ModuleContext::AGENT_GUIDE_BYTES,
			], null, null, null)) {
				$store[$id] = $signature;
			}
		}
		if ($store !== $logged) {
			update_option(self::OPTION_GUIDES_TRIMMED, $store, true);
		}
	}

	/** Remembers which object types each active module owns; written only when something changed. */
	private static function remember(): void
	{
		if (!self::$active) {
			return;
		}
		$known = self::known();
		$next = $known;
		foreach (self::$active as $id => $entry) {
			if (!$entry['staged']['adapters']) {
				// Only modules with journal object types matter once they are gone.
				continue;
			}
			$types = array_values(array_unique(array_merge((array) ($known[$id]['types'] ?? []), array_keys($entry['staged']['adapters']))));
			sort($types);
			$next[$id] = ['label' => self::text($entry['module']->label(), 80), 'version' => self::text($entry['module']->version(), 32), 'types' => $types];
		}
		ksort($next);
		if ($next !== $known) {
			update_option(self::OPTION_KNOWN, $next, true);
		}
	}
}
