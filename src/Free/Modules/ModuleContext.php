<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

use Llamly\Free\History\Adapter;
use Llamly\Free\Tools\Toolkit;

/**
 * What a module may register, handed to Module::register() once. Every call is checked against the module's id,
 * namespace and root; the first refusal (or an exception) refuses the whole module and nothing it staged is kept.
 * The context is sealed when register() returns: a module that keeps it and registers later is refused.
 *
 * Names are prefixed by the module id, so a module can never take over a core or another module's name:
 * adapter types and tools `<id>_...`, executors `<id>.<name>`, admin sections `<id>` or `<id>-...`.
 */
final class ModuleContext
{
	/** Longest adapter hash prefix: "<prefix>_" + 64 hex must fit the journal's char(68) hash columns. */
	public const MAX_PREFIX = 3;
	/** Longest guide a module may pass (characters; longer is refused). In the instructions it is cut to AGENT_GUIDE_BYTES. */
	public const MAX_INSTRUCTIONS = 1200;
	/**
	 * Bytes of a module guide in the MCP instructions (LLP-478, decision of the Head, option A): with three modules on, the
	 * whole text the agent reads stays under 3 KB for every identity of the budget suite (200 bytes did not fit). A longer guide is accepted and cut at a word boundary, with "…", and the cut shows once in Logs.
	 * Details belong where the agent reads them on demand (the module's own guide tool, tool descriptions).
	 */
	public const AGENT_GUIDE_BYTES = 150;
	public const MAX_CATEGORY_DESCRIPTION = 200;
	/** Core categories a module may join, and its tools per category. */
	public const MAX_RELATED_CATEGORIES = 4;
	public const MAX_RELATED_TOOLS = 12;

	private bool $sealed = false;
	private ?string $error = null;
	/** @var array<string, Adapter> */
	private array $adapters = [];
	/** @var array<string, callable> */
	private array $executors = [];
	/** @var array<string, array{definition:array<string,mixed>,handler:?callable}> */
	private array $tools = [];
	/** @var array<string, array{label:string,renderer:callable,capability:string}> */
	private array $sections = [];
	/** @var array{instructions:string,category:string,related:array<string,list<string>>,elementor:bool} */
	private array $agents = ['instructions' => '', 'category' => '', 'related' => [], 'elementor' => false, 'trimmed' => 0];

	/** @internal Created by ModuleRegistry only. */
	public function __construct(private string $id, private string $namespace, private string $root)
	{
	}

	public function moduleId(): string
	{
		return $this->id;
	}

	/**
	 * A reversible domain. Its type must be `<id>_...` (up to 48 characters) and its hash prefix 1 to 3 lowercase letters
	 * or digits: the revision hash ("<prefix>_" + 64 hex) must fit the journal's 68-character hash columns.
	 */
	public function registerAdapter(Adapter $adapter): bool
	{
		if (!$this->open()) {
			return false;
		}
		$type = $adapter->type();
		if (!preg_match('/^[a-z][a-z0-9_]{1,47}$/', $type) || strpos($type, $this->id . '_') !== 0) {
			return $this->refuse('adapter_type_invalid', $type);
		}
		if (isset($this->adapters[$type])) {
			return $this->refuse('adapter_duplicate', $type);
		}
		if (!preg_match('/^[a-z0-9]{1,' . self::MAX_PREFIX . '}$/', $adapter->prefix())) {
			return $this->refuse('adapter_prefix_invalid', $type . ' (the hash prefix must be 1 to ' . self::MAX_PREFIX . ' lowercase letters or digits, so "<prefix>_" + 64 hex fits the 68-character journal hash column)');
		}
		if (!$this->ownsClass(get_class($adapter))) {
			return $this->refuse('adapter_not_owned', get_class($adapter));
		}
		$this->adapters[$type] = $adapter;
		return true;
	}

	/**
	 * A write executor, `<id>.<name>`. It is called as fn(array $arguments, array $auth, string $tool, WriteGateway $gateway)
	 * and writes only through $gateway, which runs the Engine.
	 */
	public function registerExecutor(string $name, callable $executor): bool
	{
		if (!$this->open()) {
			return false;
		}
		if (!preg_match('/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/', $name) || strpos($name, $this->id . '.') !== 0) {
			return $this->refuse('executor_name_invalid', $name);
		}
		if (isset($this->executors[$name])) {
			return $this->refuse('executor_duplicate', $name);
		}
		if (!$this->ownsCallable($executor)) {
			return $this->refuse('executor_not_owned', $name);
		}
		$this->executors[$name] = $executor;
		return true;
	}

	/**
	 * A read tool with its query handler.
	 *
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 */
	public function registerReadTool(string $name, string $description, string $capability, array $properties, array $required, callable $handler): bool
	{
		if (!$this->open()) {
			return false;
		}
		if (!$this->validToolName($name)) {
			return false;
		}
		if (!$this->ownsCallable($handler)) {
			return $this->refuse('handler_not_owned', $name);
		}
		$this->tools[$name] = [
			'definition' => [
				'name' => $name,
				'description' => $description,
				'category' => $this->id,
				'access' => 'read',
				'capability' => $capability,
				'inputSchema' => Toolkit::schema($properties, $required),
				'annotations' => Toolkit::annotations($name, 'read'),
				'module' => $this->id,
			],
			'handler' => $handler,
		];
		return true;
	}

	/**
	 * A write tool. It never takes a callable: it names one of this module's executors, and the write envelope
	 * (dry_run, idempotency_key, confirm and, for existing items, expected_hash) is added here like in the core.
	 *
	 * @param array<string, array<string, mixed>> $properties
	 * @param list<string> $required
	 */
	public function registerWriteTool(string $name, string $description, string $capability, array $properties, array $required, bool $existing, string $executor): bool
	{
		if (!$this->open()) {
			return false;
		}
		if (!$this->validToolName($name)) {
			return false;
		}
		if (!isset($this->executors[$executor])) {
			return $this->refuse('executor_unknown', $name);
		}
		$this->tools[$name] = [
			'definition' => [
				'name' => $name,
				'description' => $description,
				'category' => $this->id,
				'access' => 'write',
				'capability' => $capability,
				'inputSchema' => Toolkit::schema($properties + Toolkit::writeProps($existing), array_values(array_unique(array_merge($required, Toolkit::writeRequired($existing))))),
				'executor' => $executor,
				'annotations' => Toolkit::annotations($name, 'write'),
				'module' => $this->id,
			],
			'handler' => null,
		];
		return true;
	}

	/** A section of the Llamly admin page, `<id>` or `<id>-...`. The HTML goes through wp_kses_post like any section. */
	public function registerAdminSection(string $id, string $label, callable $renderer, string $capability = 'manage_options'): bool
	{
		if (!$this->open()) {
			return false;
		}
		$label = sanitize_text_field($label);
		if (($id !== $this->id && strpos($id, $this->id . '-') !== 0) || strlen($id) > 64 || !preg_match('/^[a-z][a-z0-9_-]*$/', $id)
			|| $label === '' || strlen($label) > 80 || sanitize_key($capability) === '' || isset($this->sections[$id])
		) {
			return $this->refuse('section_invalid', $id);
		}
		if (!$this->ownsCallable($renderer)) {
			return $this->refuse('section_not_owned', $id);
		}
		$this->sections[$id] = ['label' => $label, 'renderer' => $renderer, 'capability' => sanitize_key($capability)];
		return true;
	}

	/**
	 * What a connected agent reads about the module (all optional; a module that never calls it changes nothing).
	 * $instructions joins the MCP instructions while the module is active and the connection can use at least one of its
	 * tools: plain text, up to MAX_INSTRUCTIONS characters, line breaks allowed. Keep it within AGENT_GUIDE_BYTES (150
	 * bytes, one or two sentences: what the module does and where to start); a longer one is cut at a word boundary with
	 * "…" (the module still loads) and the cut is logged once (event module_guide_trimmed). $category describes the module's own
	 * category (named after the module id) in llamly_discover: plain text, one line, up to MAX_CATEGORY_DESCRIPTION.
	 */
	public function describeForAgents(string $instructions, string $category = ''): bool
	{
		if (!$this->open()) {
			return false;
		}
		$instructions = trim(str_replace("\r\n", "\n", $instructions));
		$category = trim($category);
		if (!self::plainText($instructions, self::MAX_INSTRUCTIONS, true)) {
			return $this->refuse('instructions_invalid', 'plain text without markup or control characters, up to ' . self::MAX_INSTRUCTIONS . ' characters');
		}
		if (!self::plainText($category, self::MAX_CATEGORY_DESCRIPTION, false)) {
			return $this->refuse('category_description_invalid', 'one line of plain text, up to ' . self::MAX_CATEGORY_DESCRIPTION . ' characters');
		}
		$this->agents['trimmed'] = strlen($instructions) > self::AGENT_GUIDE_BYTES ? strlen($instructions) : 0;
		$this->agents['instructions'] = self::cutGuide($instructions, self::AGENT_GUIDE_BYTES);
		$this->agents['category'] = $category;
		return true;
	}

	/**
	 * Show some of the module's tools also when an agent browses a core category in llamly_discover (for example
	 * "gutenberg"), before the core tools of that category. The tools must be registered by this module (checked when
	 * register() returns); a category that does not exist in Llamly is ignored.
	 *
	 * @param list<string> $tools
	 */
	public function addToCategory(string $category, array $tools): bool
	{
		if (!$this->open()) {
			return false;
		}
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $category) || $category === $this->id || isset($this->agents['related'][$category])) {
			return $this->refuse('category_invalid', $category);
		}
		if (count($this->agents['related']) >= self::MAX_RELATED_CATEGORIES) {
			return $this->refuse('category_limit', $category . ' (up to ' . self::MAX_RELATED_CATEGORIES . ' categories)');
		}
		$tools = array_values(array_unique(array_map('strval', $tools)));
		if (!$tools || count($tools) > self::MAX_RELATED_TOOLS) {
			return $this->refuse('category_tools_invalid', $category . ' (1 to ' . self::MAX_RELATED_TOOLS . ' tools)');
		}
		foreach ($tools as $tool) {
			if (strpos($tool, $this->id . '_') !== 0) {
				return $this->refuse('category_tool_unknown', $tool);
			}
		}
		$this->agents['related'][$category] = $tools;
		return true;
	}

	/** The module's write tools change Elementor documents, so the instructions stop calling Elementor read-only. */
	public function declareElementorWriter(): bool
	{
		if (!$this->open()) {
			return false;
		}
		$this->agents['elementor'] = true;
		return true;
	}

	/** @internal */
	public function seal(): void
	{
		if (!$this->sealed && $this->error === null) {
			// Tools named by addToCategory() may be registered after it, so they are checked once register() returned.
			foreach ($this->agents['related'] as $tools) {
				foreach ($tools as $tool) {
					if (!isset($this->tools[$tool])) {
						$this->refuse('category_tool_unknown', $tool);
						break 2;
					}
				}
			}
		}
		$this->sealed = true;
	}

	/** @internal */
	public function fail(string $code, string $detail = ''): void
	{
		$this->refuse($code, $detail);
	}

	/** @internal Null when everything was accepted, otherwise "code: detail" of the first refusal. */
	public function error(): ?string
	{
		return $this->error;
	}

	/** @internal @return array{adapters:array<string,Adapter>,executors:array<string,callable>,tools:array<string,array{definition:array<string,mixed>,handler:?callable}>,sections:array<string,array{label:string,renderer:callable,capability:string}>,agents:array{instructions:string,category:string,related:array<string,list<string>>,elementor:bool}} */
	public function staged(): array
	{
		return ['adapters' => $this->adapters, 'executors' => $this->executors, 'tools' => $this->tools, 'sections' => $this->sections, 'agents' => $this->agents];
	}

	private function open(): bool
	{
		if ($this->sealed) {
			// Registering after register() returned: the module was already accepted or refused as a whole.
			return false;
		}
		return $this->error === null;
	}

	/**
	 * $text within $maxBytes: unchanged when it fits; otherwise cut at the last word boundary (never inside a character)
	 * and ended with "…". Without a word boundary in the kept part, cut on a character boundary.
	 */
	public static function cutGuide(string $text, int $maxBytes, bool $useMbstring = true): string
	{
		if (strlen($text) <= $maxBytes) {
			return $text;
		}
		$keep = $maxBytes - strlen('…');
		if ($useMbstring && function_exists('mb_strcut')) {
			$cut = mb_strcut($text, 0, $keep, 'UTF-8');
		} else {
			$cut = substr($text, 0, $keep);
			while ($cut !== '' && !preg_match('//u', $cut)) {
				$cut = substr($cut, 0, -1);
			}
		}
		// The next character starts a new word only if it is whitespace; otherwise drop the partial word.
		if (!preg_match('/\s/u', substr($text, strlen($cut), 1))) {
			$space = max((int) strrpos($cut, ' '), (int) strrpos($cut, "\n"));
			// A boundary in the first half would drop most of the guide: cut on the character boundary instead.
			if ($space > intdiv(strlen($cut), 2)) {
				$cut = substr($cut, 0, $space);
			}
		}
		return rtrim($cut, " \n,;:") . '…';
	}

	private function refuse(string $code, string $detail): bool
	{
		if ($this->error === null) {
			$this->error = $code . ($detail !== '' ? ': ' . $detail : '');
		}
		return false;
	}

	/** Plain text: valid UTF-8, no markup, no control characters (line breaks only when $multiline). */
	private static function plainText(string $text, int $max, bool $multiline): bool
	{
		if ($text === '') {
			return true;
		}
		$length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
		if ($length > $max || !wp_check_invalid_utf8($text) || wp_strip_all_tags($text) !== $text || strpos($text, '<') !== false) {
			return false;
		}
		return !preg_match($multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $text);
	}

	private function validToolName(string $name): bool
	{
		if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) || strpos($name, $this->id . '_') !== 0) {
			return $this->refuse('tool_name_invalid', $name);
		}
		if (isset($this->tools[$name])) {
			return $this->refuse('tool_duplicate', $name);
		}
		return true;
	}

	/** Class in the module namespace whose file is inside the module root. */
	private function ownsClass(string $class): bool
	{
		if (strpos($class, $this->namespace) !== 0) {
			return false;
		}
		try {
			$file = (new \ReflectionClass($class))->getFileName();
		} catch (\ReflectionException $error) {
			return false;
		}
		return is_string($file) && ModuleRegistry::inside($file, $this->root);
	}

	/** [class or object, method] of an owned class, or a closure declared in the module namespace inside the root. */
	private function ownsCallable(callable $callable): bool
	{
		if (is_array($callable) && count($callable) === 2 && is_string($callable[1])) {
			$class = is_object($callable[0]) ? get_class($callable[0]) : (is_string($callable[0]) ? ltrim($callable[0], '\\') : '');
			if ($class === '' || !$this->ownsClass($class)) {
				return false;
			}
			try {
				$method = new \ReflectionMethod($class, $callable[1]);
			} catch (\ReflectionException $error) {
				return false;
			}
			$file = $method->getFileName();
			return is_string($file) && ModuleRegistry::inside($file, $this->root);
		}
		if ($callable instanceof \Closure) {
			$function = new \ReflectionFunction($callable);
			$file = $function->getFileName();
			$namespace = $function->getNamespaceName() . '\\';
			return is_string($file) && strpos($namespace, $this->namespace) === 0 && ModuleRegistry::inside($file, $this->root);
		}
		return false;
	}
}
