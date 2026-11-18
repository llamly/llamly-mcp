<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * Block tree addressing and the all-or-nothing batch of wp_edit_blocks.
 *
 * Parsing and serializing are WordPress' own parse_blocks()/serialize_block(); this class only adds:
 * - dot-path addresses (base 0) over the parsed tree without top-level whitespace-only nodes;
 * - snapshot addressing: every path of a batch refers to the tree read BEFORE the batch;
 * - conflict validation of the whole batch before anything is applied;
 * - three phases in memory (updates in place, moves captured, one functional rebuild by original path);
 * - innerContent reindexing when the number of children of a block changes;
 * - the content policy for every agent write, whatever the user's role (core/html, script, iframe, style, on* and
 *   javascript: refused; kses per node). Agents never write unfiltered HTML (see Auth\CapabilityCeiling).
 *
 * Every failure is a MutationFailure thrown before a single byte is written.
 */
final class BlockTree
{
	public const MAX_OPERATIONS = 50;
	public const MAX_DEPTH = 12;
	public const PATH_PATTERN = '^\d{1,4}(\.\d{1,4}){0,15}$';
	public const NAME_PATTERN = '^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$';
	private const POSITIONS = ['before', 'after', 'first_child', 'last_child'];

	/** @return list<array<string, mixed>> */
	public static function parse(string $content): array
	{
		$blocks = [];
		foreach (parse_blocks($content) as $block) {
			if ($block['blockName'] === null && trim((string) $block['innerHTML']) === '') {
				continue;
			}
			$blocks[] = $block;
		}
		return $blocks;
	}

	/** Content stored as classic/freeform HTML (any top-level node outside a block delimiter). */
	public static function isClassic(string $content): bool
	{
		if (trim($content) === '') {
			return false;
		}
		foreach (self::parse($content) as $block) {
			if ($block['blockName'] === null) {
				return true;
			}
		}
		return false;
	}

	/** @param list<array<string, mixed>> $blocks */
	public static function serialize(array $blocks): string
	{
		return implode("\n\n", array_map('serialize_block', $blocks));
	}

	/** Whether parse + serialize changes the stored bytes (whitespace between blocks, attribute JSON escaping). */
	public static function roundTripNormalizes(string $content): bool
	{
		return self::serialize(self::parse($content)) !== $content;
	}

	/**
	 * Addressed summary of a tree.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	public static function summarize(array $blocks, bool $full = false, string $prefix = ''): array
	{
		$out = [];
		foreach (array_values($blocks) as $index => $block) {
			$path = $prefix === '' ? (string) $index : $prefix . '.' . $index;
			$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
			$item = ['path' => $path, 'name' => $block['blockName'] ?? 'core/freeform'];
			if ($full) {
				$item['attrs'] = $attrs ?: (object) [];
				$item['content'] = (string) $block['innerHTML'];
			} else {
				if ($attrs) {
					$preview = [];
					foreach ($attrs as $key => $value) {
						$preview[$key] = is_scalar($value) || $value === null ? $value : '[...]';
					}
					$item['attrs_preview'] = $preview;
				}
				$text = trim((string) $block['innerHTML']);
				if ($text !== '') {
					$item['content_preview'] = mb_strlen($text, 'UTF-8') > 200 ? mb_substr($text, 0, 200, 'UTF-8') . '...' : $text;
				}
			}
			$children = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
			$item['has_children'] = $children !== [];
			if ($children) {
				$item['children'] = self::summarize($children, $full, $path);
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Validate and apply a batch to a tree, all in memory.
	 *
	 * @param list<array<string, mixed>> $tree Output of parse().
	 * @param list<array<string, mixed>> $operations
	 * @return array{blocks:list<array<string,mixed>>,plan:list<array<string,mixed>>,warnings:list<string>}
	 * @throws MutationFailure
	 */
	public static function apply(array $tree, array $operations): array
	{
		if (!$operations || count($operations) > self::MAX_OPERATIONS) {
			throw new MutationFailure('invalid_arguments', esc_html(sprintf('Send between 1 and %d operations.', self::MAX_OPERATIONS)), 400);
		}
		$warnings = [];
		$plan = [];
		$ops = [];
		foreach (array_values($operations) as $i => $raw) {
			$ops[] = self::normalize($tree, is_array($raw) ? $raw : [], $i, $warnings);
			$plan[] = self::describe($tree, $ops[$i], $i);
		}
		$index = self::index($tree, $ops);

		// Phase 1: updates in place (paths are still the snapshot paths).
		foreach ($index['updates'] as $key => $changes) {
			$path = substr($key, 2);
			$node = self::node($tree, $path);
			foreach ($changes as $change) {
				// Classes of the className this change replaces (an earlier update of the same block may have set it).
				$previous = preg_split('/\s+/', trim((string) (is_array($node['attrs'] ?? null) ? ($node['attrs']['className'] ?? '') : '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				if ($change['attrs'] !== null) {
					$attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
					foreach ($change['attrs'] as $name => $value) {
						if ($value === null) {
							unset($attrs[$name]);
						} else {
							$attrs[$name] = $value;
						}
					}
					$node['attrs'] = $attrs;
				}
				if ($change['content'] !== null) {
					$node['innerHTML'] = $change['content'];
					$node['innerContent'] = [$change['content']];
				}
				if (($change['class_sync'] ?? null) !== null) {
					$node = self::withRootClasses($node, array_values(array_diff($previous, $change['class_sync']['add'])), $change['class_sync']['add']);
				}
			}
			$tree = self::replaceNode($tree, $path, $node);
		}

		// Phase 2: a move is a removal at its source plus an insertion of the (updated) node at its target.
		foreach ($index['moves'] as $move) {
			$node = self::node($tree, $move['from']);
			$index[$move['position']]['p:' . $move['to']][] = $node;
			$index['removed']['p:' . $move['from']] = true;
		}

		// Phase 3: one functional rebuild that consults the index by original path.
		$blocks = self::rebuild($tree, '', $index);
		return ['blocks' => $blocks, 'plan' => $plan, 'warnings' => array_values(array_unique($warnings))];
	}

	/**
	 * Content policy for a whole document written by an agent (posts, patterns, templates): the same gates and per-node
	 * kses as wp_edit_blocks, for every user.
	 *
	 * @param list<string> $warnings
	 * @throws MutationFailure
	 */
	public static function filterDocument(string $content, array &$warnings = []): string
	{
		$blocks = self::parse($content);
		foreach ($blocks as $i => $block) {
			$blocks[$i] = self::sanitize($block, 'content', $warnings, 0);
		}
		return self::serialize($blocks);
	}

	// ------------------------------------------------------------------ validation

	/**
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function normalize(array $tree, array $raw, int $i, array &$warnings): array
	{
		$where = 'operations[' . $i . ']';
		$op = (string) ($raw['op'] ?? '');
		switch ($op) {
			case 'update':
				$path = self::existingPath($tree, $raw['path'] ?? null, $where . '.path');
				$node = self::node($tree, $path);
				$attrs = null;
				$content = null;
				if (array_key_exists('attrs', $raw)) {
					if (!is_array($raw['attrs']) || ($raw['attrs'] !== [] && array_keys($raw['attrs']) === range(0, count($raw['attrs']) - 1))) {
						throw self::invalid('invalid_operation', esc_html($where . '.attrs must be an object.'));
					}
					$attrs = $raw['attrs'];
					self::checkAttrs((string) ($node['blockName'] ?? ''), $attrs, $where);
					$others = array_diff(array_keys($attrs), ['className']);
					if (self::isStatic((string) ($node['blockName'] ?? '')) && $others) {
						$warnings[] = sprintf('%s changes attributes of the static block %s at %s without re-rendering its saved HTML; the editor may report invalid content. Prefer replace with markup.', $where, (string) $node['blockName'], $path);
					}
				}
				if (array_key_exists('content', $raw)) {
					if (!is_string($raw['content'])) {
						throw self::invalid('invalid_operation', esc_html($where . '.content must be a string.'));
					}
					if (!empty($node['innerBlocks'])) {
						throw self::invalid('has_children', esc_html(sprintf('%s: the block at %s has child blocks; update a child or replace the whole block.', $where, $path)));
					}
					if (($node['blockName'] ?? '') === 'core/html') {
						throw self::invalid('unfiltered_html_blocked', esc_html(sprintf('%s: agents cannot write core/html (Custom HTML) blocks.', $where)));
					}
					$content = $raw['content'];
					self::gate($content, $where . '.content');
					$filtered = wp_kses_post($content);
					if ($filtered !== $content) {
						$warnings[] = $where . ': disallowed HTML was removed from content.';
					}
					$content = $filtered;
				}
				if ($attrs === null && $content === null) {
					throw self::invalid('invalid_operation', esc_html($where . ': update needs attrs or content.'));
				}
				$classSync = null;
				if ($attrs !== null && array_key_exists('className', $attrs)) {
					$classSync = self::classSyncPlan($node, $attrs['className'], $content, $where, $path);
				}
				return ['op' => 'update', 'path' => $path, 'attrs' => $attrs, 'content' => $content, 'class_sync' => $classSync];
			case 'insert':
				$path = self::existingPath($tree, $raw['path'] ?? null, $where . '.path');
				$position = self::position($raw['position'] ?? null, $where);
				$block = self::blockFromSpec($raw['block'] ?? null, $where . '.block', $warnings);
				if (in_array($position, ['first_child', 'last_child'], true)) {
					self::assertCanTakeChildren(self::node($tree, $path), $path, $where);
				}
				return ['op' => 'insert', 'path' => $path, 'position' => $position, 'block' => $block];
			case 'replace':
				$path = self::existingPath($tree, $raw['path'] ?? null, $where . '.path');
				$block = self::blockFromSpec($raw['block'] ?? null, $where . '.block', $warnings);
				return ['op' => 'replace', 'path' => $path, 'block' => $block];
			case 'delete':
				$path = self::existingPath($tree, $raw['path'] ?? null, $where . '.path');
				return ['op' => 'delete', 'path' => $path];
			case 'move':
				$from = self::existingPath($tree, $raw['from_path'] ?? null, $where . '.from_path');
				$to = self::existingPath($tree, $raw['to_path'] ?? null, $where . '.to_path');
				$position = self::position($raw['position'] ?? null, $where);
				if ($to === $from || self::isDescendant($to, $from)) {
					throw self::invalid('invalid_path', esc_html(sprintf('%s: a block cannot be moved relative to itself or into its own subtree.', $where)));
				}
				if (in_array($position, ['first_child', 'last_child'], true)) {
					self::assertCanTakeChildren(self::node($tree, $to), $to, $where);
				}
				return ['op' => 'move', 'from' => $from, 'to' => $to, 'position' => $position];
		}
		throw self::invalid('invalid_operation', esc_html($where . '.op must be update, insert, replace, delete or move.'));
	}

	/**
	 * Build the operation index and refuse conflicting batches (all paths are snapshot paths).
	 *
	 * @param list<array<string, mixed>> $ops
	 * @return array<string, mixed>
	 */
	private static function index(array $tree, array $ops): array
	{
		$index = ['updates' => [], 'removed' => [], 'replaced' => [], 'before' => [], 'after' => [], 'first_child' => [], 'last_child' => [], 'moves' => []];
		$roots = [];
		foreach ($ops as $i => $op) {
			$root = $op['op'] === 'move' ? $op['from'] : ($op['path'] ?? '');
			if (in_array($op['op'], ['delete', 'replace', 'move'], true)) {
				if (isset($roots['p:' . $root])) {
					throw self::invalid('op_conflict', esc_html(sprintf('operations[%d]: the block at %s is already removed, replaced or moved by operations[%d].', $i, $root, $roots['p:' . $root]['i'])));
				}
				$roots['p:' . $root] = ['i' => $i, 'kind' => $op['op'], 'path' => $root];
			}
		}
		foreach ($ops as $i => $op) {
			$refs = [];
			switch ($op['op']) {
				case 'update':
					$refs[] = ['update', $op['path']];
					break;
				case 'insert':
					$refs[] = [in_array($op['position'], ['before', 'after'], true) ? 'insert anchor' : 'insert parent', $op['path']];
					break;
				case 'replace':
				case 'delete':
					$refs[] = [$op['op'] . ' target', $op['path']];
					break;
				case 'move':
					$refs[] = ['move source', $op['from']];
					$refs[] = [in_array($op['position'], ['before', 'after'], true) ? 'move anchor' : 'move parent', $op['to']];
					break;
			}
			foreach ($refs as [$what, $path]) {
				foreach ($roots as $root) {
					if ($root['i'] === $i && in_array($what, ['move source', 'delete target', 'replace target'], true)) {
						continue;
					}
					$exact = $path === $root['path'];
					if (!$exact && !self::isDescendant($path, $root['path'])) {
						continue;
					}
					$exempt = ($what === 'update' && $root['kind'] === 'move')
						|| ($exact && $root['kind'] === 'replace' && in_array($what, ['insert anchor', 'move anchor'], true));
					if (!$exempt) {
						throw self::invalid('op_conflict', esc_html(sprintf('operations[%d]: %s at %s is inside a block removed by the %s at %s (operations[%d]).', $i, $what, $path, $root['kind'], $root['path'], $root['i'])));
					}
				}
			}
			switch ($op['op']) {
				case 'update':
					$index['updates']['p:' . $op['path']][] = ['attrs' => $op['attrs'], 'content' => $op['content'], 'class_sync' => $op['class_sync'] ?? null];
					break;
				case 'insert':
					$index[$op['position']]['p:' . $op['path']][] = $op['block'];
					break;
				case 'replace':
					$index['replaced']['p:' . $op['path']] = $op['block'];
					break;
				case 'delete':
					$index['removed']['p:' . $op['path']] = true;
					break;
				case 'move':
					$index['moves'][] = $op;
					break;
			}
		}
		return $index;
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @return list<array<string, mixed>>
	 */
	private static function rebuild(array $nodes, string $prefix, array $index): array
	{
		$out = [];
		foreach (array_values($nodes) as $i => $node) {
			$path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
			$key = 'p:' . $path;
			foreach ($index['before'][$key] ?? [] as $inserted) {
				$out[] = $inserted;
			}
			if (isset($index['replaced'][$key])) {
				$out[] = $index['replaced'][$key];
			} elseif (!isset($index['removed'][$key])) {
				$oldChildren = is_array($node['innerBlocks'] ?? null) ? array_values($node['innerBlocks']) : [];
				$children = array_merge(
					$index['first_child'][$key] ?? [],
					self::rebuild($oldChildren, $path, $index),
					$index['last_child'][$key] ?? []
				);
				if (count($children) !== count($oldChildren)) {
					$node = self::reindex($node, $children, $path);
				} else {
					$node['innerBlocks'] = $children;
				}
				$out[] = $node;
			}
			foreach ($index['after'][$key] ?? [] as $inserted) {
				$out[] = $inserted;
			}
		}
		return $out;
	}

	/**
	 * Rebuild innerContent after the number of children changed: keep the wrapper head/tail HTML, one null per child
	 * separated by a blank line. HTML between children is dropped (whitespace in valid block markup).
	 *
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function reindex(array $node, array $children, string $path): array
	{
		[$head, $tail] = self::wrapper($node, $path);
		$inner = [];
		if ($head !== null) {
			$inner[] = $head;
		}
		foreach (array_values($children) as $j => $unused) {
			if ($j > 0) {
				$inner[] = "\n\n";
			}
			$inner[] = null;
		}
		if ($tail !== null) {
			$inner[] = $tail;
		}
		$node['innerBlocks'] = array_values($children);
		$node['innerContent'] = $inner;
		$node['innerHTML'] = ($head ?? '') . ($tail ?? '');
		return $node;
	}

	/** @return array{0:?string,1:?string} */
	private static function wrapper(array $node, string $path): array
	{
		$old = is_array($node['innerContent'] ?? null) ? array_values($node['innerContent']) : [];
		if (empty($node['innerBlocks']) && count($old) <= 1) {
			$html = trim((string) ($old[0] ?? ''));
			if ($html === '') {
				return [null, null];
			}
			if (preg_match('/^(<[a-zA-Z][^>]*>)\s*(<\/[a-zA-Z][^>]*>)$/s', $html, $m)) {
				return [$m[1], $m[2]];
			}
			throw self::invalid('invalid_path', esc_html(sprintf('The block %s at %s has content but no child blocks, so it cannot take children. Replace it with full markup instead.', (string) ($node['blockName'] ?? 'core/freeform'), $path)));
		}
		$head = isset($old[0]) && is_string($old[0]) ? $old[0] : null;
		$last = $old ? $old[count($old) - 1] : null;
		$tail = count($old) > 1 && is_string($last) ? $last : null;
		return [$head, $tail];
	}

	private static function assertCanTakeChildren(?array $node, string $path, string $where): void
	{
		if ($node === null) {
			throw self::invalid('invalid_path', esc_html($where . ': path not found.'));
		}
		if (($node['blockName'] ?? null) === null) {
			throw self::invalid('invalid_path', esc_html(sprintf('%s: classic content at %s cannot take child blocks.', $where, $path)));
		}
		self::wrapper($node, $path);
	}

	// ------------------------------------------------------------------ blocks from the agent

	/**
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function blockFromSpec(mixed $spec, string $where, array &$warnings, int $depth = 0): array
	{
		if ($depth > self::MAX_DEPTH) {
			throw self::invalid('invalid_block', esc_html($where . ': blocks are nested too deeply.'));
		}
		if (!is_array($spec)) {
			throw self::invalid('invalid_block', esc_html($where . ' must be an object with markup or name.'));
		}
		$hasMarkup = array_key_exists('markup', $spec);
		$hasName = array_key_exists('name', $spec);
		if ($hasMarkup === $hasName) {
			throw self::invalid('invalid_block', esc_html($where . ' needs exactly one of markup or name.'));
		}
		if ($hasMarkup) {
			if (!is_string($spec['markup']) || count($spec) !== 1) {
				throw self::invalid('invalid_block', esc_html($where . '.markup must be a string and the only field.'));
			}
			$parsed = self::parse($spec['markup']);
			if (count($parsed) !== 1 || $parsed[0]['blockName'] === null) {
				throw self::invalid('invalid_block', esc_html($where . '.markup must contain exactly one root block delimited by <!-- wp:... -->.'));
			}
			$block = $parsed[0];
		} else {
			$name = $spec['name'];
			if (!is_string($name) || !preg_match('#' . self::NAME_PATTERN . '#', $name)) {
				throw self::invalid('invalid_block', esc_html($where . '.name must look like namespace/block.'));
			}
			$extra = array_diff(array_keys($spec), ['name', 'attrs', 'content', 'inner_blocks']);
			if ($extra) {
				throw self::invalid('invalid_block', esc_html($where . ': unknown field ' . (string) reset($extra) . '.'));
			}
			if (array_key_exists('content', $spec) && array_key_exists('inner_blocks', $spec)) {
				throw self::invalid('invalid_block', esc_html($where . ' takes content or inner_blocks, not both.'));
			}
			$attrs = $spec['attrs'] ?? [];
			if (!is_array($attrs) || ($attrs !== [] && array_keys($attrs) === range(0, count($attrs) - 1))) {
				throw self::invalid('invalid_block', esc_html($where . '.attrs must be an object.'));
			}
			$children = [];
			$content = '';
			if (array_key_exists('inner_blocks', $spec)) {
				if (!is_array($spec['inner_blocks']) || count($spec['inner_blocks']) > 100) {
					throw self::invalid('invalid_block', esc_html($where . '.inner_blocks must be a list of at most 100 blocks.'));
				}
				foreach (array_values($spec['inner_blocks']) as $j => $child) {
					$children[] = self::blockFromSpec($child, $where . '.inner_blocks[' . $j . ']', $warnings, $depth + 1);
				}
			} elseif (array_key_exists('content', $spec)) {
				if (!is_string($spec['content'])) {
					throw self::invalid('invalid_block', esc_html($where . '.content must be a string.'));
				}
				$content = $spec['content'];
			}
			$inner = [];
			foreach ($children as $j => $unused) {
				if ($j > 0) {
					$inner[] = "\n\n";
				}
				$inner[] = null;
			}
			$block = [
				'blockName' => $name,
				'attrs' => $attrs,
				'innerBlocks' => $children,
				'innerHTML' => $children ? '' : $content,
				'innerContent' => $children ? $inner : ($content === '' ? [] : [$content]),
			];
		}
		return self::sanitize($block, $where, $warnings, $depth);
	}

	/**
	 * Policy for one agent-supplied node and its subtree.
	 *
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private static function sanitize(array $block, string $where, array &$warnings, int $depth): array
	{
		if ($depth > self::MAX_DEPTH + 1) {
			throw self::invalid('invalid_block', esc_html($where . ': blocks are nested too deeply.'));
		}
		$name = $block['blockName'];
		if ($name !== null && !self::isRegistered((string) $name)) {
			$warnings[] = sprintf('%s: block type %s is not registered on the server (client-only blocks are allowed).', $where, (string) $name);
		}
		$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
		if ($name === 'core/html') {
			throw self::invalid('unfiltered_html_blocked', esc_html(sprintf('%s: agents cannot write core/html (Custom HTML) blocks.', $where)));
		}
		self::checkAttrs((string) $name, $attrs, $where);
		$changed = false;
		$inner = [];
		foreach ((array) ($block['innerContent'] ?? []) as $piece) {
			if (is_string($piece)) {
				self::gate($piece, $where);
				$clean = wp_kses_post($piece);
				$changed = $changed || $clean !== $piece;
				$piece = $clean;
			}
			$inner[] = $piece;
		}
		$block['innerContent'] = $inner;
		$block['innerHTML'] = implode('', array_filter($inner, 'is_string'));
		if ($changed) {
			$warnings[] = $where . ': disallowed HTML was removed.';
		}
		$children = [];
		foreach ((array) ($block['innerBlocks'] ?? []) as $j => $child) {
			$children[] = self::sanitize($child, $where . '.inner_blocks[' . $j . ']', $warnings, $depth + 1);
		}
		$block['innerBlocks'] = $children;
		return $block;
	}

	/** Script, iframe, style and inline event handlers are refused outright (kses would only hide them). */
	private static function gate(string $html, string $where): void
	{
		if (preg_match('/<\s*\/?\s*(script|iframe|style)\b/i', $html)) {
			throw self::invalid('unfiltered_html_blocked', esc_html($where . ' contains a script, iframe or style tag, which agents cannot write.'));
		}
		if (preg_match('/(?:^|[\s"\'\/])on[a-z]+\s*=/i', $html)) {
			throw self::invalid('unfiltered_html_blocked', esc_html($where . ' contains an inline event handler (on*=), which agents cannot write.'));
		}
	}

	/** Attributes are not filtered by kses; HTML-looking values are refused. */
	private static function checkAttrs(string $name, array $attrs, string $where): void
	{
		if (!$attrs) {
			return;
		}
		// Decoded values, not their JSON: escaped \n or \t would otherwise hide the separator before on*=.
		$coreKnown = strpos($name, 'core/') === 0 && self::isRegistered($name);
		foreach (self::attrStrings($attrs) as $value) {
			self::gate($value, $where . '.attrs');
			if (preg_match('/javascript\s*:/i', $value)) {
				throw self::invalid('unfiltered_html_blocked', esc_html($where . '.attrs contains a javascript: URL.'));
			}
			if (!$coreKnown && strpos($value, '<') !== false) {
				throw self::invalid('unfiltered_html_blocked', esc_html($where . '.attrs of a non-core block contain HTML, which agents cannot write.'));
			}
		}
	}

	/**
	 * attrs.className of an update also goes into the saved HTML, as the editor's save() puts it: on the block's root
	 * element, replacing the previous className classes. Refused (with the path and the reason) when that cannot be done
	 * safely, so the comment attributes and the HTML never disagree: unknown block, block without className support,
	 * invalid class names, saved HTML whose root element does not carry the current className.
	 * A block without saved HTML (server-rendered, like core/latest-posts) only changes the attribute: WordPress adds the
	 * class when it renders it. Blocks with a render callback that also save HTML (core/heading, core/image, core/button
	 * on WordPress 7.1) get the class in that HTML like any static block.
	 *
	 * @return array{add:list<string>}|null Null when only the attribute changes.
	 * @throws MutationFailure
	 */
	private static function classSyncPlan(array $node, mixed $value, ?string $content, string $where, string $path): ?array
	{
		$name = (string) ($node['blockName'] ?? '');
		$fail = static fn(string $reason): MutationFailure => self::invalid('class_name_not_synced', esc_html(sprintf('%s.attrs.className (block %s at %s): %s', $where, $name !== '' ? $name : 'classic', $path, $reason)));
		if ($value !== null && !is_string($value)) {
			throw $fail('className must be a string of class names, or null to remove it.');
		}
		$add = $value === null ? [] : array_values(array_unique(preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []));
		if (strlen((string) $value) > 500 || count($add) > 20) {
			throw $fail('use up to 20 class names and 500 characters.');
		}
		foreach ($add as $class) {
			if (!preg_match('/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/D', $class)) {
				throw $fail(sprintf('"%s" is not a valid class name (letters, digits, "-" and "_", not starting with a digit).', $class)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $fail() runs the whole message through esc_html().
			}
		}
		$type = $name !== '' ? \WP_Block_Type_Registry::get_instance()->get_registered($name) : null;
		if (!$type instanceof \WP_Block_Type) {
			throw $fail('the block type is not registered on this site, so where its class goes is unknown. Replace the block with markup that carries the class.');
		}
		// supports.className only controls the generated wp-block-* class; a custom className is supports.customClassName.
		if (is_array($type->supports) && array_key_exists('customClassName', $type->supports) && $type->supports['customClassName'] === false) {
			throw $fail('this block type does not support a custom className.');
		}
		$old = preg_split('/\s+/', trim((string) (is_array($node['attrs'] ?? null) ? ($node['attrs']['className'] ?? '') : '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$probe = $node;
		if ($content !== null) {
			$probe['innerHTML'] = $content;
			$probe['innerContent'] = [$content];
		}
		$root = self::rootTag($probe);
		if ($root === null) {
			if (trim(implode('', array_filter((array) ($probe['innerContent'] ?? []), 'is_string'))) === '') {
				return null;
			}
			throw $fail('the saved HTML does not start with an element to put the class on. Replace the block with markup that carries the class.');
		}
		if ($content === null) {
			$has = preg_split('/\s+/', trim((string) $root->get_attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			$missing = array_values(array_diff($old, $has));
			if ($missing) {
				throw $fail(sprintf('the saved HTML no longer carries the current className (%s), so it is already out of sync. Replace the block with markup.', implode(' ', $missing))); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $fail() runs the whole message through esc_html().
			}
		}
		return ['add' => $add];
	}

	/** Tag processor positioned on the first element of the block's saved HTML (only whitespace before it), or null. */
	private static function rootTag(array $node): ?\WP_HTML_Tag_Processor
	{
		$first = null;
		foreach ((array) ($node['innerContent'] ?? []) as $chunk) {
			if (is_string($chunk)) {
				$first = $chunk;
				break;
			}
		}
		if ($first === null || !preg_match('/^\s*<[a-zA-Z]/', $first)) {
			return null;
		}
		$processor = new \WP_HTML_Tag_Processor($first);
		return $processor->next_tag() ? $processor : null;
	}

	/** The node with its root element's classes changed (first HTML chunk only; innerHTML follows). */
	private static function withRootClasses(array $node, array $remove, array $add): array
	{
		$index = null;
		foreach ((array) ($node['innerContent'] ?? []) as $i => $chunk) {
			if (is_string($chunk)) {
				$index = $i;
				break;
			}
		}
		$processor = self::rootTag($node);
		if ($index === null || $processor === null) {
			return $node;
		}
		$classes = preg_split('/\s+/', trim((string) $processor->get_attribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (!array_diff($classes, $remove) && !$add) {
			// No class left: drop the attribute with the space before it, as the editor's save() writes <p>, not <p >.
			$marker = 'llamly-class-' . bin2hex(random_bytes(8));
			$processor->set_attribute('class', $marker);
			$html = (string) preg_replace('/\s+class="' . $marker . '"/', '', $processor->get_updated_html(), 1);
		} else {
			foreach ($remove as $class) {
				$processor->remove_class($class);
			}
			foreach ($add as $class) {
				$processor->add_class($class);
			}
			$html = $processor->get_updated_html();
		}
		$node['innerContent'][$index] = $html;
		$node['innerHTML'] = implode('', array_filter($node['innerContent'], 'is_string'));
		return $node;
	}

	/** @return list<string> Every string key and value of an attribute tree. */
	private static function attrStrings(array $attrs, int $depth = 0): array
	{
		if ($depth > 32) {
			throw self::invalid('invalid_block', 'Block attributes are nested too deeply.');
		}
		$out = [];
		foreach ($attrs as $key => $value) {
			if (is_string($key)) {
				$out[] = $key;
			}
			if (is_string($value)) {
				$out[] = $value;
			} elseif (is_array($value)) {
				$out = array_merge($out, self::attrStrings($value, $depth + 1));
			}
		}
		return $out;
	}

	private static function isRegistered(string $name): bool
	{
		return \WP_Block_Type_Registry::get_instance()->is_registered($name);
	}

	/** Static block = registered with a save() output (not server-rendered) or unknown to the server. */
	private static function isStatic(string $name): bool
	{
		if ($name === '') {
			return true;
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered($name);
		return !$type instanceof \WP_Block_Type || !$type->is_dynamic();
	}

	// ------------------------------------------------------------------ paths

	private static function existingPath(array $tree, mixed $path, string $where): string
	{
		if (!is_string($path) || !preg_match('/' . self::PATH_PATTERN . '/D', $path)) {
			throw self::invalid('invalid_path', esc_html($where . ' must be a dot path such as "0" or "2.1".'));
		}
		if (self::node($tree, $path) === null) {
			throw self::invalid('invalid_path', esc_html(sprintf('%s: no block at %s in the tree you read (paths refer to the tree before the batch).', $where, $path)));
		}
		return $path;
	}

	private static function position(mixed $position, string $where): string
	{
		if (!is_string($position) || !in_array($position, self::POSITIONS, true)) {
			throw self::invalid('invalid_operation', esc_html($where . '.position must be before, after, first_child or last_child.'));
		}
		return $position;
	}

	/** @return array<string, mixed>|null */
	public static function node(array $tree, string $path): ?array
	{
		$level = $tree;
		$node = null;
		foreach (explode('.', $path) as $segment) {
			$position = (int) $segment;
			$level = array_values($level);
			if (!isset($level[$position]) || !is_array($level[$position])) {
				return null;
			}
			$node = $level[$position];
			$level = is_array($node['innerBlocks'] ?? null) ? $node['innerBlocks'] : [];
		}
		return $node;
	}

	/** @return list<array<string, mixed>> */
	private static function replaceNode(array $tree, string $path, array $node): array
	{
		$segments = array_map('intval', explode('.', $path));
		$first = array_shift($segments);
		$tree = array_values($tree);
		if (!$segments) {
			$tree[$first] = $node;
			return $tree;
		}
		$parent = $tree[$first];
		$parent['innerBlocks'] = self::replaceNode((array) $parent['innerBlocks'], implode('.', $segments), $node);
		$tree[$first] = $parent;
		return $tree;
	}

	private static function isDescendant(string $path, string $root): bool
	{
		return strpos($path, $root . '.') === 0;
	}

	/** @return array<string, mixed> */
	private static function describe(array $tree, array $op, int $i): array
	{
		$item = ['index' => $i, 'op' => $op['op']];
		if ($op['op'] === 'move') {
			$item += ['from_path' => $op['from'], 'to_path' => $op['to'], 'position' => $op['position'], 'block' => self::node($tree, $op['from'])['blockName'] ?? 'core/freeform'];
			return $item;
		}
		$item['path'] = $op['path'];
		$item['target'] = self::node($tree, $op['path'])['blockName'] ?? 'core/freeform';
		if (isset($op['position'])) {
			$item['position'] = $op['position'];
		}
		if (isset($op['block'])) {
			$item['block'] = $op['block']['blockName'];
		}
		if ($op['op'] === 'update') {
			$item['changes'] = array_values(array_filter([$op['attrs'] !== null ? 'attrs' : null, $op['content'] !== null ? 'content' : null]));
		}
		return $item;
	}

	private static function invalid(string $code, string $message): MutationFailure
	{
		return new MutationFailure($code, $message, 400);
	}
}
