<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\Core\Schema;
use Llamly\Free\History\Adapters\PostObject;
use Llamly\Free\History\BlockTree;
use Llamly\Free\History\ContentGuard;
use Llamly\Free\History\MutationFailure;
use Llamly\Free\History\PostAdapter;
use Llamly\Free\History\State;

/**
 * Posts, pages, custom post types and wp_navigation.
 *
 * Reads return `revision_hash` (S_POST). Writes build an intent and go through Toolkit::run() → Engine → PostObject.
 * Capability checks: primitive in the tool definition, object/type capabilities here and again under the engine lock.
 */
final class PostTools
{
	public const TITLE_MAX = 500;
	public const CONTENT_MAX = 1048576;
	public const META_VALUE_MAX = 65535;
	private const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}(:\\d{2})?(\\.\\d{1,6})?(Z|[+-]\\d{2}:?\\d{2})?$';
	/** Taxonomies that have their own adapters or are internal. */
	private const DENIED_TAXONOMIES = ['nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'elementor_library_type', 'elementor_library_category', 'elementor_font_type'];

	public static function register(): void
	{
		$id = ['type' => 'integer', 'minimum' => 1];
		Toolkit::read('wp_list_posts', 'List posts, pages, custom post types or wp_navigation items the connected user can read. Each item carries revision_hash for later writes.', 'content', 'edit_posts', [
			'post_type' => self::postTypeSchema(),
			'status' => ['type' => 'string', 'enum' => ['any', 'publish', 'draft', 'pending', 'private', 'future', 'trash'], 'default' => 'any'],
			'author' => $id,
			'search' => ['type' => 'string', 'maxLength' => 200],
			'category' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Category slug.'],
			'tag' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Tag slug.'],
			'orderby' => ['type' => 'string', 'enum' => ['date', 'title', 'modified', 'ID', 'menu_order'], 'default' => 'date'],
			'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
		] + Toolkit::pageProps(), [], [self::class, 'listPosts']);

		Toolkit::read('wp_get_post', 'Read one post (any allowed post type): content, excerpt, terms, public meta, featured image, sticky flag, preview link and revision_hash.', 'content', 'edit_posts', [
			'post_id' => $id,
		], ['post_id'], [self::class, 'getPost']);

		Toolkit::write('wp_create_post', 'Create a post. Status defaults to draft; publish, future (scheduled) and private go through unless the optional approval mode is on in Settings, which makes them wait for a human in wp-admin. Terms by ID only.', 'content', 'edit_posts', self::writeFields(true), ['title'], false, [self::class, 'createPost']);

		Toolkit::write('wp_update_post', 'Update fields of an existing post (at least one). Changing a public post or publishing goes through unless the optional approval mode is on in Settings, which makes it wait for a human in wp-admin. Elementor documents only accept title, slug, status, author, parent, order, date, terms, featured image and sticky.', 'content', 'edit_post', ['post_id' => $id] + self::writeFields(false), ['post_id'], true, [self::class, 'updatePost']);

		Toolkit::write('wp_delete_post', 'Move a post to the trash (reversible with wp_undo_change). Permanent deletion is not available. With safe mode on, needs confirm: true; ask the user before sending it. With the optional approval mode on, trashing a published, scheduled or private post waits for a human in wp-admin and confirm does not skip that.', 'content', 'delete_post', [
			'post_id' => $id,
			'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Permanent deletion is not supported; true is refused.'],
		], ['post_id'], true, [self::class, 'deletePost']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new PostObject()];
	}

	/** @return array<string, mixed> */
	private static function postTypeSchema(): array
	{
		$schema = ['type' => 'string', 'default' => 'post', 'maxLength' => 20, 'pattern' => '^[a-z0-9_-]{1,20}$'];
		// The enum is only reliable once every post type is registered; before that the handler validates.
		if (did_action('init') && !doing_action('init')) {
			$schema['enum'] = ContentGuard::allowedPostTypes();
		}
		return $schema;
	}

	/** @return array<string, array<string, mixed>> */
	private static function writeFields(bool $create): array
	{
		$ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 100, 'uniqueItems' => true];
		$fields = [
			'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TITLE_MAX],
			'content' => ['type' => 'string', 'maxLength' => self::CONTENT_MAX],
			'excerpt' => ['type' => 'string', 'maxLength' => 65535],
			'status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'private', 'publish', 'future']],
			'date' => ['type' => 'string', 'pattern' => self::DATE_PATTERN, 'description' => 'ISO 8601 publish date; without a timezone it is site-local time.'],
			'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'author' => ['type' => 'integer', 'minimum' => 1],
			'parent' => ['type' => 'integer', 'minimum' => 0],
			'menu_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
			'categories' => $ids + ['description' => 'Category term IDs (replaces the current set).'],
			'tags' => $ids + ['description' => 'Tag term IDs (replaces the current set).'],
			'terms' => ['type' => 'object', 'maxProperties' => 20, 'additionalProperties' => $ids, 'description' => 'taxonomy => term IDs; each listed taxonomy is replaced.'],
			'featured_media' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Image attachment ID; 0 removes the featured image.'],
			'sticky' => ['type' => 'boolean'],
			'meta' => ['type' => 'object', 'maxProperties' => 50, 'additionalProperties' => ['type' => ['string', 'null'], 'maxLength' => self::META_VALUE_MAX], 'description' => 'Public meta key => string value (null deletes). Protected or sensitive keys are returned in rejected_meta_keys.'],
		];
		if ($create) {
			$fields['post_type'] = self::postTypeSchema();
		}
		return $fields;
	}

	// ------------------------------------------------------------------ reads

	public static function listPosts(array $args, array $auth): array|\WP_Error
	{
		$userId = (int) $auth['user_id'];
		$type = self::typeObject((string) ($args['post_type'] ?? 'post'));
		if (is_wp_error($type)) {
			return $type;
		}
		if (!user_can($userId, $type->cap->edit_posts)) {
			return Toolkit::error('forbidden', __('The connected user cannot list this post type.', 'llamly'), 403);
		}
		[$page, $perPage] = Toolkit::page($args);
		$status = (string) ($args['status'] ?? 'any');
		$query = [
			'post_type' => $type->name,
			'post_status' => $status === 'any' ? ['publish', 'draft', 'pending', 'private', 'future'] : [$status],
			'posts_per_page' => $perPage,
			'paged' => $page,
			'orderby' => (string) ($args['orderby'] ?? 'date'),
			'order' => (string) ($args['order'] ?? 'DESC'),
			'fields' => 'ids',
			'ignore_sticky_posts' => true,
			'perm' => 'readable',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
		// Without edit_others_posts, non-public statuses are limited to the user's own posts so totals match the items.
		if (!user_can($userId, $type->cap->edit_others_posts) && $status !== 'publish') {
			$query['author'] = $userId;
		}
		if (isset($args['author'])) {
			if (isset($query['author']) && (int) $args['author'] !== $userId) {
				return Toolkit::listResult([], 0, $page, $perPage);
			}
			$query['author'] = (int) $args['author'];
		}
		if (($args['search'] ?? '') !== '') {
			$query['s'] = sanitize_text_field((string) $args['search']);
		}
		if (($args['category'] ?? '') !== '') {
			$query['category_name'] = sanitize_title((string) $args['category']);
		}
		if (($args['tag'] ?? '') !== '') {
			$query['tag'] = sanitize_title((string) $args['tag']);
		}
		$wpQuery = new \WP_Query($query);
		$items = [];
		foreach ($wpQuery->posts as $postId) {
			$state = PostAdapter::capture((int) $postId);
			if ($state === null || !self::canRead($userId, $state)) {
				continue;
			}
			$f = $state['fields'];
			$items[] = [
				'ID' => $state['post_id'],
				'title' => $f['post_title'],
				'slug' => $f['post_name'],
				'status' => $f['post_status'],
				'type' => $f['post_type'],
				'date' => self::date($f['post_date']),
				'modified' => self::date($f['post_modified']),
				'author_id' => $f['post_author'],
				'author' => (string) get_the_author_meta('display_name', $f['post_author']),
				'permalink' => (string) get_permalink($state['post_id']),
				'password_protected' => $f['post_password'] !== '',
				'revision_hash' => PostAdapter::fingerprintOf($state),
			];
		}
		return Toolkit::listResult($items, (int) $wpQuery->found_posts, $page, $perPage);
	}

	public static function getPost(array $args, array $auth): array|\WP_Error
	{
		$userId = (int) $auth['user_id'];
		$postId = (int) $args['post_id'];
		$state = PostAdapter::capture($postId);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('The post was not found or its type is not handled by the post tools.', 'llamly'), 404);
		}
		if (!self::canRead($userId, $state)) {
			return Toolkit::error('forbidden', __('The connected user cannot read this post.', 'llamly'), 403);
		}
		$f = $state['fields'];
		$terms = [];
		foreach ($state['terms'] as $taxonomy => $termIds) {
			foreach ($termIds as $termId) {
				$term = get_term($termId, $taxonomy);
				if ($term instanceof \WP_Term) {
					$terms[$taxonomy][] = ['term_id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
				}
			}
		}
		[$meta, $truncated] = self::publicMeta($state['meta']);
		$canEdit = user_can($userId, 'edit_post', $postId);
		$elementor = ContentGuard::isElementorDocument($postId);
		$result = [
			'id' => $state['post_id'],
			'type' => $f['post_type'],
			'status' => $f['post_status'],
			'title' => $f['post_title'],
			'content' => $f['post_content'],
			'excerpt' => $f['post_excerpt'],
			'slug' => $f['post_name'],
			'author_id' => $f['post_author'],
			'parent' => $f['post_parent'],
			'menu_order' => $f['menu_order'],
			'date' => self::date($f['post_date']),
			'date_gmt' => self::date($f['post_date_gmt']),
			'modified' => self::date($f['post_modified']),
			'modified_gmt' => self::date($f['post_modified_gmt']),
			'comment_status' => $f['comment_status'],
			'password_protected' => $f['post_password'] !== '',
			'terms' => $terms ?: (object) [],
			'meta' => $meta ?: (object) [],
			'featured_media' => (int) ($state['meta']['_thumbnail_id'][0] ?? 0),
			'sticky' => $state['sticky'],
			'template' => (string) ($state['meta']['_wp_page_template'][0] ?? ''),
			'permalink' => (string) get_permalink($postId),
			'preview_link' => $canEdit ? (string) get_preview_post_link($postId) : null,
			'is_elementor_document' => $elementor,
			'revision_hash' => PostAdapter::fingerprintOf($state),
		];
		if ($truncated) {
			$result['meta_truncated'] = $truncated;
		}
		if ($elementor) {
			$result['read_only_fields'] = ['content', 'excerpt', 'meta', 'revisions'];
		}
		return $result;
	}

	// ------------------------------------------------------------------ writes

	public static function createPost(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_create_post');
		if ($replay !== null) {
			return $replay;
		}
		$userId = (int) $auth['user_id'];
		$type = self::typeObject((string) ($args['post_type'] ?? 'post'));
		if (is_wp_error($type)) {
			return $type;
		}
		if (!user_can($userId, $type->cap->create_posts) || !user_can($userId, $type->cap->edit_posts)) {
			return Toolkit::error('forbidden', __('The connected user cannot create items of this post type.', 'llamly'), 403);
		}
		$change = self::buildChange($args, null, $type, $userId);
		if (is_wp_error($change)) {
			return $change;
		}
		$change['fields'] += ['post_type' => $type->name, 'post_status' => 'draft', 'post_author' => $userId];
		if (($change['fields']['post_title'] ?? '') === '') {
			return Toolkit::error('invalid_arguments', __('A post title is required.', 'llamly'), 400);
		}
		return self::runWrite('wp_create_post', 0, $args, $auth, ['op' => 'create'] + $change, 'post_created');
	}

	public static function updatePost(array $args, array $auth): mixed
	{
		$replay = self::replay($args, $auth, 'wp_update_post');
		if ($replay !== null) {
			return $replay;
		}
		$userId = (int) $auth['user_id'];
		$postId = (int) ($args['post_id'] ?? 0);
		$state = PostAdapter::capture($postId);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('The post was not found or its type is not handled by the post tools.', 'llamly'), 404);
		}
		if (!user_can($userId, 'edit_post', $postId)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit this post.', 'llamly'), 403);
		}
		$type = self::typeObject((string) $state['fields']['post_type']);
		if (is_wp_error($type)) {
			return $type;
		}
		$change = self::buildChange($args, $state, $type, $userId);
		if (is_wp_error($change)) {
			return $change;
		}
		$intent = ['op' => 'update'] + $change;
		if (!$change['fields'] && !array_intersect(['terms', 'meta', 'featured_media', 'sticky'], array_keys($change))) {
			return Toolkit::error('invalid_arguments', empty($change['rejected_meta_keys'])
				? __('Provide at least one field to change.', 'llamly')
				: __('Every meta key was rejected and nothing else changes.', 'llamly'), 400);
		}
		$guard = ContentGuard::checkIntent($postId, $intent);
		if ($guard) {
			return Toolkit::error($guard->errorCode, $guard->reason(), $guard->httpStatus);
		}
		return self::runWrite('wp_update_post', $postId, $args, $auth, $intent, 'post_updated');
	}

	public static function deletePost(array $args, array $auth): mixed
	{
		global $wpdb;
		$replay = self::replay($args, $auth, 'wp_delete_post');
		if ($replay !== null) {
			return $replay;
		}
		if (!empty($args['force'])) {
			return Toolkit::error('force_delete_unsupported', __('Permanent deletion is not available. The post can only be moved to the trash; a person can empty the trash in WordPress.', 'llamly'), 400);
		}
		$userId = (int) $auth['user_id'];
		$postId = (int) ($args['post_id'] ?? 0);
		$state = PostAdapter::capture($postId);
		if ($state === null) {
			return Toolkit::error('object_not_found', __('The post was not found or its type is not handled by the post tools.', 'llamly'), 404);
		}
		if (!user_can($userId, 'delete_post', $postId)) {
			return Toolkit::error('forbidden', __('The connected user cannot delete this post.', 'llamly'), 403);
		}
		// Without dry run the adapter refuses under lock, so a replay of a committed trash still returns its result.
		if (!empty($args['dry_run']) && $state['fields']['post_status'] === 'trash') {
			return Toolkit::error('already_trashed', __('The post is already in the trash.', 'llamly'), 409);
		}
		if (!EMPTY_TRASH_DAYS) {
			return Toolkit::error('trash_disabled', __('The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so trashing would delete permanently. Nothing was changed.', 'llamly'), 409);
		}
		$intent = ['op' => 'trash'];
		$guard = ContentGuard::checkIntent($postId, $intent);
		if ($guard) {
			return Toolkit::error($guard->errorCode, $guard->reason(), $guard->httpStatus);
		}
		$menuItems = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->postmeta} t ON t.post_id = m.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type' WHERE m.meta_key = '_menu_item_object_id' AND m.meta_value = %s",
			(string) $postId
		));
		if ($menuItems > 0) {
			$intent['warnings'] = [sprintf('The post is linked from %d menu item(s); they stay but point to a trashed post.', $menuItems)];
		}
		return self::runWrite('wp_delete_post', $postId, $args, $auth, $intent, 'post_trashed');
	}

	/**
	 * Validate and normalize create/update arguments into an intent fragment.
	 *
	 * @param array<string, mixed>|null $before Current state for an update, null for a create.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function buildChange(array $args, ?array $before, \WP_Post_Type $type, int $userId): array|\WP_Error
	{
		$postId = $before === null ? 0 : (int) $before['post_id'];
		$fields = [];
		$change = [];
		$warnings = [];

		if (array_key_exists('title', $args)) {
			// Same filtering WordPress applies on save, so the stored value equals the intent.
			$title = trim(sanitize_text_field((string) $args['title']));
			$fields['post_title'] = wp_kses_data($title);
			if ($fields['post_title'] === '') {
				return Toolkit::error('invalid_arguments', __('The title cannot be empty.', 'llamly'), 400);
			}
		}
		if (array_key_exists('content', $args)) {
			// Block-aware content policy for every user: block comment attributes (e.g. "a--b", var(--wp--...)) survive;
			// script, iframe, style, on* and core/html are refused and the rest passes kses (agents never write unfiltered HTML).
			try {
				$fields['post_content'] = BlockTree::filterDocument((string) $args['content'], $warnings);
			} catch (MutationFailure $failure) {
				return Toolkit::error($failure->errorCode, $failure->reason(), $failure->httpStatus);
			}
		}
		if (array_key_exists('excerpt', $args)) {
			$fields['post_excerpt'] = wp_kses_data((string) $args['excerpt']);
		}
		if (array_key_exists('author', $args)) {
			$author = (int) $args['author'];
			if (!get_user_by('id', $author) || !user_can($author, $type->cap->edit_posts)) {
				return Toolkit::error('invalid_author', __('The author does not exist or cannot edit this post type.', 'llamly'), 400);
			}
			if ($author !== $userId && !user_can($userId, $type->cap->edit_others_posts)) {
				return Toolkit::error('forbidden', __('The connected user cannot assign posts to another author.', 'llamly'), 403);
			}
			$fields['post_author'] = $author;
		}
		if (array_key_exists('date', $args)) {
			$dates = rest_get_date_with_gmt((string) $args['date']);
			if (!is_array($dates) || count($dates) !== 2) {
				return Toolkit::error('invalid_date', __('The date is not a valid ISO 8601 date.', 'llamly'), 400);
			}
			$fields['post_date'] = (string) $dates[0];
			$fields['post_date_gmt'] = (string) $dates[1];
		}
		if (array_key_exists('status', $args) || array_key_exists('date', $args)) {
			$status = (string) ($args['status'] ?? $before['fields']['post_status'] ?? 'draft');
			$gmt = (string) ($fields['post_date_gmt'] ?? $before['fields']['post_date_gmt'] ?? '0000-00-00 00:00:00');
			$future = $gmt !== '0000-00-00 00:00:00' && strtotime($gmt . ' UTC') > time();
			if ($status === 'publish' && $future) {
				$status = 'future';
				$warnings[] = 'The date is in the future, so the post is scheduled (status future) instead of published.';
			} elseif ($status === 'future' && !$future) {
				return Toolkit::error('invalid_date', __('Scheduling (status future) needs a date in the future.', 'llamly'), 400);
			}
			if (array_key_exists('status', $args) || $status !== ($before['fields']['post_status'] ?? '')) {
				$fields['post_status'] = $status;
			}
			if (in_array($status, ['publish', 'future', 'private'], true) && $status !== ($before['fields']['post_status'] ?? '')
				&& !user_can($userId, $type->cap->publish_posts)
			) {
				return Toolkit::error('forbidden', __('The connected user cannot publish, schedule or make private this post type.', 'llamly'), 403);
			}
		}
		if (array_key_exists('parent', $args)) {
			$parent = (int) $args['parent'];
			if ($parent > 0) {
				$parentPost = get_post($parent);
				if (!$type->hierarchical || !$parentPost instanceof \WP_Post || $parentPost->post_type !== $type->name || $parent === $postId
					|| ($postId > 0 && in_array($postId, array_map('intval', get_post_ancestors($parent)), true))
				) {
					return Toolkit::error('invalid_parent', __('The parent must be another item of the same hierarchical post type and not one of its descendants.', 'llamly'), 400);
				}
			}
			$fields['post_parent'] = $parent;
		}
		if (array_key_exists('menu_order', $args)) {
			$fields['menu_order'] = (int) $args['menu_order'];
		}
		if (array_key_exists('slug', $args)) {
			$slug = sanitize_title((string) $args['slug']);
			if ($slug === '') {
				return Toolkit::error('invalid_slug', __('The slug is empty after sanitizing.', 'llamly'), 400);
			}
			$status = (string) ($fields['post_status'] ?? $before['fields']['post_status'] ?? 'draft');
			$parent = (int) ($fields['post_parent'] ?? $before['fields']['post_parent'] ?? 0);
			$unique = wp_unique_post_slug($slug, $postId, $status, $type->name, $parent);
			if ($unique !== $slug) {
				return new \WP_Error('slug_conflict', __('Another item already uses this slug.', 'llamly'), ['status' => 409, 'suggested_slug' => $unique]);
			}
			$fields['post_name'] = $slug;
		}

		$terms = self::termIntent($args, $type, $userId);
		if (is_wp_error($terms)) {
			return $terms;
		}
		if ($terms) {
			$change['terms'] = $terms;
		}
		if (array_key_exists('featured_media', $args)) {
			$media = (int) $args['featured_media'];
			if (!post_type_supports($type->name, 'thumbnail')) {
				return Toolkit::error('invalid_featured_media', __('This post type does not support featured images.', 'llamly'), 400);
			}
			if ($media > 0 && (get_post_type($media) !== 'attachment' || !wp_attachment_is_image($media))) {
				return Toolkit::error('invalid_featured_media', __('featured_media must be an image attachment ID.', 'llamly'), 400);
			}
			$change['featured_media'] = $media;
		}
		if (array_key_exists('sticky', $args)) {
			if ($type->name !== 'post') {
				return Toolkit::error('invalid_arguments', __('Only posts of type post can be sticky.', 'llamly'), 400);
			}
			if (!user_can($userId, $type->cap->edit_others_posts)) {
				return Toolkit::error('forbidden', __('The connected user cannot change sticky posts.', 'llamly'), 403);
			}
			$change['sticky'] = (bool) $args['sticky'];
		}
		if (array_key_exists('meta', $args)) {
			$meta = [];
			$rejected = [];
			foreach ((array) $args['meta'] as $key => $value) {
				$key = (string) $key;
				$exists = $postId > 0 && isset($before['meta'][$key]);
				$cap = $value === null ? 'delete_post_meta' : ($exists ? 'edit_post_meta' : 'add_post_meta');
				if (!ContentGuard::metaWritable($key, $type->name) || ($postId > 0 && !user_can($userId, $cap, $postId, $key))
					|| ($postId === 0 && self::metaNeedsObjectAuth($key, $type->name))
				) {
					$rejected[] = $key;
					continue;
				}
				$meta[$key] = $value === null ? null : (string) $value;
			}
			ksort($meta, SORT_STRING);
			sort($rejected, SORT_STRING);
			if ($meta) {
				$change['meta'] = $meta;
			}
			if ($rejected) {
				$change['rejected_meta_keys'] = $rejected;
			}
		}
		if ($warnings) {
			$change['warnings'] = $warnings;
		}
		return ['fields' => $fields] + $change;
	}

	/** @return array<string, list<int>>|\WP_Error */
	private static function termIntent(array $args, \WP_Post_Type $type, int $userId): array|\WP_Error
	{
		$requested = is_array($args['terms'] ?? null) ? $args['terms'] : [];
		foreach (['categories' => 'category', 'tags' => 'post_tag'] as $arg => $taxonomy) {
			if (array_key_exists($arg, $args)) {
				if (array_key_exists($taxonomy, $requested)) {
					return Toolkit::error('invalid_arguments', sprintf(/* translators: 1: taxonomy name, 2: argument name. */ __('Pass %1$s either as %2$s or inside terms, not both.', 'llamly'), $taxonomy, $arg), 400);
				}
				$requested[$taxonomy] = (array) $args[$arg];
			}
		}
		$terms = [];
		foreach ($requested as $taxonomy => $termIds) {
			$taxonomy = (string) $taxonomy;
			$object = get_taxonomy($taxonomy);
			if (!$object instanceof \WP_Taxonomy || in_array($taxonomy, self::DENIED_TAXONOMIES, true) || !is_object_in_taxonomy($type->name, $taxonomy)) {
				return Toolkit::error('invalid_taxonomy', sprintf(/* translators: %s: taxonomy name. */ __('The taxonomy %s cannot be assigned to this post type here.', 'llamly'), $taxonomy), 400);
			}
			if (!user_can($userId, $object->cap->assign_terms)) {
				return Toolkit::error('forbidden', sprintf(/* translators: %s: taxonomy name. */ __('The connected user cannot assign %s terms.', 'llamly'), $taxonomy), 403);
			}
			$ids = array_values(array_unique(array_map('intval', (array) $termIds)));
			sort($ids);
			foreach ($ids as $termId) {
				$term = get_term($termId, $taxonomy);
				if (!$term instanceof \WP_Term) {
					return Toolkit::error('invalid_terms', sprintf(/* translators: 1: term ID, 2: taxonomy name. */ __('Term %1$d does not exist in %2$s.', 'llamly'), $termId, $taxonomy), 400);
				}
			}
			$terms[$taxonomy] = $ids;
		}
		ksort($terms, SORT_STRING);
		return $terms;
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Engine write for post tools. Warnings and rejected meta keys depend on the clock and on state, so they are not
	 * part of the intent (and of the idempotency request hash); they are added to the response.
	 *
	 * @param array<string, mixed> $intent
	 */
	public static function runWrite(string $tool, int $objectId, array $args, array $auth, array $intent, string $event): mixed
	{
		$notes = array_intersect_key($intent, array_flip(['warnings', 'rejected_meta_keys']));
		$intent = array_diff_key($intent, $notes) + ['args_hash' => self::argsHash($args)];
		$result = Toolkit::run('post', $tool, $objectId, $args, $auth, $intent, $event);
		if (is_array($result)) {
			if (!empty($notes['warnings'])) {
				// Warnings are strings or structured arrays (derived_meta); dedup by serialized form.
				$merged = [];
				foreach (array_merge((array) ($result['warnings'] ?? []), $notes['warnings']) as $warning) {
					$merged[(string) wp_json_encode($warning)] = $warning;
				}
				$result['warnings'] = array_values($merged);
			}
			if (!empty($notes['rejected_meta_keys'])) {
				$result['rejected_meta_keys'] = $notes['rejected_meta_keys'];
			}
		}
		return $result;
	}

	/**
	 * Idempotent replay by (credential, idempotency_key) before any state-dependent validation, so a retry after the
	 * commit returns the stored result instead of failing on the state it produced (slug taken, already trashed).
	 * Returns null when the request must run normally.
	 */
	public static function replay(array $args, array $auth, string $tool): mixed
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
		if ((string) $row['tool'] !== $tool || (is_array($intent) && ($intent['args_hash'] ?? '') !== self::argsHash($args))) {
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

	/** Registered meta with its own auth_callback needs an object to authorize; a create has none yet. */
	private static function metaNeedsObjectAuth(string $key, string $postType): bool
	{
		foreach ([$postType, ''] as $subtype) {
			$registered = get_registered_meta_keys('post', $subtype);
			if (isset($registered[$key])) {
				$callback = $registered[$key]['auth_callback'] ?? null;
				return $callback !== null && $callback !== '__return_true';
			}
		}
		return false;
	}

	public static function typeObject(string $name): \WP_Post_Type|\WP_Error
	{
		$type = get_post_type_object($name);
		if (!$type instanceof \WP_Post_Type || !ContentGuard::isAllowedType($name)) {
			return Toolkit::error('unsupported_post_type', __('This post type is not handled by the post tools.', 'llamly'), 400);
		}
		return $type;
	}

	/** Read access against the captured state (status and password), not a cached WP_Post. @param array<string, mixed> $state */
	public static function canRead(int $userId, array $state): bool
	{
		$postId = (int) $state['post_id'];
		$fields = $state['fields'];
		if ($fields['post_password'] !== '' && !user_can($userId, 'edit_post', $postId)) {
			return false;
		}
		if ($fields['post_status'] === 'publish' && user_can($userId, 'read')) {
			return true;
		}
		return user_can($userId, 'read_post', $postId);
	}

	/**
	 * Public meta for output (protected and sensitive keys never leave). Serialized values are decoded without
	 * objects; strings over 4 KiB are cut.
	 *
	 * @param array<string, list<string>> $meta
	 * @return array{0:array<string,mixed>,1:list<string>}
	 */
	private static function publicMeta(array $meta): array
	{
		$out = [];
		$truncated = [];
		foreach ($meta as $key => $values) {
			$key = (string) $key;
			if ($key === '' || $key[0] === '_') {
				continue;
			}
			$decoded = [];
			foreach ($values as $value) {
				if (is_serialized($value)) {
					$value = @unserialize($value, ['allowed_classes' => false]);
				}
				if (is_string($value) && strlen($value) > 4096) {
					$value = mb_strcut($value, 0, 4096, 'UTF-8');
					$truncated[] = $key;
				}
				$decoded[] = $value;
			}
			$out[$key] = count($decoded) === 1 ? $decoded[0] : $decoded;
		}
		return [$out, array_values(array_unique($truncated))];
	}

	private static function date(string $value): ?string
	{
		return $value === '' || $value === '0000-00-00 00:00:00' ? null : $value;
	}
}
