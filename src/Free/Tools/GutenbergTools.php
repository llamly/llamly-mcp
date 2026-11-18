<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\Core\Schema;
use Llamly\Free\History\Adapters\GlobalStylesObject;
use Llamly\Free\History\Adapters\PatternObject;
use Llamly\Free\History\Adapters\TemplateObject;
use Llamly\Free\History\BlockTree;
use Llamly\Free\History\ContentGuard;
use Llamly\Free\History\Engine;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\ObjectMutex;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\State;

/**
 * Gutenberg: block trees, block types, patterns, templates, global styles and navigation.
 *
 * - wp_edit_blocks is one all-or-nothing batch: BlockTree validates and applies every operation in memory against the
 *   tree the caller read (expected_hash = full revision_hash), then a single write goes through the engine with the
 *   post adapter (posts, pages, CPTs, wp_navigation), the pattern adapter (wp_block) or the template adapter.
 * - Registered patterns, theme files and the merged theme.json are read-only. Template overrides, user patterns and
 *   the user global styles are reversible objects. A classic theme gets a typed not_block_theme error from the site
 *   editor tools; nothing pretends to write.
 * - Writes whose intent depends on the current state (block batches, template materialization, style merges) replay
 *   a committed call with the same idempotency key before recomputing anything, so a retry returns the stored result.
 */
final class GutenbergTools
{
	public const CONTENT_MAX = 1048576;
	private const TEMPLATE_ID = '^[^/]{1,200}//[^/]{1,200}$';
	private const CATEGORY = 'gutenberg';

	public static function register(): void
	{
		$id = ['type' => 'integer', 'minimum' => 1];
		$templateId = ['type' => 'string', 'maxLength' => 401, 'pattern' => self::TEMPLATE_ID, 'description' => 'Template id "theme//slug" as returned by wp_list_templates.'];
		$templateType = ['type' => 'string', 'enum' => ['wp_template', 'wp_template_part']];
		$path = ['type' => 'string', 'maxLength' => 80, 'pattern' => BlockTree::PATH_PATTERN];
		$block = [
			'type' => 'object',
			'additionalProperties' => false,
			'description' => 'Either {markup:"<!-- wp:... -->...<!-- /wp:... -->"} with exactly one root block, or {name, attrs?, content? | inner_blocks?}. The structured form emits no wrapper HTML; use markup for group/columns.',
			'properties' => [
				'markup' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::CONTENT_MAX],
				'name' => ['type' => 'string', 'maxLength' => 100, 'pattern' => BlockTree::NAME_PATTERN],
				'attrs' => ['type' => 'object', 'maxProperties' => 100],
				'content' => ['type' => 'string', 'maxLength' => self::CONTENT_MAX],
				'inner_blocks' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object']],
			],
		];
		$categories = ['type' => 'array', 'maxItems' => 20, 'uniqueItems' => true, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'description' => 'Existing pattern category slugs (replaces the current set).'];

		Toolkit::read('wp_list_blocks', 'Read the block tree of a post (post_id) or a block template (template "theme//slug"). Each block has a dot path (base 0) used by wp_edit_blocks; revision_hash is the expected_hash for the next edit.', self::CATEGORY, 'edit_posts', [
			'post_id' => $id,
			'template' => $templateId,
			'template_type' => $templateType,
			'full' => ['type' => 'boolean', 'default' => false, 'description' => 'Return complete attrs and HTML instead of previews.'],
		], [], [self::class, 'listBlocks']);

		Toolkit::read('wp_list_block_types', 'List registered block types (name, title, category). With name, return its attributes, supports, parent/ancestor and context.', self::CATEGORY, 'edit_posts', [
			'name' => ['type' => 'string', 'maxLength' => 100, 'pattern' => BlockTree::NAME_PATTERN],
			'category' => ['type' => 'string', 'maxLength' => 100],
			'search' => ['type' => 'string', 'maxLength' => 200],
		] + Toolkit::pageProps(), [], [self::class, 'listBlockTypes']);

		Toolkit::read('wp_list_patterns', 'List block patterns: registered (core, theme, plugins; read-only) and user patterns (wp_block, editable, with revision_hash).', self::CATEGORY, 'edit_posts', [
			'source' => ['type' => 'string', 'enum' => ['all', 'registered', 'user'], 'default' => 'all'],
			'category' => ['type' => 'string', 'maxLength' => 200],
			'search' => ['type' => 'string', 'maxLength' => 200],
		] + Toolkit::pageProps(), [], [self::class, 'listPatterns']);

		Toolkit::read('wp_get_pattern', 'Read one pattern with its content and block tree: a registered pattern by name or a user pattern by id (with revision_hash and usage_count).', self::CATEGORY, 'edit_posts', [
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'id' => $id,
		], [], [self::class, 'getPattern']);

		Toolkit::read('wp_list_templates', 'List block templates and template parts of the active block theme, with source, override state and revision_hash. Classic themes return not_block_theme.', self::CATEGORY, 'edit_theme_options', [
			'type' => ['type' => 'string', 'enum' => ['wp_template', 'wp_template_part', 'both'], 'default' => 'both'],
			'area' => ['type' => 'string', 'maxLength' => 100],
		] + Toolkit::pageProps(50), [], [self::class, 'listTemplates']);

		Toolkit::read('wp_get_template', 'Read one block template or template part ("theme//slug") with content, block tree and revision_hash.', self::CATEGORY, 'edit_theme_options', [
			'id' => $templateId,
			'type' => $templateType,
		], ['id'], [self::class, 'getTemplate']);

		Toolkit::read('wp_get_global_styles', 'Read global styles of the active block theme: scope user (editable, with revision_hash) or merged (theme + user, read-only).', self::CATEGORY, 'edit_theme_options', [
			'scope' => ['type' => 'string', 'enum' => ['user', 'merged'], 'default' => 'user'],
		], [], [self::class, 'getGlobalStyles']);

		Toolkit::read('wp_list_navigations', 'List wp_navigation menus of the block editor with their link tree and revision_hash. Edit them with wp_edit_blocks(post_id).', self::CATEGORY, 'edit_theme_options', [
			'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'any'], 'default' => 'any'],
		] + Toolkit::pageProps(), [], [self::class, 'listNavigations']);

		Toolkit::write('wp_edit_blocks', 'Apply 1-50 block operations (update, insert, replace, delete, move) to one post or template as a single all-or-nothing save. Paths refer to the tree returned by wp_list_blocks (snapshot addressing) and expected_hash must be its revision_hash. Editing a theme template creates its override (undo returns to the theme file). Public content and templates wait for a human in wp-admin only when the optional approval mode is on in Settings.', self::CATEGORY, 'edit_posts', [
			'post_id' => $id,
			'template' => $templateId,
			'template_type' => $templateType,
			'operations' => [
				'type' => 'array',
				'minItems' => 1,
				'maxItems' => BlockTree::MAX_OPERATIONS,
				'items' => [
					'type' => 'object',
					'additionalProperties' => false,
					'required' => ['op'],
					'properties' => [
						'op' => ['type' => 'string', 'enum' => ['update', 'insert', 'replace', 'delete', 'move']],
						'path' => $path,
						'from_path' => $path,
						'to_path' => $path,
						'position' => ['type' => 'string', 'enum' => ['before', 'after', 'first_child', 'last_child']],
						'attrs' => ['type' => 'object', 'maxProperties' => 100, 'description' => 'update: shallow merge; null removes a key.'],
						'content' => ['type' => 'string', 'maxLength' => self::CONTENT_MAX, 'description' => 'update: new inner HTML of a block without children.'],
						'block' => $block,
					],
				],
			],
		], ['operations'], true, [self::class, 'editBlocks']);

		Toolkit::write('wp_create_pattern', 'Create a user pattern (wp_block). Synced by default. Reversible: undo removes it.', self::CATEGORY, 'publish_posts', [
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::CONTENT_MAX],
			'synced' => ['type' => 'boolean', 'default' => true],
			'categories' => $categories,
		], ['title', 'content'], false, [self::class, 'createPattern']);

		Toolkit::write('wp_update_pattern', 'Update a user pattern (id): title, content, synced flag or categories. Registered patterns are read-only. Changing a synced pattern used on the site waits for a human in wp-admin only when the optional approval mode is on in Settings.', self::CATEGORY, 'edit_posts', [
			'id' => $id,
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Registered pattern name: always refused (read-only).'],
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::CONTENT_MAX],
			'synced' => ['type' => 'boolean'],
			'categories' => $categories,
		], [], true, [self::class, 'updatePattern']);

		Toolkit::write('wp_delete_pattern', 'Move a user pattern to the trash (reversible). Permanent deletion is not available; registered patterns are read-only. With safe mode on, needs confirm: true; ask the user before sending it. Pages that embed a synced pattern show an empty block until it is restored.', self::CATEGORY, 'delete_posts', [
			'id' => $id,
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Registered pattern name: always refused (read-only).'],
		], [], true, [self::class, 'deletePattern']);

		Toolkit::write('wp_save_template', 'Save a template or template part of the active block theme. A theme file template gets a database override (undo returns to the theme file); an unknown id with content creates a custom template. expected_hash is the revision_hash from wp_get_template (or the zero hash tp1_000... for a new template). A template is public: with the optional approval mode on, it waits for a human in wp-admin.', self::CATEGORY, 'edit_theme_options', [
			'id' => $templateId,
			'type' => $templateType + ['default' => 'wp_template'],
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'description' => ['type' => 'string', 'maxLength' => 2000],
			'content' => ['type' => 'string', 'maxLength' => self::CONTENT_MAX],
			'area' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Template part area (header, footer, uncategorized...).'],
		], ['id'], true, [self::class, 'saveTemplate']);

		Toolkit::write('wp_reset_template', 'Delete the database override of a template or template part so the theme file applies again. Reversible with the same ID (undo restores the override and up to 50 revisions). With safe mode on, needs confirm: true; ask the user before sending it. With the optional approval mode on, it waits for a human in wp-admin and confirm does not skip that.', self::CATEGORY, 'edit_theme_options', [
			'id' => $templateId,
			'type' => $templateType + ['default' => 'wp_template'],
		], ['id'], true, [self::class, 'resetTemplate']);

		Toolkit::write('wp_update_global_styles', 'Change the user global styles of the active block theme. mode merge (default) deep-merges objects (null removes a key); replace swaps the given top-level settings/styles. Unsafe values are refused, never dropped. Custom CSS (a css key at any level of styles) is refused: it is edited in the WordPress Site Editor, and any custom CSS already saved there is kept as it is. Global styles are public: with the optional approval mode on, it waits for a human in wp-admin.', self::CATEGORY, 'edit_theme_options', [
			'settings' => ['type' => 'object', 'maxProperties' => 50],
			'styles' => ['type' => 'object', 'maxProperties' => 50],
			'mode' => ['type' => 'string', 'enum' => ['merge', 'replace'], 'default' => 'merge'],
		], [], true, [self::class, 'updateGlobalStyles']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new PatternObject(), new TemplateObject(), new GlobalStylesObject()];
	}

	// ------------------------------------------------------------------ reads

	public static function listBlocks(array $args, array $auth): array|\WP_Error
	{
		$target = self::target($args, (int) $auth['user_id'], false);
		if (is_wp_error($target)) {
			return $target;
		}
		$content = $target['content'];
		return [
			'target' => ['post_id' => $target['post_id'], 'template' => $target['template'], 'title' => $target['title'], 'kind' => $target['kind']],
			'is_classic_content' => BlockTree::isClassic($content),
			'roundtrip_normalized' => BlockTree::roundTripNormalizes($content),
			'revision_hash' => $target['hash'],
			'blocks' => BlockTree::summarize(BlockTree::parse($content), !empty($args['full'])),
		];
	}

	public static function listBlockTypes(array $args, array $auth): array|\WP_Error
	{
		$registry = \WP_Block_Type_Registry::get_instance();
		if (isset($args['name'])) {
			$type = $registry->get_registered((string) $args['name']);
			if (!$type instanceof \WP_Block_Type) {
				return Toolkit::error('object_not_found', __('This block type is not registered.', 'llamly'), 404);
			}
			return [
				'name' => $type->name,
				'title' => (string) $type->title,
				'category' => $type->category,
				'description' => (string) $type->description,
				'attributes' => $type->attributes ?: (object) [],
				'supports' => $type->supports ?: (object) [],
				'parent' => $type->parent,
				'ancestor' => $type->ancestor ?? null,
				'provides_context' => $type->provides_context ?: (object) [],
				'uses_context' => $type->uses_context,
				'is_dynamic' => $type->is_dynamic(),
			];
		}
		$category = (string) ($args['category'] ?? '');
		$search = strtolower(trim((string) ($args['search'] ?? '')));
		$items = [];
		foreach ($registry->get_all_registered() as $type) {
			if ($category !== '' && $type->category !== $category) {
				continue;
			}
			if ($search !== '' && strpos(strtolower($type->name . ' ' . (string) $type->title), $search) === false) {
				continue;
			}
			$items[] = ['name' => $type->name, 'title' => (string) $type->title, 'category' => $type->category];
		}
		usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
		[$page, $perPage] = Toolkit::page($args);
		return Toolkit::listResult(array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $page, $perPage);
	}

	public static function listPatterns(array $args, array $auth): array|\WP_Error
	{
		$userId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$source = (string) ($args['source'] ?? 'all');
		$category = (string) ($args['category'] ?? '');
		$search = trim((string) ($args['search'] ?? ''));
		$registered = [];
		if ($source !== 'user') {
			foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
				$cats = array_values((array) ($pattern['categories'] ?? []));
				if ($category !== '' && !in_array($category, $cats, true)) {
					continue;
				}
				if ($search !== '' && stripos((string) ($pattern['name'] ?? '') . ' ' . (string) ($pattern['title'] ?? ''), $search) === false) {
					continue;
				}
				$registered[] = [
					'name' => (string) $pattern['name'],
					'title' => (string) ($pattern['title'] ?? ''),
					'source' => 'registered',
					'categories' => $cats,
					'description' => (string) ($pattern['description'] ?? ''),
					'viewport_width' => isset($pattern['viewportWidth']) ? (int) $pattern['viewportWidth'] : null,
				];
			}
			usort($registered, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
		}
		$offset = ($page - 1) * $perPage;
		$items = array_slice($registered, $offset, $perPage);
		$total = count($registered);
		if ($source !== 'registered') {
			$query = [
				'post_type' => 'wp_block',
				'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
				'fields' => 'ids',
				'orderby' => 'ID',
				'order' => 'ASC',
				'perm' => 'readable',
				'no_found_rows' => false,
			];
			if ($search !== '') {
				$query['s'] = sanitize_text_field($search);
			}
			if ($category !== '' && taxonomy_exists(PatternObject::CATEGORY)) {
				$query['tax_query'] = [['taxonomy' => PatternObject::CATEGORY, 'field' => 'slug', 'terms' => [$category]]]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
			}
			$need = $perPage - count($items);
			$userOffset = max(0, $offset - count($registered));
			$wpQuery = new \WP_Query($query + ['posts_per_page' => max(1, $need), 'offset' => $userOffset]);
			$total += (int) $wpQuery->found_posts;
			if ($need > 0) {
				foreach ($wpQuery->posts as $postId) {
					$state = PostAdapter::capture((int) $postId, false, PatternObject::TYPES);
					if ($state === null || !user_can($userId, 'read_post', (int) $postId)) {
						continue;
					}
					$items[] = self::userPatternItem($state);
				}
			}
		}
		return Toolkit::listResult($items, $total, $page, $perPage);
	}

	public static function getPattern(array $args, array $auth): array|\WP_Error
	{
		$hasName = isset($args['name']);
		if ($hasName === isset($args['id'])) {
			return Toolkit::error('invalid_arguments', __('Send exactly one of name (registered pattern) or id (user pattern).', 'llamly'), 400);
		}
		if ($hasName) {
			$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered((string) $args['name']);
			if (!is_array($pattern)) {
				return Toolkit::error('object_not_found', __('No registered pattern has this name.', 'llamly'), 404);
			}
			$content = (string) ($pattern['content'] ?? '');
			return [
				'name' => (string) $pattern['name'],
				'title' => (string) ($pattern['title'] ?? ''),
				'source' => 'registered',
				'read_only' => true,
				'categories' => array_values((array) ($pattern['categories'] ?? [])),
				'description' => (string) ($pattern['description'] ?? ''),
				'content' => $content,
				'blocks' => BlockTree::summarize(BlockTree::parse($content)),
			];
		}
		$id = (int) $args['id'];
		$state = PostAdapter::capture($id, false, PatternObject::TYPES);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('No user pattern has this id.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], 'read_post', $id)) {
			return Toolkit::error('forbidden', __('The connected user cannot read this pattern.', 'llamly'), 403);
		}
		$content = (string) $state['fields']['post_content'];
		return self::userPatternItem($state) + [
			'content' => $content,
			'blocks' => BlockTree::summarize(BlockTree::parse($content)),
			'usage_count' => PatternObject::usageCount($id),
		];
	}

	public static function listTemplates(array $args, array $auth): array|\WP_Error
	{
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		$type = (string) ($args['type'] ?? 'both');
		$types = $type === 'both' ? TemplateObject::TYPES : [$type];
		$items = [];
		foreach ($types as $templateType) {
			$query = [];
			if ($templateType === 'wp_template_part' && ($args['area'] ?? '') !== '') {
				$query['area'] = (string) $args['area'];
			}
			foreach (get_block_templates($query, $templateType) as $template) {
				$items[] = self::templateItem($template);
			}
		}
		[$page, $perPage] = Toolkit::page($args, 50);
		return Toolkit::listResult(array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $page, $perPage);
	}

	public static function getTemplate(array $args, array $auth): array|\WP_Error
	{
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		$resolved = self::resolveTemplate((string) $args['id'], (string) ($args['type'] ?? ''));
		if (is_wp_error($resolved)) {
			return $resolved;
		}
		if ($resolved['template'] === null) {
			return Toolkit::error('object_not_found', __('Template not found. List the available ids with wp_list_templates.', 'llamly'), 404);
		}
		$content = $resolved['content'];
		return self::templateItem($resolved['template']) + [
			'has_theme_file' => (bool) $resolved['template']->has_theme_file,
			'content' => $content,
			'blocks' => BlockTree::summarize(BlockTree::parse($content)),
			'revision_hash' => $resolved['hash'],
		];
	}

	public static function getGlobalStyles(array $args, array $auth): array|\WP_Error
	{
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		if (($args['scope'] ?? 'user') === 'merged') {
			return [
				'scope' => 'merged',
				'post_id' => GlobalStylesObject::postIdFor(get_stylesheet()),
				'settings' => wp_get_global_settings() ?: (object) [],
				'styles' => wp_get_global_styles() ?: (object) [],
			];
		}
		$current = self::globalStylesState();
		$data = GlobalStylesObject::decode($current['state']) ?? [];
		return [
			'scope' => 'user',
			'theme' => get_stylesheet(),
			'post_id' => $current['id'],
			'settings' => is_array($data['settings'] ?? null) && $data['settings'] ? $data['settings'] : (object) [],
			'styles' => is_array($data['styles'] ?? null) && $data['styles'] ? $data['styles'] : (object) [],
			'revision_hash' => $current['hash'],
		];
	}

	public static function listNavigations(array $args, array $auth): array|\WP_Error
	{
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		$userId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$status = (string) ($args['status'] ?? 'any');
		$wpQuery = new \WP_Query([
			'post_type' => 'wp_navigation',
			'post_status' => $status === 'any' ? ['publish', 'draft'] : [$status],
			'posts_per_page' => $perPage,
			'paged' => $page,
			'orderby' => 'ID',
			'order' => 'ASC',
			'fields' => 'ids',
			'perm' => 'readable',
		]);
		$items = [];
		foreach ($wpQuery->posts as $postId) {
			$state = PostAdapter::capture((int) $postId);
			if ($state === null || !user_can($userId, 'read_post', (int) $postId)) {
				continue;
			}
			$budget = 200;
			$items[] = [
				'id' => $state['post_id'],
				'title' => $state['fields']['post_title'],
				'slug' => $state['fields']['post_name'],
				'status' => $state['fields']['post_status'],
				'revision_hash' => PostAdapter::fingerprintOf($state),
				'links' => self::navigationLinks(BlockTree::parse((string) $state['fields']['post_content']), $budget),
			];
		}
		return Toolkit::listResult($items, (int) $wpQuery->found_posts, $page, $perPage);
	}

	// ------------------------------------------------------------------ writes

	public static function editBlocks(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_edit_blocks');
		if ($replay !== null) {
			return $replay;
		}
		$userId = (int) $auth['user_id'];
		$target = self::target($args, $userId, true);
		if (is_wp_error($target)) {
			return $target;
		}
		$dryRun = !empty($args['dry_run']);
		$stale = !hash_equals($target['hash'], (string) ($args['expected_hash'] ?? ''));
		if ($stale && !$dryRun) {
			return Toolkit::error('stale_revision', __('The blocks changed after the caller read them. Read them again with wp_list_blocks before editing.', 'llamly'), 409);
		}
		if ($target['adapter'] === 'post' && ContentGuard::isElementorDocument($target['post_id'])) {
			return Toolkit::error('elementor_read_only', __('This is an Elementor document. Its content is read-only in Llamly.', 'llamly'), 403);
		}
		if ($target['post_id'] > 0 && $target['adapter'] !== 'template') {
			if (!function_exists('wp_check_post_lock')) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			$lockedBy = wp_check_post_lock($target['post_id']);
			if ($lockedBy && (int) $lockedBy !== $userId) {
				return Toolkit::error('editor_locked', __('Someone is editing this post in the WordPress editor. Try again after they close it.', 'llamly'), 409);
			}
		}
		$content = $target['content'];
		if (BlockTree::isClassic($content)) {
			return Toolkit::error('classic_content', __('This content is classic HTML outside blocks. Convert it to blocks in the editor before editing blocks.', 'llamly'), 409);
		}
		try {
			$applied = BlockTree::apply(BlockTree::parse($content), (array) $args['operations']);
		} catch (MutationFailure $failure) {
			return Toolkit::error($failure->errorCode, $failure->reason(), $failure->httpStatus);
		}
		$new = BlockTree::serialize($applied['blocks']);
		$warnings = $applied['warnings'];
		$normalized = BlockTree::roundTripNormalizes($content);
		if ($normalized) {
			$warnings[] = 'Saving re-serializes the whole document: whitespace between blocks and attribute JSON escaping are normalized.';
		}
		if ($new === $content) {
			return Toolkit::error('no_change', __('The operations do not change the stored content.', 'llamly'), 409);
		}
		$base = ['args_hash' => self::argsHash($args)] + ($warnings ? ['warnings' => $warnings] : []);
		$extra = [
			'blocks_plan' => $applied['plan'],
			'roundtrip_normalized' => $normalized,
			'would_materialize_override' => $target['adapter'] === 'template' && $target['post_id'] === 0,
			'diff' => [
				'before' => array_map(static fn(array $b): string => (string) ($b['blockName'] ?? 'core/freeform'), BlockTree::parse($content)),
				'after' => array_map(static fn(array $b): string => (string) ($b['blockName'] ?? 'core/freeform'), $applied['blocks']),
				'bytes_before' => strlen($content),
				'bytes_after' => strlen($new),
			],
			'warnings' => $warnings,
		];
		if ($target['adapter'] === 'template' && $target['post_id'] === 0) {
			$intent = ['op' => 'create', 'materialized' => true] + self::materializeIntent($target['resolved'], ['post_content' => $new]) + $base;
			$result = self::withTemplateLock($target['resolved'], static fn() => Toolkit::run('template', 'wp_edit_blocks', 0, $args, $auth, $intent, 'template_created'));
		} else {
			$intent = ['op' => 'update', 'fields' => ['post_content' => $new]] + $base;
			$result = Toolkit::run($target['adapter'], 'wp_edit_blocks', $target['post_id'], $args, $auth, $intent, $target['adapter'] . '_blocks_edited');
		}
		if ($dryRun && is_array($result)) {
			$result = $extra + $result;
			if ($stale) {
				$result['would_fail'] = 'stale_revision';
			}
		}
		return $result;
	}

	public static function createPattern(array $args, array $auth): mixed
	{
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'publish_posts') || !user_can($userId, 'edit_posts')) {
			return Toolkit::error('forbidden', __('The connected user cannot create patterns.', 'llamly'), 403);
		}
		$change = self::patternChange($args, $userId);
		if (is_wp_error($change)) {
			return $change;
		}
		$change += ['synced' => true];
		return Toolkit::run('pattern', 'wp_create_pattern', 0, $args, $auth, ['op' => 'create'] + $change, 'pattern_created');
	}

	public static function updatePattern(array $args, array $auth): mixed
	{
		$loaded = self::userPattern($args, $auth, 'edit_post');
		if (is_wp_error($loaded)) {
			return $loaded;
		}
		$change = self::patternChange($args, (int) $auth['user_id']);
		if (is_wp_error($change)) {
			return $change;
		}
		if (!$change['fields'] && !array_key_exists('synced', $change) && !array_key_exists('categories', $change)) {
			return Toolkit::error('invalid_arguments', __('Send at least one of title, content, synced or categories.', 'llamly'), 400);
		}
		return Toolkit::run('pattern', 'wp_update_pattern', $loaded, $args, $auth, ['op' => 'update'] + $change, 'pattern_updated');
	}

	public static function deletePattern(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_delete_pattern');
		if ($replay !== null) {
			return $replay;
		}
		$loaded = self::userPattern($args, $auth, 'delete_post');
		if (is_wp_error($loaded)) {
			return $loaded;
		}
		if (!EMPTY_TRASH_DAYS) {
			return Toolkit::error('trash_disabled', __('The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so deleting would be permanent. Nothing was changed.', 'llamly'), 409);
		}
		if (get_post_status($loaded) === 'trash' && !empty($args['dry_run'])) {
			return Toolkit::error('already_trashed', __('The pattern is already in the trash.', 'llamly'), 409);
		}
		$intent = ['op' => 'trash', 'args_hash' => self::argsHash($args)];
		$refs = PatternObject::referencedBy($loaded, 100);
		if ($refs) {
			$intent['referenced_by'] = $refs;
			$intent['warnings'] = [sprintf('The pattern is embedded in %d item(s); those blocks render empty until the pattern is restored.', count($refs))];
		}
		return Toolkit::run('pattern', 'wp_delete_pattern', $loaded, $args, $auth, $intent, 'pattern_trashed');
	}

	public static function saveTemplate(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_save_template');
		if ($replay !== null) {
			return $replay;
		}
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'edit_theme_options')) {
			return Toolkit::error('forbidden', __('The connected user cannot edit templates.', 'llamly'), 403);
		}
		$type = (string) ($args['type'] ?? 'wp_template');
		$resolved = self::resolveTemplate((string) $args['id'], $type);
		if (is_wp_error($resolved)) {
			return $resolved;
		}
		if ($resolved['theme'] !== get_stylesheet()) {
			return Toolkit::error('not_active_theme', __('Only templates of the active theme can be saved.', 'llamly'), 409);
		}
		$fields = [];
		$warnings = [];
		if (isset($args['title'])) {
			$fields['post_title'] = sanitize_text_field((string) $args['title']);
		}
		if (array_key_exists('description', $args)) {
			$fields['post_excerpt'] = sanitize_text_field((string) $args['description']);
		}
		if (array_key_exists('content', $args)) {
			try {
				$fields['post_content'] = BlockTree::filterDocument((string) $args['content'], $warnings);
			} catch (MutationFailure $failure) {
				return Toolkit::error($failure->errorCode, $failure->reason(), $failure->httpStatus);
			}
		}
		$area = null;
		if (isset($args['area'])) {
			if ($type !== 'wp_template_part') {
				return Toolkit::error('invalid_arguments', __('area only applies to template parts.', 'llamly'), 400);
			}
			$allowed = function_exists('get_allowed_block_template_part_areas') ? array_column(get_allowed_block_template_part_areas(), 'area') : ['header', 'footer', 'uncategorized'];
			if (!in_array((string) $args['area'], $allowed, true)) {
				return Toolkit::error('invalid_arguments', sprintf(/* translators: %s: comma-separated allowed areas. */ __('Unknown template part area. Allowed: %s.', 'llamly'), implode(', ', $allowed)), 400);
			}
			$area = (string) $args['area'];
		}
		if (!$fields && $area === null) {
			return Toolkit::error('invalid_arguments', __('Send at least one of title, description, content or area.', 'llamly'), 400);
		}
		$base = ['args_hash' => self::argsHash($args)] + ($warnings ? ['warnings' => array_values(array_unique($warnings))] : []);
		$dryRun = !empty($args['dry_run']);
		$expected = (string) ($args['expected_hash'] ?? '');
		if ($resolved['override'] !== null) {
			$intent = ['op' => 'update', 'fields' => $fields] + ($area !== null ? ['area' => $area] : []) + $base;
			return Toolkit::run('template', 'wp_save_template', $resolved['override'], $args, $auth, $intent, 'template_updated');
		}
		if (!hash_equals($resolved['hash'], $expected)) {
			if (!$dryRun) {
				return Toolkit::error('stale_revision', __('The template changed after the caller read it. Read it again with wp_get_template before saving.', 'llamly'), 409);
			}
		}
		if ($resolved['template'] === null) {
			if (!isset($fields['post_content'])) {
				return Toolkit::error('object_not_found', __('Template not found. Send content to create a new custom template with this id.', 'llamly'), 404);
			}
			if (sanitize_title($resolved['slug']) !== $resolved['slug']) {
				return Toolkit::error('invalid_arguments', __('The slug of a new template must be lowercase letters, numbers and dashes.', 'llamly'), 400);
			}
			$intent = ['op' => 'create', 'theme' => $resolved['theme'], 'slug' => $resolved['slug'], 'template_type' => $type,
				'fields' => ['post_title' => $fields['post_title'] ?? $resolved['slug'], 'post_excerpt' => $fields['post_excerpt'] ?? '', 'post_content' => $fields['post_content']]]
				+ ($type === 'wp_template_part' ? ['area' => $area ?? 'uncategorized'] : []) + $base;
		} else {
			$intent = ['op' => 'create', 'materialized' => true] + self::materializeIntent($resolved, $fields, $area) + $base;
		}
		$result = self::withTemplateLock($resolved, static fn() => Toolkit::run('template', 'wp_save_template', 0, $args, $auth, $intent, 'template_created'));
		if ($dryRun && is_array($result)) {
			$result['would_materialize_override'] = $resolved['template'] !== null;
			if (!hash_equals($resolved['hash'], $expected)) {
				$result['would_fail'] = 'stale_revision';
			}
		}
		return $result;
	}

	public static function resetTemplate(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_reset_template');
		if ($replay !== null) {
			return $replay;
		}
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		if (!user_can((int) $auth['user_id'], 'edit_theme_options')) {
			return Toolkit::error('forbidden', __('The connected user cannot edit templates.', 'llamly'), 403);
		}
		$resolved = self::resolveTemplate((string) $args['id'], (string) ($args['type'] ?? 'wp_template'));
		if (is_wp_error($resolved)) {
			return $resolved;
		}
		if ($resolved['theme'] !== get_stylesheet()) {
			return Toolkit::error('not_active_theme', __('Only templates of the active theme can be reset.', 'llamly'), 409);
		}
		if ($resolved['override'] === null) {
			return Toolkit::error('no_override', __('This template has no saved customization; it already comes from the theme.', 'llamly'), 409);
		}
		global $wpdb;
		$warnings = [];
		$revisions = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $resolved['override']));
		if ($revisions > TemplateObject::MAX_REVISIONS) {
			$warnings[] = sprintf('Only the latest %d of %d revisions are kept for undo; older revisions are lost.', TemplateObject::MAX_REVISIONS, $revisions);
		}
		if ($resolved['template'] !== null && !$resolved['template']->has_theme_file) {
			$warnings[] = 'This custom template has no theme file: resetting removes it from the site.';
		}
		$intent = ['op' => 'delete', 'args_hash' => self::argsHash($args)] + ($warnings ? ['warnings' => $warnings] : []);
		return Toolkit::run('template', 'wp_reset_template', $resolved['override'], $args, $auth, $intent, 'template_reset');
	}

	public static function updateGlobalStyles(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_update_global_styles');
		if ($replay !== null) {
			return $replay;
		}
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		if (!class_exists('WP_Theme_JSON') || !method_exists('WP_Theme_JSON', 'remove_insecure_properties')) {
			return Toolkit::error('unsupported', __('This WordPress version cannot validate global styles safely.', 'llamly'), 501);
		}
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, 'edit_theme_options')) {
			return Toolkit::error('forbidden', __('The connected user cannot change the global styles.', 'llamly'), 403);
		}
		if (!isset($args['settings']) && !isset($args['styles'])) {
			return Toolkit::error('invalid_arguments', __('Send settings, styles or both.', 'llamly'), 400);
		}
		$current = self::globalStylesState();
		$dryRun = !empty($args['dry_run']);
		$stale = !hash_equals($current['hash'], (string) ($args['expected_hash'] ?? ''));
		if ($stale && !$dryRun) {
			return Toolkit::error('stale_revision', __('The global styles changed after the caller read them. Read them again with wp_get_global_styles.', 'llamly'), 409);
		}
		$given = [];
		foreach (['settings', 'styles'] as $part) {
			if (isset($args[$part])) {
				$given[$part] = (array) $args[$part];
			}
		}
		if (GlobalStylesObject::cssOf($given)) {
			$error = Toolkit::error('css_not_supported', __('Custom CSS cannot be written through Llamly. Remove every css key from styles and use the WordPress Site Editor for custom CSS.', 'llamly'), 400);
			// LLP-476: the additional CSS is a Llamly Pro feature (Pro\ProFeatures); the code and the message stay the same.
			$error->add_data(['status' => 400, 'custom_css' => true]);
			return $error;
		}
		$data = GlobalStylesObject::decode($current['state']) ?? [];
		$replace = ($args['mode'] ?? 'merge') === 'replace';
		$patch = [];
		foreach (['settings', 'styles'] as $part) {
			if (!isset($args[$part])) {
				continue;
			}
			$given = (array) $args[$part];
			$patch[$part] = $given;
			$base = is_array($data[$part] ?? null) ? $data[$part] : [];
			$data[$part] = $replace ? self::withoutNulls($given) : self::deepMerge($base, $given);
		}
		foreach (['patch' => self::withoutNulls($patch), 'result' => $data] as $label => $candidate) {
			$unsafe = self::unsafePaths($candidate);
			if ($unsafe) {
				return new \WP_Error('unsafe_value', sprintf(/* translators: 1: style section, 2: comma-separated invalid values. */ __('Global styles contain values WordPress would remove as unsafe or invalid (%1$s): %2$s. Nothing was changed.', 'llamly'), $label, implode(', ', array_slice($unsafe, 0, 10))), ['status' => 400, 'paths' => array_slice($unsafe, 0, 50)]);
			}
		}
		// Replacing styles would drop the custom CSS saved in the Site Editor; Llamly never changes it.
		if (GlobalStylesObject::cssOf(GlobalStylesObject::decode($current['state'])) !== GlobalStylesObject::cssOf($data)) {
			return Toolkit::error('css_not_supported', __('This change would remove the custom CSS saved in the Site Editor. Use mode merge to keep it.', 'llamly'), 400);
		}
		$out = ['version' => \WP_Theme_JSON::LATEST_SCHEMA, 'isGlobalStylesUserThemeJSON' => true];
		foreach (['settings', 'styles'] as $part) {
			$out[$part] = is_array($data[$part] ?? null) && $data[$part] ? $data[$part] : (object) [];
		}
		$content = wp_json_encode($out);
		if (!is_string($content)) {
			return Toolkit::error('invalid_arguments', __('The global styles could not be encoded as JSON.', 'llamly'), 400);
		}
		$intent = ['op' => $current['id'] === null ? 'create' : 'update', 'content' => $content, 'args_hash' => self::argsHash($args)];
		if ($current['id'] === null) {
			$intent['theme'] = get_stylesheet();
			$lock = 'llamly:' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|gstyles-create|' . get_stylesheet()), 0, 40);
			if (!ObjectMutex::acquireName($lock, 5)) {
				return Toolkit::error('operation_in_progress', __('Another save of the global styles is in progress. Retry with the same idempotency key.', 'llamly'), 409);
			}
			try {
				$result = Toolkit::run('global_styles', 'wp_update_global_styles', 0, $args, $auth, $intent, 'global_styles_created');
			} finally {
				ObjectMutex::releaseName($lock);
			}
		} else {
			$result = Toolkit::run('global_styles', 'wp_update_global_styles', $current['id'], $args, $auth, $intent, 'global_styles_updated');
		}
		if ($dryRun && $stale && is_array($result)) {
			$result['would_fail'] = 'stale_revision';
		}
		return $result;
	}

	// ------------------------------------------------------------------ targets and helpers

	/**
	 * Resolve the document of wp_list_blocks / wp_edit_blocks.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function target(array $args, int $userId, bool $forWrite): array|\WP_Error
	{
		$hasPost = isset($args['post_id']);
		$hasTemplate = isset($args['template']);
		if ($hasPost === $hasTemplate) {
			return Toolkit::error('invalid_arguments', __('Send exactly one of post_id or template.', 'llamly'), 400);
		}
		if ($hasPost) {
			$postId = (int) $args['post_id'];
			$type = (string) get_post_type($postId);
			if ($type === '') {
				return Toolkit::error('object_not_found', __('The post was not found.', 'llamly'), 404);
			}
			if (in_array($type, TemplateObject::TYPES, true)) {
				return Toolkit::error('invalid_arguments', __('Address templates with template "theme//slug" instead of post_id.', 'llamly'), 400);
			}
			if ($type === 'wp_block') {
				$adapter = 'pattern';
			} elseif (ContentGuard::isAllowedType($type)) {
				$adapter = 'post';
			} else {
				return Toolkit::error('unsupported_post_type', __('Blocks of this post type cannot be read or edited here.', 'llamly'), 400);
			}
			$object = Engine::adapter($adapter);
			$state = $object ? $object->capture($postId) : null;
			if ($state === null) {
				return Toolkit::error('object_not_found', __('The post was not found.', 'llamly'), 404);
			}
			$allowed = $forWrite ? user_can($userId, 'edit_post', $postId) : ($adapter === 'post' ? PostTools::canRead($userId, $state) : user_can($userId, 'read_post', $postId));
			if (!$allowed) {
				return Toolkit::error('forbidden', $forWrite ? __('The connected user cannot edit this post.', 'llamly') : __('The connected user cannot read this post.', 'llamly'), 403);
			}
			return [
				'adapter' => $adapter,
				'post_id' => $postId,
				'template' => null,
				'title' => (string) $state['fields']['post_title'],
				'kind' => $type,
				'content' => (string) $state['fields']['post_content'],
				'hash' => (string) $object->fingerprint($state),
			];
		}
		$unavailable = self::blockTheme();
		if ($unavailable) {
			return $unavailable;
		}
		if (!user_can($userId, 'edit_theme_options')) {
			return Toolkit::error('forbidden', __('The connected user cannot read or edit templates.', 'llamly'), 403);
		}
		$resolved = self::resolveTemplate((string) $args['template'], (string) ($args['template_type'] ?? ''));
		if (is_wp_error($resolved)) {
			return $resolved;
		}
		if ($resolved['template'] === null) {
			return Toolkit::error('object_not_found', __('Template not found. List the available ids with wp_list_templates.', 'llamly'), 404);
		}
		if ($forWrite && $resolved['theme'] !== get_stylesheet()) {
			return Toolkit::error('not_active_theme', __('Only templates of the active theme can be edited.', 'llamly'), 409);
		}
		return [
			'adapter' => 'template',
			'post_id' => (int) ($resolved['override'] ?? 0),
			'template' => $resolved['template']->id,
			'title' => (string) $resolved['template']->title,
			'kind' => $resolved['type'],
			'content' => $resolved['content'],
			'hash' => $resolved['hash'],
			'resolved' => $resolved,
		];
	}

	/**
	 * @return array{theme:string,slug:string,type:string,template:?\WP_Block_Template,override:?int,content:string,hash:string}|\WP_Error
	 */
	private static function resolveTemplate(string $id, string $type): array|\WP_Error
	{
		$parts = explode('//', $id);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			return Toolkit::error('invalid_arguments', __('Template ids look like "theme//slug".', 'llamly'), 400);
		}
		[$theme, $slug] = $parts;
		$types = $type !== '' ? [$type] : TemplateObject::TYPES;
		foreach ($types as $candidate) {
			$template = get_block_template($id, $candidate);
			if (!$template instanceof \WP_Block_Template) {
				continue;
			}
			$override = $theme === get_stylesheet() ? TemplateObject::overrideId($theme, $slug, $candidate) : null;
			if ($override !== null) {
				$adapter = new TemplateObject();
				$state = $adapter->capture($override);
				if ($state === null) {
					return Toolkit::error('object_not_found', __('The template override could not be read.', 'llamly'), 404);
				}
				return ['theme' => $theme, 'slug' => $slug, 'type' => $candidate, 'template' => $template, 'override' => $override, 'content' => (string) $state['fields']['post_content'], 'hash' => (string) $adapter->fingerprint($state)];
			}
			$content = (string) $template->content;
			return ['theme' => $theme, 'slug' => $slug, 'type' => $candidate, 'template' => $template, 'override' => null, 'content' => $content, 'hash' => TemplateObject::virtualHash($content)];
		}
		return ['theme' => $theme, 'slug' => $slug, 'type' => $types[0], 'template' => null, 'override' => null, 'content' => '', 'hash' => TemplateObject::PREFIX . '_' . Engine::ABSENT];
	}

	/**
	 * Create intent for the first override of a theme-file template, seeded from the theme content.
	 *
	 * @param array<string, mixed> $resolved
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private static function materializeIntent(array $resolved, array $fields, ?string $area = null): array
	{
		$template = $resolved['template'];
		$intent = [
			'theme' => $resolved['theme'],
			'slug' => $resolved['slug'],
			'template_type' => $resolved['type'],
			'virtual_hash' => $resolved['hash'],
			'fields' => $fields + [
				'post_title' => (string) ($template->title ?: $resolved['slug']),
				'post_excerpt' => (string) ($template->description ?? ''),
				'post_content' => (string) $template->content,
			],
		];
		if ($template->source === 'theme' || $template->has_theme_file) {
			$intent['origin'] = 'theme';
		}
		if ($resolved['type'] === 'wp_template_part') {
			$intent['area'] = $area ?? (string) ($template->area ?: 'uncategorized');
		}
		ksort($intent['fields']);
		return $intent;
	}

	/** Serialize creations of the same template override across Llamly writers. */
	private static function withTemplateLock(array $resolved, callable $run): mixed
	{
		$lock = 'llamly:' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . '|template-create|' . $resolved['theme'] . '|' . $resolved['type'] . '|' . $resolved['slug']), 0, 40);
		if (!ObjectMutex::acquireName($lock, 5)) {
			return Toolkit::error('operation_in_progress', __('Another save of this template is in progress. Retry with the same idempotency key.', 'llamly'), 409);
		}
		try {
			return $run();
		} finally {
			ObjectMutex::releaseName($lock);
		}
	}

	/** @return array{id:?int,state:?array,hash:string} */
	private static function globalStylesState(): array
	{
		$adapter = new GlobalStylesObject();
		$id = GlobalStylesObject::postIdFor(get_stylesheet());
		$state = $id !== null ? $adapter->capture($id) : null;
		return ['id' => $state === null ? null : $id, 'state' => $state, 'hash' => (string) $adapter->fingerprint($state)];
	}

	private static function blockTheme(): ?\WP_Error
	{
		if (!function_exists('wp_is_block_theme') || !function_exists('get_block_templates') || !class_exists('WP_Block_Template')) {
			return Toolkit::error('unsupported', __('This WordPress version has no block templates.', 'llamly'), 501);
		}
		if (!wp_is_block_theme()) {
			return new \WP_Error('not_block_theme', __('The active theme is a classic theme: templates, template parts, global styles and navigation menus of the site editor are not available.', 'llamly'), ['status' => 409, 'theme' => get_stylesheet()]);
		}
		return null;
	}

	/** @return array<string, mixed> */
	private static function templateItem(\WP_Block_Template $template): array
	{
		$override = $template->wp_id ? (int) $template->wp_id : null;
		if ($override !== null) {
			$adapter = new TemplateObject();
			$hash = $adapter->fingerprint($adapter->capture($override));
		} else {
			$hash = TemplateObject::virtualHash((string) $template->content);
		}
		return [
			'id' => $template->id,
			'type' => $template->type,
			'title' => (string) $template->title,
			'description' => (string) $template->description,
			'source' => $template->source,
			'has_override' => $override !== null,
			'post_id' => $override,
			'area' => $template->type === 'wp_template_part' ? (string) $template->area : null,
			'modified' => $override !== null ? (string) get_post_field('post_modified', $override) : null,
			'revision_hash' => $hash,
		];
	}

	/** @param array<string, mixed> $state @return array<string, mixed> */
	private static function userPatternItem(array $state): array
	{
		$categories = [];
		foreach ((array) ($state['terms'][PatternObject::CATEGORY] ?? []) as $termId) {
			$term = get_term((int) $termId, PatternObject::CATEGORY);
			if ($term instanceof \WP_Term) {
				$categories[] = $term->slug;
			}
		}
		return [
			'id' => (int) $state['post_id'],
			'title' => (string) $state['fields']['post_title'],
			'source' => 'user',
			'status' => (string) $state['fields']['post_status'],
			'synced' => PatternObject::isSynced($state),
			'categories' => $categories,
			'revision_hash' => PostAdapter::fingerprintOf($state),
		];
	}

	/** Resolve a user pattern for a write; registered names are always read-only. */
	private static function userPattern(array $args, array $auth, string $metaCap): int|\WP_Error
	{
		if (isset($args['name'])) {
			$registered = \WP_Block_Patterns_Registry::get_instance()->is_registered((string) $args['name']);
			return $registered
				? Toolkit::error('registered_pattern_readonly', __('Registered patterns (core, theme or plugin) are read-only. Create a user pattern with wp_create_pattern instead.', 'llamly'), 403)
				: Toolkit::error('object_not_found', __('No registered pattern has this name; user patterns are addressed by id.', 'llamly'), 404);
		}
		if (!isset($args['id'])) {
			return Toolkit::error('invalid_arguments', __('Send the id of the user pattern.', 'llamly'), 400);
		}
		$id = (int) $args['id'];
		if (PostAdapter::capture($id, false, PatternObject::TYPES) === null) {
			return Toolkit::error('object_not_found', __('No user pattern has this id.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], $metaCap, $id)) {
			return Toolkit::error('forbidden', __('The connected user cannot change this pattern.', 'llamly'), 403);
		}
		return $id;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function patternChange(array $args, int $userId): array|\WP_Error
	{
		$fields = [];
		$change = [];
		$warnings = [];
		if (isset($args['title'])) {
			$title = trim(sanitize_text_field((string) $args['title']));
			$fields['post_title'] = wp_kses_data($title);
			if ($fields['post_title'] === '') {
				return Toolkit::error('invalid_arguments', __('The title cannot be empty.', 'llamly'), 400);
			}
		}
		if (isset($args['content'])) {
			try {
				$fields['post_content'] = BlockTree::filterDocument((string) $args['content'], $warnings);
			} catch (MutationFailure $failure) {
				return Toolkit::error($failure->errorCode, $failure->reason(), $failure->httpStatus);
			}
		}
		if (array_key_exists('synced', $args)) {
			if (!$args['synced'] && !registered_meta_key_exists('post', PatternObject::SYNC_META, 'wp_block')) {
				return Toolkit::error('unsupported', __('This WordPress version has no unsynced patterns.', 'llamly'), 501);
			}
			$change['synced'] = (bool) $args['synced'];
		}
		if (array_key_exists('categories', $args)) {
			if (!taxonomy_exists(PatternObject::CATEGORY)) {
				return Toolkit::error('unsupported', __('This WordPress version has no pattern categories.', 'llamly'), 501);
			}
			$ids = [];
			foreach ((array) $args['categories'] as $slug) {
				$term = get_term_by('slug', (string) $slug, PatternObject::CATEGORY);
				if (!$term instanceof \WP_Term) {
					return Toolkit::error('invalid_terms', sprintf(/* translators: %s: pattern category slug. */ __('Pattern category %s does not exist.', 'llamly'), (string) $slug), 400);
				}
				$ids[] = (int) $term->term_id;
			}
			sort($ids);
			$change['categories'] = array_values(array_unique($ids));
		}
		if ($warnings) {
			$change['warnings'] = array_values(array_unique($warnings));
		}
		return ['fields' => $fields] + $change;
	}

	/**
	 * A committed call with the same credential and idempotency key returns its stored result before the current state
	 * is read again; the same key with different arguments is a mismatch.
	 */
	private static function replay(array $args, array $auth, string $tool): mixed
	{
		global $wpdb;
		if (!empty($args['dry_run']) || !is_string($args['idempotency_key'] ?? null)) {
			return null;
		}
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT tool, status, intended_data, result_data, created_at FROM %i WHERE credential_key = %s AND idempotency_key = %s LIMIT 1',
			Schema::tableNames()['operations'],
			(string) ($auth['credential_key'] ?? ''),
			$args['idempotency_key']
		), ARRAY_A);
		if (!is_array($row)) {
			return null;
		}
		$intent = json_decode((string) $row['intended_data'], true);
		if ((string) $row['tool'] !== $tool || !is_array($intent) || ($intent['args_hash'] ?? '') !== self::argsHash($args)) {
			return Toolkit::error('idempotency_mismatch', __('This idempotency key was already used for a different change.', 'llamly'), 409);
		}
		if ((string) $row['status'] === 'committed') {
			$result = json_decode((string) $row['result_data'], true);
			return is_array($result) ? \Llamly\Free\History\Engine::markReplayed($result, (string) $row['created_at']) : null;
		}
		return null;
	}

	private static function argsHash(array $args): string
	{
		// confirm is the agent's safe-mode answer, not part of the change: a retry that adds it is the same request.
		unset($args['dry_run'], $args['idempotency_key'], $args['confirm']);
		return hash('sha256', (string) wp_json_encode(State::canonical($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** Assoc arrays merge by key; lists and scalars replace; null removes the key. */
	private static function deepMerge(array $base, array $patch): array
	{
		foreach ($patch as $key => $value) {
			if ($value === null) {
				unset($base[$key]);
				continue;
			}
			if (is_array($value) && self::isAssoc($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($base[$key])) {
				$base[$key] = self::deepMerge($base[$key], $value);
			} else {
				$base[$key] = is_array($value) ? self::withoutNulls($value) : $value;
			}
		}
		return $base;
	}

	private static function withoutNulls(array $value): array
	{
		foreach ($value as $key => $item) {
			if ($item === null) {
				unset($value[$key]);
			} elseif (is_array($item)) {
				$value[$key] = self::withoutNulls($item);
			}
		}
		return $value;
	}

	private static function isAssoc(array $value): bool
	{
		return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
	}

	/**
	 * Paths WordPress' own sanitizer would drop. Custom CSS is checked apart (it is never markup); everything else must
	 * survive WP_Theme_JSON::remove_insecure_properties() unchanged.
	 *
	 * @return list<string>
	 */
	private static function unsafePaths(array $data): array
	{
		$problems = [];
		$css = GlobalStylesObject::cssOf($data);
		$styles = GlobalStylesObject::withoutCss(is_array($data['styles'] ?? null) ? $data['styles'] : []);
		foreach ($css as $path => $value) {
			if (!is_string($value) || preg_match('#</?\w+#', $value) || stripos($value, '<') !== false) {
				$problems[] = $path;
			}
		}
		$input = ['version' => \WP_Theme_JSON::LATEST_SCHEMA];
		foreach (['settings' => $data['settings'] ?? [], 'styles' => $styles] as $part => $value) {
			$value = self::prune(is_array($value) ? $value : []);
			if ($value) {
				$input[$part] = $value;
			}
		}
		$sanitized = \WP_Theme_JSON::remove_insecure_properties($input);
		foreach (['settings', 'styles'] as $part) {
			$wanted = self::prune((array) ($input[$part] ?? []));
			$kept = self::prune(is_array($sanitized[$part] ?? null) ? $sanitized[$part] : []);
			self::diffPaths($wanted, $kept, $part, $problems);
		}
		return array_values(array_unique($problems));
	}

	private static function prune(array $value): array
	{
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$item = self::prune($item);
				if ($item === []) {
					unset($value[$key]);
					continue;
				}
				$value[$key] = $item;
			}
		}
		return $value;
	}

	/** @param list<string> $problems */
	private static function diffPaths(mixed $wanted, mixed $kept, string $path, array &$problems): void
	{
		if (count($problems) >= 50) {
			return;
		}
		if (is_array($wanted) && self::isAssoc($wanted)) {
			foreach ($wanted as $key => $item) {
				if (!is_array($kept) || !array_key_exists($key, $kept)) {
					$problems[] = $path . '.' . $key;
					continue;
				}
				self::diffPaths($item, $kept[$key], $path . '.' . $key, $problems);
			}
			return;
		}
		if (wp_json_encode(State::canonical($wanted)) !== wp_json_encode(State::canonical($kept))) {
			$problems[] = $path;
		}
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @return list<array<string, mixed>>
	 */
	private static function navigationLinks(array $blocks, int &$budget): array
	{
		$out = [];
		foreach ($blocks as $block) {
			if ($budget <= 0) {
				break;
			}
			$budget--;
			$name = (string) ($block['blockName'] ?? '');
			$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
			if (in_array($name, ['core/navigation-link', 'core/navigation-submenu'], true)) {
				$item = ['label' => wp_strip_all_tags((string) ($attrs['label'] ?? '')), 'url' => (string) ($attrs['url'] ?? '')];
				if (!empty($block['innerBlocks'])) {
					$item['children'] = self::navigationLinks($block['innerBlocks'], $budget);
				}
				$out[] = $item;
			} elseif ($name !== '') {
				$out[] = ['block' => $name];
			}
		}
		return $out;
	}
}
