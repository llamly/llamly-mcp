<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\History\Adapters;

use Llamly\Free\History\Adapter;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\RawRows;
use Llamly\Free\History\State;

/**
 * Classic navigation menus. Object ID = term_id of the nav_menu term.
 *
 * State: the term and term_taxonomy rows, termmeta, every item (any status, drafts included) as its full wp_posts row
 * plus all of its raw meta and its term_order, the locations this menu holds, the full location map at capture time
 * (journal only, not hashed: it records who held a location before this menu took it), and auto_add membership.
 * Deleting and restoring keep term_id, term_taxonomy_id and every item ID. A location is handed back to this menu on
 * undo only while it is still free; otherwise the inverse is an undo_conflict.
 */
final class MenuObject implements Adapter
{
	public const MARKER = '_llamly_pending_operation';
	public const MAX_ITEMS = 500;
	public const MAX_DEPTH = 8;
	/** Larger menus are not deleted or rewritten reversibly. */
	public const MAX_STORED = 1000;
	public const ITEM_TYPES = ['custom', 'post_type', 'taxonomy', 'post_type_archive'];
	public const URL_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];
	private const META_EXCLUDE = '/^_llamly_pending_operation$|secret|token|api_?key|_key$|password|passwd|nonce|^auth_|salt|^llamly_|^mailserver_|user_roles$/i';
	private const INT_COLUMNS = ['ID', 'post_author', 'post_parent', 'menu_order', 'comment_count'];

	/** @var array<string, mixed> Details of the last forward write, merged into its stored result. */
	private static array $lastApply = [];

	// ------------------------------------------------------------------ helpers shared with MenuTools

	/** True when $id is an existing nav_menu term (read from the database). */
	public static function isMenu(int $id): bool
	{
		global $wpdb;
		if ($id < 1) {
			return false;
		}
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu'", $id)) > 0;
	}

	/** True when $id is a term of any taxonomy other than nav_menu (a menu ID that points elsewhere). */
	public static function isOtherTerm(int $id): bool
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy <> 'nav_menu'", $id)) > 0;
	}

	public static function revisionHash(int $id): ?string
	{
		$adapter = new self();
		return $adapter->fingerprint($adapter->capture($id));
	}

	/** Theme location map read from the stored theme_mods row (not the object cache). @return array<string, int> */
	public static function locationsMap(bool $forUpdate = false): array
	{
		$mods = self::rawOption('theme_mods_' . (string) get_option('stylesheet'), $forUpdate);
		$map = [];
		if (is_array($mods) && is_array($mods['nav_menu_locations'] ?? null)) {
			foreach ($mods['nav_menu_locations'] as $location => $menuId) {
				$map[(string) $location] = (int) $menuId;
			}
		}
		ksort($map, SORT_STRING);
		return $map;
	}

	/** @return list<int> */
	public static function autoAddIds(bool $forUpdate = false): array
	{
		$options = self::rawOption('nav_menu_options', $forUpdate);
		$ids = is_array($options) && is_array($options['auto_add'] ?? null) ? array_map('intval', $options['auto_add']) : [];
		$ids = array_values(array_unique($ids));
		sort($ids);
		return $ids;
	}

	private static function rawOption(string $name, bool $forUpdate): mixed
	{
		global $wpdb;
		$value = $forUpdate
			? $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 FOR UPDATE", $name))
			: $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
		if ($wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The menu settings could not be read from the database.', 'llamly'), 503);
		}
		return $value === null ? null : maybe_unserialize($value);
	}

	private static function flushOption(string $name): void
	{
		wp_cache_delete($name, 'options');
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	/** @param array<string, int> $map */
	private static function writeLocations(array $map): void
	{
		ksort($map, SORT_STRING);
		if ($map === self::locationsMap(true)) {
			return;
		}
		$option = 'theme_mods_' . (string) get_option('stylesheet');
		self::flushOption($option);
		set_theme_mod('nav_menu_locations', $map);
		self::flushOption($option);
		if (self::locationsMap() !== $map) {
			throw new MutationFailure('journal_unavailable', esc_html__('The menu locations could not be saved.', 'llamly'), 503);
		}
	}

	private static function writeAutoAdd(int $id, bool $on): void
	{
		$ids = self::autoAddIds(true);
		$has = in_array($id, $ids, true);
		if ($has === $on) {
			return;
		}
		self::flushOption('nav_menu_options');
		$options = get_option('nav_menu_options');
		$options = is_array($options) ? $options : [];
		$list = is_array($options['auto_add'] ?? null) ? array_map('intval', $options['auto_add']) : [];
		$list = $on ? array_merge($list, [$id]) : array_values(array_diff($list, [$id]));
		$options['auto_add'] = array_values(array_unique($list));
		// Core option: nav_menu_options (the menu's "automatically add new top-level pages" setting).
		update_option('nav_menu_options', $options);
		self::flushOption('nav_menu_options');
		if (in_array($id, self::autoAddIds(), true) !== $on) {
			throw new MutationFailure('journal_unavailable', esc_html__('The automatic page setting of the menu could not be saved.', 'llamly'), 503);
		}
	}

	/** First stored value of a meta key in a captured item ('' when absent). @param array<string, mixed> $item */
	public static function metaValue(array $item, string $key): string
	{
		foreach ((array) ($item['meta'] ?? []) as $row) {
			if ((string) $row['key'] === $key) {
				return (string) $row['value'];
			}
		}
		return '';
	}

	/** @return array{code:string,message:string,status:int}|null Why a delete must be refused before mutating. */
	public static function deleteBlocker(int $id): ?array
	{
		$state = (new self())->capture($id);
		if ($state === null) {
			return ['code' => 'object_not_found', 'message' => __('The menu was not found.', 'llamly'), 'status' => 404];
		}
		if ((int) $state['items_total'] > self::MAX_STORED) {
			return ['code' => 'too_many_items', 'message' => __('This menu has too many items to delete reversibly.', 'llamly'), 'status' => 409];
		}
		return self::protectedMetaBlocker($id, array_map(static fn(array $item): int => (int) $item['row']['ID'], $state['items']));
	}

	/**
	 * Metadata matching the secret pattern is never journaled, so removing an object that carries it would lose it.
	 *
	 * @param list<int> $itemIds
	 * @return array{code:string,message:string,status:int}|null
	 */
	public static function protectedMetaBlocker(int $menuId, array $itemIds): ?array
	{
		global $wpdb;
		$keys = (array) $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->termmeta} WHERE term_id = %d", $menuId));
		foreach (array_chunk($itemIds, 500) as $chunk) {
			$keys = array_merge($keys, (array) $wpdb->get_col($wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')',
				$chunk
			)));
		}
		foreach ($keys as $key) {
			if ((string) $key !== self::MARKER && preg_match(self::META_EXCLUDE, (string) $key)) {
				return ['code' => 'sensitive_meta', 'message' => __('This menu carries protected metadata that cannot be journaled, so it cannot be changed reversibly.', 'llamly'), 'status' => 409];
			}
		}
		return null;
	}

	/**
	 * Validate and normalize the flattened item tree against the current items of the menu. Every node is checked
	 * before anything is written: type, target object, URL protocol, size and depth. Existing items (db_id) keep the
	 * values of fields the node omits.
	 *
	 * @param list<array<string, mixed>> $nodes Flattened depth-first: each has 'parent' (index of an earlier node or -1) and 'depth'.
	 * @param array<int, array<string, mixed>> $current Current items keyed by ID.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function normalizeNodes(array $nodes, array $current, int $userId): array|\WP_Error
	{
		if (count($nodes) > self::MAX_ITEMS) {
			return Engine::error('too_many_items', sprintf(/* translators: %d: maximum number of menu items. */ __('A menu can have at most %d items.', 'llamly'), self::MAX_ITEMS), 400);
		}
		$seen = [];
		$out = [];
		foreach ($nodes as $index => $node) {
			$where = sprintf('items[%d]', $index);
			if ((int) ($node['depth'] ?? 1) > self::MAX_DEPTH) {
				return Engine::error('too_deep', sprintf(/* translators: %d: maximum nesting depth. */ __('Menu items can be nested at most %d levels deep.', 'llamly'), self::MAX_DEPTH), 400);
			}
			$parent = (int) ($node['parent'] ?? -1);
			if ($parent >= $index) {
				return Engine::error('invalid_item', $where . ': ' . __('invalid parent reference.', 'llamly'), 400);
			}
			$dbId = isset($node['db_id']) ? (int) $node['db_id'] : 0;
			$existing = null;
			if ($dbId > 0) {
				if (!isset($current[$dbId])) {
					return Engine::error('invalid_item', $where . ': ' . __('db_id is not an item of this menu.', 'llamly'), 400);
				}
				if (isset($seen[$dbId])) {
					return Engine::error('invalid_item', $where . ': ' . __('the same db_id appears twice.', 'llamly'), 400);
				}
				$seen[$dbId] = true;
				$existing = $current[$dbId];
			}
			$field = static function (string $name, string $metaKey, string $column = '') use ($node, $existing): string {
				if (array_key_exists($name, $node)) {
					return is_array($node[$name]) ? implode(' ', array_map('strval', $node[$name])) : (string) $node[$name];
				}
				if ($existing === null) {
					return '';
				}
				if ($column !== '') {
					return (string) $existing['row'][$column];
				}
				$value = self::metaValue($existing, $metaKey);
				if ($name === 'classes') {
					$list = maybe_unserialize($value);
					return is_array($list) ? trim(implode(' ', array_map('strval', $list))) : '';
				}
				return $value;
			};
			$type = $field('type', '_menu_item_type');
			if ($type === '') {
				$type = 'custom';
			}
			if (!in_array($type, self::ITEM_TYPES, true)) {
				return Engine::error('invalid_item', $where . ': ' . __('unknown item type.', 'llamly'), 400);
			}
			$object = $field('object', '_menu_item_object');
			$objectId = array_key_exists('object_id', $node) ? (int) $node['object_id'] : ($existing !== null ? (int) self::metaValue($existing, '_menu_item_object_id') : 0);
			$title = $field('title', '', 'post_title');
			$url = '';
			if ($type === 'custom') {
				$url = self::cleanUrl($field('url', '_menu_item_url'));
				if ($url === null) {
					return Engine::error('invalid_url', $where . ': ' . __('the URL must use http, https, mailto or tel (or be relative).', 'llamly'), 400);
				}
				if ($url === '') {
					return Engine::error('invalid_item', $where . ': ' . __('a custom link needs a url.', 'llamly'), 400);
				}
				if (trim($title) === '') {
					return Engine::error('invalid_item', $where . ': ' . __('a custom link needs a title.', 'llamly'), 400);
				}
				$object = 'custom';
				$objectId = 0;
			} elseif ($type === 'post_type') {
				$typeObject = get_post_type_object($object);
				$post = $objectId > 0 ? get_post($objectId) : null;
				if (!$typeObject instanceof \WP_Post_Type || $object === 'nav_menu_item' || (!$typeObject->public && !$typeObject->show_in_nav_menus)) {
					return Engine::error('invalid_item', $where . ': ' . __('object must be a public post type.', 'llamly'), 400);
				}
				if (!$post instanceof \WP_Post || $post->post_type !== $object || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
					return Engine::error('target_not_found', $where . ': ' . __('object_id is not an existing item of that post type.', 'llamly'), 400);
				}
				if ($post->post_status !== 'publish' && !user_can($userId, 'read_post', $post->ID)) {
					return Engine::error('forbidden', $where . ': ' . __('the connected user cannot read the target item.', 'llamly'), 403);
				}
			} elseif ($type === 'taxonomy') {
				if ($object === '' || in_array($object, TermObject::INTERNAL, true) || !taxonomy_exists($object)) {
					return Engine::error('invalid_item', $where . ': ' . __('object must be a taxonomy that menus can link to.', 'llamly'), 400);
				}
				$term = $objectId > 0 ? get_term($objectId, $object) : null;
				if (!$term instanceof \WP_Term) {
					return Engine::error('target_not_found', $where . ': ' . __('object_id is not an existing term of that taxonomy.', 'llamly'), 400);
				}
			} else {
				$typeObject = get_post_type_object($object);
				if (!$typeObject instanceof \WP_Post_Type || empty($typeObject->has_archive)) {
					return Engine::error('target_not_found', $where . ': ' . __('object must be a post type with an archive.', 'llamly'), 400);
				}
				$objectId = 0;
			}
			$target = $field('target', '_menu_item_target');
			if (!in_array($target, ['', '_blank'], true)) {
				return Engine::error('invalid_item', $where . ': ' . __('target must be empty or _blank.', 'llamly'), 400);
			}
			$out[] = [
				'db_id' => $dbId,
				'parent' => $parent,
				'type' => $type,
				'object' => $object,
				'object_id' => $objectId,
				'title' => $title,
				'url' => $url,
				'target' => $target,
				'classes' => $field('classes', '_menu_item_classes'),
				'description' => $field('description', '', 'post_content'),
				'attr_title' => $field('attr_title', '', 'post_excerpt'),
				'xfn' => $field('xfn', '_menu_item_xfn'),
				'status' => $existing !== null ? (string) $existing['row']['post_status'] : 'publish',
			];
		}
		return $out;
	}

	/** Sanitized URL, '' for empty, null when the protocol is not allowed. */
	public static function cleanUrl(string $url): ?string
	{
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$compact = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));
		if (preg_match('/^(javascript|vbscript|data):/', $compact) || str_contains($compact, 'javascript:')) {
			return null;
		}
		if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $compact, $m) && !in_array($m[1], self::URL_PROTOCOLS, true)) {
			return null;
		}
		$clean = esc_url_raw($url, self::URL_PROTOCOLS);
		return $clean === '' ? null : $clean;
	}

	// ------------------------------------------------------------------ Adapter

	public function type(): string
	{
		return 'menu';
	}

	public function prefix(): string
	{
		return 'n1';
	}

	public function capture(int $id, bool $forUpdate = false): ?array
	{
		global $wpdb;
		if ($id < 1) {
			return null;
		}
		$row = $forUpdate
			? $wpdb->get_row($wpdb->prepare("SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = 'nav_menu' LIMIT 1 FOR UPDATE", $id), ARRAY_A)
			: $wpdb->get_row($wpdb->prepare("SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = 'nav_menu' LIMIT 1", $id), ARRAY_A);
		if ($wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The menu could not be read from the database.', 'llamly'), 503);
		}
		if (!is_array($row)) {
			return null;
		}
		$termMeta = RawRows::captureMeta($wpdb->termmeta, 'term_id', $id, null, self::META_EXCLUDE, $forUpdate);
		if ($termMeta === null) {
			throw new MutationFailure('journal_unavailable', esc_html__('The menu metadata could not be read from the database.', 'llamly'), 503);
		}
		$tt = (int) $row['term_taxonomy_id'];
		// All statuses: drafts left by the Customizer are part of the menu (wp_get_nav_menu_items() only returns published).
		$rows = $forUpdate
			? $wpdb->get_results($wpdb->prepare("SELECT p.*, tr.term_order AS llamly_term_order FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item' ORDER BY p.menu_order ASC, p.ID ASC FOR UPDATE", $tt), ARRAY_A)
			: $wpdb->get_results($wpdb->prepare("SELECT p.*, tr.term_order AS llamly_term_order FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item' ORDER BY p.menu_order ASC, p.ID ASC", $tt), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The menu items could not be read from the database.', 'llamly'), 503);
		}
		$ids = array_map(static fn(array $r): int => (int) $r['ID'], $rows);
		$metaByItem = $this->itemMeta($ids, $forUpdate);
		$items = [];
		foreach ($rows as $itemRow) {
			$order = (int) $itemRow['llamly_term_order'];
			unset($itemRow['llamly_term_order']);
			foreach ($itemRow as $column => $value) {
				$itemRow[$column] = in_array($column, self::INT_COLUMNS, true) ? (int) $value : (string) $value;
			}
			$items[] = ['row' => $itemRow, 'meta' => $metaByItem[(int) $itemRow['ID']] ?? [], 'term_order' => $order];
		}
		$map = self::locationsMap($forUpdate);
		$held = array_keys(array_filter($map, static fn(int $menu): bool => $menu === $id));
		sort($held, SORT_STRING);
		return [
			'menu_id' => (int) $row['term_id'],
			'term_taxonomy_id' => $tt,
			'name' => (string) $row['name'],
			'slug' => (string) $row['slug'],
			'term_group' => (int) $row['term_group'],
			'description' => (string) $row['description'],
			'parent' => (int) $row['parent'],
			'term_meta' => $termMeta,
			'items' => $items,
			'items_total' => count($items),
			'locations' => array_values(array_map('strval', $held)),
			'auto_add' => in_array($id, self::autoAddIds($forUpdate), true),
			// Journal only (not hashed): who held each location when this state was captured.
			'locations_map' => $map,
			'theme' => (string) get_option('stylesheet'),
			'count' => (int) $row['count'],
		];
	}

	/** @param list<int> $ids @return array<int, list<array{key:string,value:string}>> */
	private function itemMeta(array $ids, bool $forUpdate): array
	{
		global $wpdb;
		$out = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$rows = $forUpdate
				? $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY meta_id ASC FOR UPDATE', $chunk), ARRAY_A)
				: $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY meta_id ASC', $chunk), ARRAY_A);
			if (!is_array($rows) || $wpdb->last_error !== '') {
				throw new MutationFailure('journal_unavailable', esc_html__('The menu item metadata could not be read from the database.', 'llamly'), 503);
			}
			foreach ($rows as $row) {
				$key = (string) $row['meta_key'];
				if (preg_match(self::META_EXCLUDE, $key)) {
					continue;
				}
				$out[(int) $row['post_id']][] = ['key' => $key, 'value' => (string) $row['meta_value']];
			}
		}
		return $out;
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return State::hash('n1', null);
		}
		$items = [];
		foreach ((array) ($state['items'] ?? []) as $item) {
			$row = (array) $item['row'];
			unset($row['comment_count']);
			$items[] = ['row' => $row, 'meta' => $item['meta'], 'term_order' => (int) $item['term_order']];
		}
		$hashed = array_intersect_key($state, array_flip(['menu_id', 'term_taxonomy_id', 'name', 'slug', 'term_group', 'description', 'parent', 'term_meta', 'locations', 'auto_add']));
		$hashed['items'] = $items;
		return State::hash('n1', $hashed);
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		self::$lastApply = [];
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'create') {
			$created = wp_create_nav_menu(wp_slash((string) ($fields['name'] ?? '')));
			if (is_wp_error($created)) {
				throw new MutationFailure(esc_html($created->get_error_code() ?: 'mutation_failed'), esc_html($created->get_error_message()), 409);
			}
			$menuId = (int) $created;
			global $wpdb;
			if ($menuId < 1 || $wpdb->insert($wpdb->termmeta, ['term_id' => $menuId, 'meta_key' => self::MARKER, 'meta_value' => $operationId], ['%d', '%s', '%s']) !== 1) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('The creation marker could not be stored.', 'llamly'), 503);
			}
			wp_cache_delete($menuId, 'term_meta');
			if (isset($fields['locations'])) {
				self::$lastApply['displaced_locations'] = $this->assignLocations($menuId, (array) $fields['locations']);
			}
			if (isset($fields['auto_add'])) {
				self::writeAutoAdd($menuId, (bool) $fields['auto_add']);
			}
			clean_term_cache($menuId, 'nav_menu');
			return $menuId;
		}
		$current = $this->capture($id, true);
		if ($current === null) {
			throw new MutationFailure('object_not_found', esc_html__('The menu was not found.', 'llamly'), 404);
		}
		if ($op === 'update') {
			if (isset($fields['name'])) {
				$updated = wp_update_nav_menu_object($id, wp_slash([
					'menu-name' => (string) $fields['name'],
					'description' => $current['description'],
					'parent' => $current['parent'],
				]));
				if (is_wp_error($updated)) {
					throw new MutationFailure(esc_html($updated->get_error_code() ?: 'mutation_failed'), esc_html($updated->get_error_message()), 409);
				}
			}
			if (isset($fields['locations'])) {
				self::$lastApply['displaced_locations'] = $this->assignLocations($id, (array) $fields['locations']);
			}
			if (isset($fields['auto_add'])) {
				self::writeAutoAdd($id, (bool) $fields['auto_add']);
			}
			clean_term_cache($id, 'nav_menu');
			return $id;
		}
		if ($op === 'delete') {
			$this->remove($id, $current, null);
			return $id;
		}
		if ($op === 'set_items') {
			$this->setItems($id, $current, $intent, (int) ($auth['user_id'] ?? 0));
			return $id;
		}
		throw new MutationFailure('invalid_arguments', esc_html__('Unknown menu operation.', 'llamly'), 400);
	}

	/**
	 * Give this menu exactly the $wanted locations. Locations held by another menu move here; the previous holder is
	 * returned so an undo can hand them back.
	 *
	 * Every touched key's previous value is kept too (null = the key was absent) so an undo restores the map exactly.
	 *
	 * @param list<string> $wanted
	 * @return array<string, int>
	 */
	private function assignLocations(int $id, array $wanted): array
	{
		$registered = array_keys(get_registered_nav_menus());
		$live = self::locationsMap(true);
		$map = $live;
		$displaced = [];
		$previous = [];
		foreach ($wanted as $location) {
			if (!in_array((string) $location, $registered, true)) {
				throw new MutationFailure('unknown_location', esc_html(sprintf(/* translators: %s: menu location slug. */ __('The theme does not register the menu location "%s".', 'llamly'), (string) $location)), 400);
			}
		}
		foreach ($live as $location => $holder) {
			if ($holder === $id && !in_array($location, $wanted, true)) {
				$map[$location] = 0;
			}
		}
		foreach ($wanted as $location) {
			$location = (string) $location;
			$previous[$location] = array_key_exists($location, $live) ? (int) $live[$location] : null;
			$holder = (int) ($live[$location] ?? 0);
			if ($holder !== $id && $holder > 0 && self::isMenu($holder)) {
				$displaced[$location] = $holder;
			}
			$map[$location] = $id;
		}
		self::writeLocations($map);
		ksort($displaced, SORT_STRING);
		ksort($previous, SORT_STRING);
		self::$lastApply['previous_locations'] = $previous;
		return $displaced;
	}

	/** @param array<string, mixed> $current */
	private function setItems(int $id, array $current, array $intent, int $userId): void
	{
		global $wpdb;
		if ((int) $current['items_total'] > self::MAX_STORED) {
			throw new MutationFailure('too_many_items', esc_html__('This menu has too many items to rewrite reversibly.', 'llamly'), 409);
		}
		$byId = [];
		foreach ($current['items'] as $item) {
			$byId[(int) $item['row']['ID']] = $item;
		}
		$nodes = self::normalizeNodes((array) ($intent['nodes'] ?? []), $byId, $userId);
		if (is_wp_error($nodes)) {
			throw new MutationFailure(esc_html($nodes->get_error_code()), esc_html($nodes->get_error_message()), (int) ($nodes->get_error_data()['status'] ?? 400));
		}
		$replace = ($intent['mode'] ?? 'replace') !== 'merge';
		$cited = array_values(array_filter(array_map(static fn(array $n): int => (int) $n['db_id'], $nodes)));
		$uncited = array_values(array_diff(array_keys($byId), $cited));
		if ($replace && $uncited) {
			$blocker = self::protectedMetaBlocker(0, $uncited);
			if ($blocker !== null) {
				throw new MutationFailure(esc_html($blocker['code']), esc_html($blocker['message']), (int) $blocker['status']);
			}
		}
		$ids = [];
		$position = 1;
		foreach ($nodes as $index => $node) {
			$parentId = $node['parent'] >= 0 ? (int) $ids[$node['parent']] : 0;
			$saved = wp_update_nav_menu_item($id, (int) $node['db_id'], wp_slash([
				'menu-item-object-id' => (int) $node['object_id'],
				'menu-item-object' => (string) $node['object'],
				'menu-item-parent-id' => $parentId,
				'menu-item-position' => $position++,
				'menu-item-type' => (string) $node['type'],
				'menu-item-title' => (string) $node['title'],
				'menu-item-url' => (string) $node['url'],
				'menu-item-description' => (string) $node['description'],
				'menu-item-attr-title' => (string) $node['attr_title'],
				'menu-item-target' => (string) $node['target'],
				'menu-item-classes' => (string) $node['classes'],
				'menu-item-xfn' => (string) $node['xfn'],
				'menu-item-status' => (string) $node['status'],
			]));
			if (is_wp_error($saved) || (int) $saved < 1) {
				if (is_wp_error($saved)) {
					throw new MutationFailure(esc_html($saved->get_error_code() ?: 'mutation_failed'), esc_html($saved->get_error_message()), 409);
				}
				throw new MutationFailure('mutation_failed', esc_html__('WordPress did not save a menu item.', 'llamly'), 409);
			}
			$ids[$index] = (int) $saved;
		}
		$removed = [];
		if ($replace) {
			foreach ($uncited as $itemId) {
				if (!wp_delete_post($itemId, true)) {
					throw new MutationFailure('mutation_failed', esc_html__('WordPress did not remove a menu item.', 'llamly'), 409);
				}
				$removed[] = $itemId;
			}
		} else {
			// Merge: items the request did not mention keep their relative order after the listed ones.
			foreach ($current['items'] as $item) {
				$itemId = (int) $item['row']['ID'];
				if (in_array($itemId, $cited, true)) {
					continue;
				}
				if ($wpdb->update($wpdb->posts, ['menu_order' => $position++], ['ID' => $itemId], ['%d'], ['%d']) === false) {
					throw new MutationFailure('journal_unavailable', esc_html__('The menu order could not be saved.', 'llamly'), 503);
				}
				clean_post_cache($itemId);
			}
		}
		clean_term_cache($id, 'nav_menu');
		self::$lastApply['item_ids'] = array_values($ids);
		self::$lastApply['removed_item_ids'] = $removed;
	}

	/**
	 * Delete the menu with WordPress (items, term, locations) and settle locations and auto_add explicitly.
	 *
	 * @param array<string, mixed> $current
	 * @param array<string, int>|null $locations Final location map; null zeroes the locations held by this menu.
	 */
	private function remove(int $id, array $current, ?array $locations): void
	{
		global $wpdb;
		$blocker = self::deleteBlocker($id);
		if ($blocker !== null) {
			throw new MutationFailure(esc_html($blocker['code']), esc_html($blocker['message']), (int) $blocker['status']);
		}
		if ($locations === null) {
			$locations = self::locationsMap(true);
			foreach ($locations as $location => $holder) {
				if ($holder === $id) {
					$locations[$location] = 0;
				}
			}
		}
		$itemIds = array_map(static fn(array $item): int => (int) $item['row']['ID'], $current['items']);
		$deleted = wp_delete_nav_menu($id);
		if (is_wp_error($deleted)) {
			throw new MutationFailure(esc_html($deleted->get_error_code() ?: 'mutation_failed'), esc_html($deleted->get_error_message()), 409);
		}
		if ($deleted !== true) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not delete the menu.', 'llamly'), 409);
		}
		self::writeLocations($locations);
		self::writeAutoAdd($id, false);
		foreach (array_chunk($itemIds, 500) as $chunk) {
			$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')', $chunk));
			if ($left > 0) {
				throw new MutationFailure('restore_mismatch', esc_html__('WordPress left menu items behind, so the delete was cancelled.', 'llamly'), 409);
			}
		}
		self::$lastApply['removed_item_ids'] = $itemIds;
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		global $wpdb;
		self::$lastApply = [];
		$current = $this->capture($id, true);
		$lineage = $this->lineage($operationId);
		$locations = $this->locationsFor($id, $target, $lineage);
		if ($target === null) {
			if ($current !== null) {
				$this->remove($id, $current, $locations);
			}
			return $id;
		}
		$tt = (int) $target['term_taxonomy_id'];
		$slugTaken = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = 'nav_menu' AND t.term_id <> %d",
			(string) $target['slug'],
			$id
		));
		if ($slugTaken > 0) {
			throw new MutationFailure('undo_conflict', esc_html__('Another menu now uses this slug.', 'llamly'), 409);
		}
		if ($current === null) {
			RawRows::reinsert($wpdb->terms, 'term_id', ['term_id' => $id, 'name' => (string) $target['name'], 'slug' => (string) $target['slug'], 'term_group' => (int) $target['term_group']]);
			RawRows::reinsert($wpdb->term_taxonomy, 'term_taxonomy_id', ['term_taxonomy_id' => $tt, 'term_id' => $id, 'taxonomy' => 'nav_menu', 'description' => (string) $target['description'], 'parent' => (int) $target['parent'], 'count' => 0]);
			$orphans = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt));
			if ($orphans > 0) {
				throw new MutationFailure('undo_conflict', esc_html__('Leftover relationships already use this menu identifier.', 'llamly'), 409);
			}
			$currentItems = [];
		} else {
			if ((int) $current['term_taxonomy_id'] !== $tt) {
				throw new MutationFailure('undo_conflict', esc_html__('The menu was recreated with another identifier.', 'llamly'), 409);
			}
			if ($wpdb->update($wpdb->terms, ['name' => (string) $target['name'], 'slug' => (string) $target['slug'], 'term_group' => (int) $target['term_group']], ['term_id' => $id], ['%s', '%s', '%d'], ['%d']) === false
				|| $wpdb->update($wpdb->term_taxonomy, ['description' => (string) $target['description'], 'parent' => (int) $target['parent']], ['term_taxonomy_id' => $tt], ['%s', '%d'], ['%d']) === false
			) {
				throw new MutationFailure('journal_unavailable', esc_html__('The menu rows could not be restored.', 'llamly'), 503);
			}
			$currentItems = [];
			foreach ($current['items'] as $item) {
				$currentItems[(int) $item['row']['ID']] = $item;
			}
		}
		RawRows::restoreMeta($wpdb->termmeta, 'term_id', $id, (array) $target['term_meta'], null, self::META_EXCLUDE, 'term_meta');

		$targetIds = [];
		foreach ((array) $target['items'] as $item) {
			$targetIds[(int) $item['row']['ID']] = true;
		}
		$touched = [];
		foreach (array_keys($currentItems) as $itemId) {
			if (!isset($targetIds[$itemId])) {
				$this->dropItem($itemId);
				$touched[] = $itemId;
			}
		}
		foreach ((array) $target['items'] as $item) {
			$row = (array) $item['row'];
			$itemId = (int) $row['ID'];
			if (isset($currentItems[$itemId])) {
				$formats = [];
				$update = $row;
				unset($update['ID']);
				foreach ($update as $column => $value) {
					$formats[] = in_array($column, self::INT_COLUMNS, true) ? '%d' : '%s';
				}
				if ($wpdb->update($wpdb->posts, $update, ['ID' => $itemId], $formats, ['%d']) === false) {
					throw new MutationFailure('journal_unavailable', esc_html__('A menu item could not be restored.', 'llamly'), 503);
				}
			} else {
				RawRows::reinsert($wpdb->posts, 'ID', $row);
				$foreign = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d", $itemId));
				if ($foreign > 0) {
					throw new MutationFailure('undo_conflict', esc_html__('Leftover relationships already use a menu item identifier.', 'llamly'), 409);
				}
			}
			RawRows::restoreMeta($wpdb->postmeta, 'post_id', $itemId, (array) $item['meta'], null, self::META_EXCLUDE, 'post_meta');
			if ($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d", $itemId, $tt)) === false
				|| $wpdb->insert($wpdb->term_relationships, ['object_id' => $itemId, 'term_taxonomy_id' => $tt, 'term_order' => (int) $item['term_order']], ['%d', '%d', '%d']) !== 1
			) {
				throw new MutationFailure('journal_unavailable', esc_html__('A menu item relationship could not be restored.', 'llamly'), 503);
			}
			$touched[] = $itemId;
		}
		wp_update_term_count_now([$tt], 'nav_menu');
		self::writeLocations($locations);
		self::writeAutoAdd($id, (bool) $target['auto_add']);
		foreach ($touched as $itemId) {
			clean_post_cache($itemId);
		}
		if ($touched) {
			clean_object_term_cache($touched, 'nav_menu_item');
		}
		clean_term_cache($id, 'nav_menu');
		return $id;
	}

	/** Remove one item row with its meta and relationships (inverse path; runs inside the transaction). */
	private function dropItem(int $itemId): void
	{
		global $wpdb;
		if ($wpdb->delete($wpdb->postmeta, ['post_id' => $itemId], ['%d']) === false
			|| $wpdb->delete($wpdb->term_relationships, ['object_id' => $itemId], ['%d']) === false
			|| $wpdb->delete($wpdb->posts, ['ID' => $itemId], ['%d']) === false
		) {
			throw new MutationFailure('journal_unavailable', esc_html__('A menu item could not be removed.', 'llamly'), 503);
		}
		wp_cache_delete($itemId, 'post_meta');
	}

	/**
	 * Which change an inverse reverses: mode undo|redo and the original forward operation row.
	 *
	 * @return array{mode:string,original:?array}
	 */
	private function lineage(string $operationId): array
	{
		$operation = Engine::operation($operationId);
		if ($operation && !empty($operation['redo_of'])) {
			$undo = Engine::operation((string) $operation['redo_of']);
			return ['mode' => 'redo', 'original' => $undo && !empty($undo['undo_of']) ? Engine::operation((string) $undo['undo_of']) : null];
		}
		if ($operation && !empty($operation['undo_of'])) {
			return ['mode' => 'undo', 'original' => Engine::operation((string) $operation['undo_of'])];
		}
		return ['mode' => 'undo', 'original' => null];
	}

	/**
	 * Location map before the original change: its journaled before state, or for a create the previous values it stored
	 * in its result. A key mapped to null (or missing) was absent.
	 *
	 * @return array<string, int|null>
	 */
	private function displacedBy(?array $original): array
	{
		if ($original === null) {
			return [];
		}
		$before = json_decode((string) ($original['before_data'] ?? ''), true);
		if (is_array($before) && isset($before['locations_map']) && is_array($before['locations_map'])) {
			return array_map('intval', $before['locations_map']);
		}
		$result = json_decode((string) ($original['result_data'] ?? ''), true);
		$previous = is_array($result) && is_array($result['previous_locations'] ?? null) ? $result['previous_locations'] : [];
		return array_map(static fn($v): ?int => $v === null ? null : (int) $v, $previous);
	}

	/**
	 * Final location map for an inverse. Locations this menu holds but the target does not go back to whoever held them
	 * on the other side of the change (or become empty). Locations the target holds are taken back only while free; on a
	 * redo, also from the menu the original change displaced. Anything else is an undo_conflict.
	 *
	 * @param array<string, mixed>|null $target
	 * @param array{mode:string,original:?array} $lineage
	 * @return array<string, int>
	 */
	private function locationsFor(int $id, ?array $target, array $lineage): array
	{
		$live = self::locationsMap(true);
		$map = $live;
		$wanted = $target !== null ? array_map('strval', (array) $target['locations']) : [];
		$displaced = $this->displacedBy($lineage['original']);
		if ($wanted && (string) ($target['theme'] ?? '') !== (string) get_option('stylesheet')) {
			throw new MutationFailure('undo_conflict', esc_html__('The active theme changed, so the menu locations cannot be restored.', 'llamly'), 409);
		}
		foreach ($live as $location => $holder) {
			if ($holder !== $id || in_array($location, $wanted, true)) {
				continue;
			}
			// Undo hands the key back as it was before the change; redo as it was after it (a deleted menu leaves 0).
			$reference = $lineage['mode'] === 'undo' ? $displaced : ($target !== null ? (array) ($target['locations_map'] ?? []) : [$location => 0]);
			if (!array_key_exists($location, $reference) || $reference[$location] === null) {
				unset($map[$location]);
				continue;
			}
			$give = (int) $reference[$location];
			$map[$location] = ($give !== $id && $give > 0 && self::isMenu($give)) ? $give : 0;
		}
		foreach ($wanted as $location) {
			$holder = (int) ($live[$location] ?? 0);
			if ($holder === $id) {
				continue;
			}
			$free = $holder === 0 || !self::isMenu($holder)
				|| ($lineage['mode'] === 'redo' && $holder === (int) ($displaced[$location] ?? -1));
			if (!$free) {
				throw new MutationFailure('undo_conflict', esc_html(sprintf(/* translators: %s: menu location slug. */ __('The menu location "%s" is now used by another menu.', 'llamly'), $location)), 409);
			}
			$map[$location] = $id;
		}
		return $map;
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if (isset($fields['name']) && $current['name'] !== (string) sanitize_term_field('name', (string) $fields['name'], 0, 'nav_menu', 'db')) {
			return false;
		}
		if (isset($fields['locations'])) {
			$wanted = array_values(array_unique(array_map('strval', (array) $fields['locations'])));
			sort($wanted, SORT_STRING);
			if ($current['locations'] !== $wanted) {
				return false;
			}
		}
		if (isset($fields['auto_add']) && $current['auto_add'] !== (bool) $fields['auto_add']) {
			return false;
		}
		if ($op === 'create') {
			return $current['items'] === [];
		}
		if ($before === null) {
			return true;
		}
		if ($before['term_taxonomy_id'] !== $current['term_taxonomy_id']) {
			return false;
		}
		if ($op === 'update') {
			return $before['items'] === $current['items'] && $before['term_meta'] === $current['term_meta'];
		}
		if ($op === 'set_items') {
			return $before['locations'] === $current['locations'] && $this->itemsMatch($before, $current, $intent);
		}
		return true;
	}

	/** Structural check of a set_items result: order, IDs, types, targets, parents and custom URLs. */
	private function itemsMatch(array $before, array $current, array $intent): bool
	{
		$nodes = (array) ($intent['nodes'] ?? []);
		$items = (array) $current['items'];
		$cited = array_values(array_filter(array_map(static fn($n): int => (int) ($n['db_id'] ?? 0), $nodes)));
		$uncited = [];
		foreach ((array) $before['items'] as $item) {
			if (!in_array((int) $item['row']['ID'], $cited, true)) {
				$uncited[] = (int) $item['row']['ID'];
			}
		}
		$merge = ($intent['mode'] ?? 'replace') === 'merge';
		if (count($items) !== count($nodes) + ($merge ? count($uncited) : 0)) {
			return false;
		}
		$ids = [];
		foreach ($nodes as $index => $node) {
			$item = $items[$index];
			$itemId = (int) $item['row']['ID'];
			$dbId = (int) ($node['db_id'] ?? 0);
			if (($dbId > 0 && $itemId !== $dbId) || ($dbId === 0 && in_array($itemId, array_map(static fn($i): int => (int) $i['row']['ID'], (array) $before['items']), true))) {
				return false;
			}
			$ids[$index] = $itemId;
			$parent = (int) ($node['parent'] ?? -1);
			if ((int) self::metaValue($item, '_menu_item_menu_item_parent') !== ($parent >= 0 ? $ids[$parent] : 0)) {
				return false;
			}
			if (isset($node['type']) && self::metaValue($item, '_menu_item_type') !== (string) $node['type']) {
				return false;
			}
			if (isset($node['object_id']) && ($node['type'] ?? '') !== 'custom' && ($node['type'] ?? '') !== 'post_type_archive' && (int) self::metaValue($item, '_menu_item_object_id') !== (int) $node['object_id']) {
				return false;
			}
			if (($node['type'] ?? self::metaValue($item, '_menu_item_type')) === 'custom' && isset($node['url']) && self::metaValue($item, '_menu_item_url') !== (string) self::cleanUrl((string) $node['url'])) {
				return false;
			}
		}
		if ($merge) {
			$tail = array_map(static fn($i): int => (int) $i['row']['ID'], array_slice($items, count($nodes)));
			return $tail === $uncited;
		}
		return true;
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		$held = static fn(?array $s): array => $s !== null ? array_map('strval', (array) ($s['locations'] ?? [])) : [];
		$itemIds = static fn(?array $s): array => $s !== null ? array_map(static fn($i): int => (int) $i['row']['ID'], (array) ($s['items'] ?? [])) : [];
		$none = ['destructive' => false, 'public' => false, 'sensitive' => false, 'external_effects' => []];
		if ($op === 'undo' || $op === 'redo') {
			$lostItems = $before !== null && array_diff($itemIds($before), $itemIds($after)) !== [];
			$lostLocations = array_diff($held($before), $held($after)) !== [];
			return [
				'destructive' => ($before !== null && $after === null) || $lostItems || $lostLocations,
				'public' => $held($before) !== [] || $held($after) !== [],
				'sensitive' => false,
				'external_effects' => [],
			] + $none;
		}
		if ($op === 'create') {
			$wanted = array_map('strval', (array) ($fields['locations'] ?? []));
			$map = self::locationsMap();
			$takes = array_filter($wanted, static fn(string $l): bool => (int) ($map[$l] ?? 0) > 0 && self::isMenu((int) $map[$l]));
			return ['destructive' => $takes !== [], 'public' => $wanted !== []] + $none;
		}
		if ($op === 'update') {
			$public = $held($before) !== [];
			$destructive = false;
			if (isset($fields['locations'])) {
				$wanted = array_map('strval', (array) $fields['locations']);
				$public = $public || $wanted !== [];
				$map = self::locationsMap();
				$destructive = array_diff($held($before), $wanted) !== []
					|| array_filter($wanted, static fn(string $l): bool => !in_array($l, $held($before), true) && (int) ($map[$l] ?? 0) > 0 && self::isMenu((int) $map[$l])) !== [];
			}
			return ['destructive' => $destructive, 'public' => $public] + $none;
		}
		if ($op === 'delete') {
			return ['destructive' => true, 'public' => $held($before) !== []] + $none;
		}
		if ($op === 'set_items') {
			$cited = array_values(array_filter(array_map(static fn($n): int => (int) ($n['db_id'] ?? 0), (array) ($intent['nodes'] ?? []))));
			$removes = ($intent['mode'] ?? 'replace') !== 'merge' && array_diff($itemIds($before), $cited) !== [];
			return ['destructive' => $removes, 'public' => $held($before) !== []] + $none;
		}
		return $none;
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		return user_can($userId, 'edit_theme_options');
	}

	public function label(?array $state): string
	{
		$name = trim((string) ($state['name'] ?? ''));
		return $name !== '' ? $name : __('(menu)', 'llamly');
	}

	public function tables(): array
	{
		global $wpdb;
		return [$wpdb->terms, $wpdb->term_taxonomy, $wpdb->termmeta, $wpdb->term_relationships, $wpdb->posts, $wpdb->postmeta, $wpdb->options];
	}

	public function findMarked(string $operationId): array
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT m.term_id FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id AND tt.taxonomy = 'nav_menu' WHERE m.meta_key = %s AND m.meta_value = %s ORDER BY m.term_id ASC LIMIT 2",
			self::MARKER,
			$operationId
		));
		return array_map('intval', is_array($ids) ? $ids : []);
	}

	public function clearMarker(int $id, string $operationId): void
	{
		global $wpdb;
		$wpdb->delete($wpdb->termmeta, ['term_id' => $id, 'meta_key' => self::MARKER, 'meta_value' => $operationId], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
		wp_cache_delete($id, 'term_meta');
	}

	public function flushCaches(int $id): void
	{
		global $wpdb;
		$items = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d AND tt.taxonomy = 'nav_menu' LIMIT 1000", $id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		foreach ($items as $itemId) {
			clean_post_cache($itemId);
			wp_cache_delete($itemId, 'post_meta');
		}
		clean_term_cache($id, 'nav_menu');
		wp_cache_delete($id, 'term_meta');
		self::flushOption('nav_menu_options');
		$stylesheet = (string) get_option('stylesheet');
		if ($stylesheet !== '') {
			self::flushOption('theme_mods_' . $stylesheet);
		}
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$state = $after ?? $before;
		$result = [
			'menu_id' => $id,
			'name' => (string) ($state['name'] ?? ''),
			'locations' => array_values((array) ($after['locations'] ?? [])),
			'item_count' => (int) ($after['items_total'] ?? 0),
		];
		$op = (string) ($intent['op'] ?? '');
		if (isset(self::$lastApply['displaced_locations']) && in_array($op, ['create', 'update'], true)) {
			$result['displaced_locations'] = (object) self::$lastApply['displaced_locations'];
			$result['previous_locations'] = (object) (self::$lastApply['previous_locations'] ?? []);
		}
		if ($op === 'set_items') {
			$result['item_ids'] = self::$lastApply['item_ids'] ?? [];
			$result['removed_item_ids'] = self::$lastApply['removed_item_ids'] ?? [];
		}
		if ($op === 'delete') {
			$result['deleted'] = true;
			$result['removed_items'] = count((array) ($before['items'] ?? []));
			$result['released_locations'] = array_values((array) ($before['locations'] ?? []));
		}
		if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
			$result['note'] = __('The active theme is a block theme and does not display classic menus; its navigation lives in wp_navigation posts.', 'llamly');
		}
		self::$lastApply = [];
		return $result;
	}
}
