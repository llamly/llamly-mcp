<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\History\Adapters\MenuObject;

/**
 * Classic navigation menus (wp_list_menus, wp_list_menu_items and the menu writes). Reads are plain queries; every
 * write goes through Toolkit::run() to the MenuObject adapter, which keeps menu, item and location IDs on undo/redo.
 * Block themes do not display classic menus: reads and writes say so in a note instead of pretending otherwise.
 */
final class MenuTools
{
	private const CATEGORY = 'menus';
	private const CAPABILITY = 'edit_theme_options';

	public static function register(): void
	{
		$menuId = ['type' => 'integer', 'minimum' => 1];
		$locations = ['type' => 'array', 'maxItems' => 50, 'uniqueItems' => true, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$'], 'description' => 'Theme location slugs (see wp_list_menus). Unknown slugs are rejected.'];

		Toolkit::read('wp_list_menus', 'List classic navigation menus with their locations, item count and revision_hash, plus the theme locations. On a block theme the note explains that classic menus are not displayed.', self::CATEGORY, self::CAPABILITY, Toolkit::pageProps(), [], [self::class, 'listMenus']);

		Toolkit::read('wp_list_menu_items', 'Return the item tree of a classic menu (drafts included) with the revision_hash to send as expected_hash to wp_set_menu_items.', self::CATEGORY, self::CAPABILITY, [
			'menu_id' => $menuId,
		], ['menu_id'], [self::class, 'listItems']);

		Toolkit::write('wp_create_menu', 'Create an empty classic menu, optionally assigned to theme locations (a location already used by another menu moves to the new one). Taking a location already used by another menu is destructive: with safe mode on, it needs confirm: true; ask the user before sending it. Assigning a location is public: with the optional approval mode on, it waits for a human in wp-admin. Reversible.', self::CATEGORY, self::CAPABILITY, [
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'locations' => $locations,
			'auto_add' => ['type' => 'boolean', 'description' => 'Automatically add new top-level pages to this menu.'],
		], ['name'], false, [self::class, 'create']);

		Toolkit::write('wp_update_menu', 'Rename a classic menu and/or replace its theme locations (the list replaces the current set; [] removes all). Removing locations, or taking a location from another menu, is destructive: with safe mode on, it needs confirm: true; ask the user before sending it. Changing a menu that has or gains a location is public: with the optional approval mode on, it waits for a human in wp-admin. Reversible.', self::CATEGORY, self::CAPABILITY, [
			'menu_id' => $menuId,
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'locations' => $locations,
			'auto_add' => ['type' => 'boolean'],
		], ['menu_id'], true, [self::class, 'update']);

		Toolkit::write('wp_delete_menu', 'Delete a classic menu with all of its items and remove it from its locations. Reversible with the same menu and item IDs; undo gives the locations back only while no other menu took them. With safe mode on, needs confirm: true; ask the user before sending it.', self::CATEGORY, self::CAPABILITY, [
			'menu_id' => $menuId,
		], ['menu_id'], true, [self::class, 'delete']);

		Toolkit::write('wp_set_menu_items', 'Write the item tree of a classic menu. Nodes with db_id update that item in place (omitted fields keep their value, unknown metadata is preserved); nodes without db_id are created. mode=replace (default) deletes items not listed; mode=merge keeps them after the listed ones. The whole tree is validated before anything changes (types, targets, URL protocols http/https/mailto/tel, at most ' . MenuObject::MAX_ITEMS . ' items and ' . MenuObject::MAX_DEPTH . ' levels). url is only used by custom links. Reversible with the same item IDs. mode=replace removes items: with safe mode on, it needs confirm: true; ask the user before sending it.', self::CATEGORY, self::CAPABILITY, [
			'menu_id' => $menuId,
			'mode' => ['type' => 'string', 'enum' => ['replace', 'merge'], 'default' => 'replace'],
			'items' => ['type' => 'array', 'maxItems' => MenuObject::MAX_ITEMS, 'items' => self::nodeSchema(1)],
		], ['menu_id', 'items'], true, [self::class, 'setItems']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new MenuObject()];
	}

	/** Closed node schema nested up to MAX_DEPTH levels (the validator has no $ref). */
	private static function nodeSchema(int $depth): array
	{
		$string = static fn(int $max): array => ['type' => 'string', 'maxLength' => $max];
		$properties = [
			'db_id' => ['type' => 'integer', 'minimum' => 1],
			'title' => $string(500),
			'url' => $string(2048),
			'type' => ['type' => 'string', 'enum' => MenuObject::ITEM_TYPES],
			'object' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]*$'],
			'object_id' => ['type' => 'integer', 'minimum' => 0],
			'target' => ['type' => 'string', 'enum' => ['', '_blank']],
			'classes' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]*$']],
			'description' => $string(2000),
			'attr_title' => $string(500),
			'xfn' => ['type' => 'string', 'maxLength' => 200, 'pattern' => '^[A-Za-z0-9_ -]*$'],
		];
		$properties['children'] = $depth < MenuObject::MAX_DEPTH
			? ['type' => 'array', 'maxItems' => MenuObject::MAX_ITEMS, 'items' => self::nodeSchema($depth + 1)]
			: ['type' => 'array', 'maxItems' => 0];
		return Toolkit::schema($properties);
	}

	private static function blockThemeNote(): ?string
	{
		return function_exists('wp_is_block_theme') && wp_is_block_theme()
			? __('The active theme is a block theme and does not display classic menus; its navigation lives in wp_navigation posts (use wp_list_navigations). Classic menus can still be edited here.', 'llamly')
			: null;
	}

	public static function listMenus(array $args, array $auth): array|\WP_Error
	{
		global $wpdb;
		if (!user_can((int) $auth['user_id'], self::CAPABILITY)) {
			return Toolkit::error('forbidden', __('The connected user cannot manage menus.', 'llamly'), 403);
		}
		[$page, $perPage] = Toolkit::page($args);
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'nav_menu'");
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.name ASC, t.term_id ASC LIMIT %d OFFSET %d",
			$perPage,
			($page - 1) * $perPage
		), ARRAY_A);
		$adapter = new MenuObject();
		$items = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$state = $adapter->capture((int) $row['term_id']);
			if ($state === null) {
				continue;
			}
			$items[] = [
				'menu_id' => (int) $row['term_id'],
				'name' => (string) $row['name'],
				'slug' => (string) $row['slug'],
				'item_count' => (int) $state['items_total'],
				'locations' => $state['locations'],
				'auto_add' => $state['auto_add'],
				'revision_hash' => $adapter->fingerprint($state),
			];
		}
		$map = MenuObject::locationsMap();
		$locations = [];
		foreach (get_registered_nav_menus() as $location => $description) {
			$holder = (int) ($map[$location] ?? 0);
			$locations[] = ['location' => (string) $location, 'description' => (string) $description, 'menu_id' => $holder > 0 && MenuObject::isMenu($holder) ? $holder : 0];
		}
		$result = Toolkit::listResult($items, $total, $page, $perPage) + [
			'locations' => $locations,
			'is_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
		];
		$note = self::blockThemeNote();
		if ($note !== null) {
			$result['note'] = $note;
		}
		return $result;
	}

	public static function listItems(array $args, array $auth): array|\WP_Error
	{
		if (!user_can((int) $auth['user_id'], self::CAPABILITY)) {
			return Toolkit::error('forbidden', __('The connected user cannot manage menus.', 'llamly'), 403);
		}
		$menuId = (int) $args['menu_id'];
		$adapter = new MenuObject();
		$state = $adapter->capture($menuId);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('The menu was not found.', 'llamly'), 404);
		}
		$nodes = [];
		$parents = [];
		foreach ($state['items'] as $item) {
			$id = (int) $item['row']['ID'];
			$post = get_post($id);
			$setup = $post instanceof \WP_Post ? wp_setup_nav_menu_item($post) : null;
			$classes = maybe_unserialize(MenuObject::metaValue($item, '_menu_item_classes'));
			$nodes[$id] = [
				'db_id' => $id,
				'menu_order' => (int) $item['row']['menu_order'],
				'status' => (string) $item['row']['post_status'],
				'title' => $setup ? (string) $setup->title : (string) $item['row']['post_title'],
				'url' => $setup ? (string) $setup->url : MenuObject::metaValue($item, '_menu_item_url'),
				'type' => MenuObject::metaValue($item, '_menu_item_type'),
				'object' => MenuObject::metaValue($item, '_menu_item_object'),
				'object_id' => (int) MenuObject::metaValue($item, '_menu_item_object_id'),
				'target' => MenuObject::metaValue($item, '_menu_item_target'),
				'classes' => is_array($classes) ? array_values(array_filter(array_map('strval', $classes), static fn(string $c): bool => $c !== '')) : [],
				'description' => (string) $item['row']['post_content'],
				'attr_title' => (string) $item['row']['post_excerpt'],
				'xfn' => MenuObject::metaValue($item, '_menu_item_xfn'),
				'invalid' => $setup ? !empty($setup->_invalid) : false,
				'children' => [],
			];
			$parents[$id] = (int) MenuObject::metaValue($item, '_menu_item_menu_item_parent');
		}
		// Build the tree by reference; an item whose parent is missing (or a cycle) is shown at the top level.
		$roots = [];
		foreach (array_keys($nodes) as $id) {
			$parent = $parents[$id];
			if ($parent > 0 && $parent !== $id && isset($nodes[$parent]) && !self::descends($parent, $id, $parents)) {
				$nodes[$parent]['children'][] = &$nodes[$id];
			} else {
				$roots[] = &$nodes[$id];
			}
		}
		$tree = json_decode((string) wp_json_encode($roots), true);
		$result = [
			'menu_id' => $menuId,
			'name' => (string) $state['name'],
			'locations' => $state['locations'],
			'item_count' => (int) $state['items_total'],
			'items' => is_array($tree) ? $tree : [],
			'revision_hash' => $adapter->fingerprint($state),
		];
		$note = self::blockThemeNote();
		if ($note !== null) {
			$result['note'] = $note;
		}
		return $result;
	}

	/** True when $node is (transitively) a child of $ancestor in the parent map. @param array<int,int> $parents */
	private static function descends(int $node, int $ancestor, array $parents): bool
	{
		for ($steps = 0; $node > 0 && $steps < 1000; $steps++) {
			if ($node === $ancestor) {
				return true;
			}
			$node = $parents[$node] ?? 0;
		}
		return false;
	}

	public static function create(array $args, array $auth): mixed
	{
		$denied = self::deny($auth);
		if ($denied) {
			return $denied;
		}
		$name = trim((string) $args['name']);
		if ($name === '') {
			return Toolkit::error('invalid_arguments', __('A menu name is required.', 'llamly'), 400);
		}
		$fields = ['name' => $name];
		if (isset($args['locations'])) {
			$checked = self::checkLocations((array) $args['locations']);
			if (is_wp_error($checked)) {
				return $checked;
			}
			$fields['locations'] = $checked;
		}
		if (isset($args['auto_add'])) {
			$fields['auto_add'] = (bool) $args['auto_add'];
		}
		return Toolkit::run('menu', 'wp_create_menu', 0, $args, $auth, ['op' => 'create', 'fields' => $fields], 'menu_created');
	}

	public static function update(array $args, array $auth): mixed
	{
		$menuId = self::existing($args, $auth);
		if (is_wp_error($menuId)) {
			return $menuId;
		}
		$fields = [];
		if (isset($args['name'])) {
			$fields['name'] = trim((string) $args['name']);
			if ($fields['name'] === '') {
				return Toolkit::error('invalid_arguments', __('A menu name cannot be empty.', 'llamly'), 400);
			}
		}
		if (isset($args['locations'])) {
			$checked = self::checkLocations((array) $args['locations']);
			if (is_wp_error($checked)) {
				return $checked;
			}
			$fields['locations'] = $checked;
		}
		if (isset($args['auto_add'])) {
			$fields['auto_add'] = (bool) $args['auto_add'];
		}
		if (!$fields) {
			return Toolkit::error('invalid_arguments', __('Send at least one of name, locations or auto_add.', 'llamly'), 400);
		}
		return Toolkit::run('menu', 'wp_update_menu', $menuId, $args, $auth, ['op' => 'update', 'fields' => $fields], 'menu_updated');
	}

	public static function delete(array $args, array $auth): mixed
	{
		$menuId = self::existing($args, $auth);
		if (is_wp_error($menuId)) {
			return $menuId;
		}
		// A menu that is already gone still goes through the engine so a retried call replays its stored result.
		if (MenuObject::isMenu($menuId)) {
			$blocker = MenuObject::deleteBlocker($menuId);
			if ($blocker !== null) {
				return Toolkit::error($blocker['code'], $blocker['message'], $blocker['status']);
			}
		}
		return Toolkit::run('menu', 'wp_delete_menu', $menuId, $args, $auth, ['op' => 'delete'], 'menu_deleted');
	}

	public static function setItems(array $args, array $auth): mixed
	{
		$menuId = self::existing($args, $auth);
		if (is_wp_error($menuId)) {
			return $menuId;
		}
		$flat = [];
		$error = self::flatten((array) $args['items'], -1, 1, $flat);
		if ($error !== null) {
			return $error;
		}
		// Validate the whole tree now (the adapter validates again under the lock, against the locked state).
		$state = (new MenuObject())->capture($menuId);
		if ($state !== null) {
			$current = [];
			foreach ($state['items'] as $item) {
				$current[(int) $item['row']['ID']] = $item;
			}
			$checked = MenuObject::normalizeNodes($flat, $current, (int) $auth['user_id']);
			if (is_wp_error($checked)) {
				return $checked;
			}
		}
		$mode = (string) ($args['mode'] ?? 'replace');
		return Toolkit::run('menu', 'wp_set_menu_items', $menuId, $args, $auth, ['op' => 'set_items', 'mode' => $mode === 'merge' ? 'merge' : 'replace', 'nodes' => $flat], 'menu_items_set');
	}

	/**
	 * Depth-first flattening; each node keeps only the fields the caller sent plus parent index and depth.
	 *
	 * @param list<array<string, mixed>> $nodes
	 * @param list<array<string, mixed>> $flat
	 */
	private static function flatten(array $nodes, int $parent, int $depth, array &$flat): ?\WP_Error
	{
		foreach ($nodes as $node) {
			if ($depth > MenuObject::MAX_DEPTH) {
				return Toolkit::error('too_deep', sprintf(/* translators: %d: maximum nesting depth. */ __('Menu items can be nested at most %d levels deep.', 'llamly'), MenuObject::MAX_DEPTH), 400);
			}
			if (count($flat) >= MenuObject::MAX_ITEMS) {
				return Toolkit::error('too_many_items', sprintf(/* translators: %d: maximum number of menu items. */ __('A menu can have at most %d items.', 'llamly'), MenuObject::MAX_ITEMS), 400);
			}
			$children = is_array($node['children'] ?? null) ? $node['children'] : [];
			unset($node['children']);
			$index = count($flat);
			$flat[] = $node + ['parent' => $parent, 'depth' => $depth];
			$error = self::flatten($children, $index, $depth + 1, $flat);
			if ($error !== null) {
				return $error;
			}
		}
		return null;
	}

	/** @param list<string> $locations @return list<string>|\WP_Error */
	private static function checkLocations(array $locations): array|\WP_Error
	{
		$registered = array_keys(get_registered_nav_menus());
		$out = [];
		foreach ($locations as $location) {
			$location = (string) $location;
			if (!in_array($location, $registered, true)) {
				return Toolkit::error('unknown_location', sprintf(/* translators: %s: menu location slug. */ __('The theme does not register the menu location "%s". See wp_list_menus.', 'llamly'), $location), 400);
			}
			$out[] = $location;
		}
		$out = array_values(array_unique($out));
		sort($out, SORT_STRING);
		return $out;
	}

	private static function deny(array $auth): ?\WP_Error
	{
		return user_can((int) $auth['user_id'], self::CAPABILITY)
			? null
			: Toolkit::error('forbidden', __('The connected user cannot manage menus.', 'llamly'), 403);
	}

	private static function existing(array $args, array $auth): int|\WP_Error
	{
		$denied = self::deny($auth);
		if ($denied) {
			return $denied;
		}
		$menuId = (int) $args['menu_id'];
		if (MenuObject::isOtherTerm($menuId) && !MenuObject::isMenu($menuId)) {
			return Toolkit::error('object_not_found', __('The menu was not found.', 'llamly'), 404);
		}
		return $menuId;
	}
}
