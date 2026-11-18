<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\Auth\Authorization;
use Llamly\Free\Core\Redactor;
use Llamly\Free\Core\ToolRegistry;

/**
 * Elementor reads (the wp_elementor_* tools). Read only: Elementor documents are never written by Llamly
 * Free (see History\ContentGuard), so these tools return a purely informational data_hash and never a revision_hash.
 *
 * Availability: when Elementor is not active the tools are NOT registered, so they never show up in tools/list and an
 * agent is not offered tools that cannot work. Every handler still re-checks availability and answers a typed
 * `elementor_unavailable` (503), which covers Elementor being deactivated after the tool list was built and gives the
 * tests a seam (the `llamly_free_elementor_available` filter can only turn availability off, never on).
 *
 * Nothing here renders a document, loads the editor or enqueues scripts. Element data is read from post meta.
 */
final class ElementorTools
{
	/** Largest tool output (the elements part) in bytes. Above it the tree is forced into summary form. */
	public const MAX_OUTPUT_BYTES = 524288;
	/** Refuse to decode stored data bigger than this (memory). */
	private const MAX_RAW_BYTES = 16777216;
	private const MAX_LIST_SCAN = 1000;
	private const MAX_NODES = 5000;
	private const MAX_DEPTH = 64;
	private const SUMMARY_SETTINGS = ['title', 'editor', 'text', 'heading_tag', 'link', 'image', 'html', 'css_classes', '_element_id'];

	public static function register(): void
	{
		if (!self::available()) {
			return;
		}
		$id = ['type' => 'integer', 'minimum' => 1];
		$summarize = ['type' => 'boolean', 'default' => true, 'description' => 'true (default) returns a compact tree (ids, types, a few key settings). false returns the full element data when it fits in 512 KiB; larger output is summarized automatically and truncated is true.'];

		Toolkit::read('wp_elementor_list_templates', 'List Elementor library templates the connected user can edit, optionally by type (page, section, header, footer, popup, kit...). Read only; data_hash is informational.', 'elementor', 'edit_posts', [
			'type' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$', 'description' => 'Template type slug.'],
		] + Toolkit::pageProps(), [], [self::class, 'listTemplates']);

		Toolkit::read('wp_elementor_get_template', 'Read the element tree of one Elementor template (summarized by default). Read only; data_hash is informational and is not a revision_hash.', 'elementor', 'edit_posts', [
			'template_id' => $id,
			'summarize' => $summarize,
		], ['template_id'], [self::class, 'getTemplate']);

		Toolkit::read('wp_elementor_get_page_content', 'Read the Elementor element tree of a post or page built with the Elementor builder (summarized by default). Read only; change nothing with wp_update_post: Elementor content is not writable.', 'elementor', 'edit_posts', [
			'post_id' => $id,
			'summarize' => $summarize,
		], ['post_id'], [self::class, 'getPageContent']);

		Toolkit::read('wp_elementor_get_global_styles', 'Read the global colors and typography of the active Elementor kit. Read only.', 'elementor', 'edit_posts', [], [], [self::class, 'getGlobalStyles']);

		ToolRegistry::register([
			'name' => 'wp_elementor_list_widgets',
			'description' => 'List the registered Elementor widget types (name, title, icon, categories). Calls get_title, get_icon and get_categories of every listed widget, including widgets from third-party plugins (executes_third_party_code); it never renders, builds controls or loads scripts.',
			'category' => 'elementor',
			'access' => 'read',
			'capability' => 'edit_posts',
			'executes_third_party_code' => true,
			'inputSchema' => Toolkit::schema(Toolkit::pageProps(), []),
		], [self::class, 'listWidgets']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [];
	}

	public static function available(): bool
	{
		$active = defined('ELEMENTOR_VERSION') && class_exists('\Elementor\Plugin');
		return $active && (bool) apply_filters('llamly_free_elementor_available', true);
	}

	// ------------------------------------------------------------------ handlers

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function listTemplates(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_elementor_list_templates', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$userId = (int) $auth['user_id'];
		[$page, $perPage] = Toolkit::page($args);
		$query = [
			'post_type' => 'elementor_library',
			'post_status' => 'any',
			'fields' => 'ids',
			'posts_per_page' => self::MAX_LIST_SCAN + 1,
			'orderby' => 'date',
			'order' => 'DESC',
			'no_found_rows' => true,
		];
		$type = isset($args['type']) ? sanitize_key((string) $args['type']) : '';
		if ($type !== '') {
			$query['tax_query'] = [['taxonomy' => 'elementor_library_type', 'field' => 'slug', 'terms' => $type]]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$ids = array_map('intval', get_posts($query));
		$scanTruncated = count($ids) > self::MAX_LIST_SCAN;
		$ids = array_slice($ids, 0, self::MAX_LIST_SCAN);
		$allowed = array_values(array_filter($ids, static fn(int $postId): bool => user_can($userId, 'edit_post', $postId)));
		$slice = array_slice($allowed, ($page - 1) * $perPage, $perPage);
		if ($slice) {
			_prime_post_caches($slice, true, true);
		}
		$items = [];
		foreach ($slice as $postId) {
			$post = get_post($postId);
			if (!$post instanceof \WP_Post) {
				continue;
			}
			$items[] = [
				'id' => $postId,
				'title' => $post->post_title,
				'type' => self::templateType($postId),
				'status' => $post->post_status,
				'date_created' => self::date($post->post_date_gmt),
				'date_updated' => self::date($post->post_modified_gmt),
				'data_hash' => self::rawHash($postId),
			];
		}
		$result = Toolkit::listResult($items, count($allowed), $page, $perPage);
		$result['scan_truncated'] = $scanTruncated;
		return $result;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function getTemplate(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_elementor_get_template', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$templateId = (int) ($args['template_id'] ?? 0);
		$post = $templateId > 0 ? get_post($templateId) : null;
		if (!$post instanceof \WP_Post || $post->post_type !== 'elementor_library') {
			return Toolkit::error('object_not_found', __('The Elementor template was not found.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], 'edit_post', $templateId)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit this template.', 'llamly'), 403);
		}
		$content = self::content($templateId, !array_key_exists('summarize', $args) || !empty($args['summarize']));
		if (is_wp_error($content)) {
			return $content;
		}
		return ['template_id' => $templateId, 'title' => $post->post_title, 'type' => self::templateType($templateId)] + $content;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function getPageContent(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_elementor_get_page_content', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$userId = (int) $auth['user_id'];
		$postId = (int) ($args['post_id'] ?? 0);
		$post = $postId > 0 ? get_post($postId) : null;
		if (!$post instanceof \WP_Post) {
			return Toolkit::error('object_not_found', __('The post was not found.', 'llamly'), 404);
		}
		// Protected = password, not publicly published, or a type that is not publicly viewable (library items hold form
		// and webhook settings): reading it takes edit_post on top of read_post.
		$protected = $post->post_password !== '' || $post->post_status !== 'publish' || $post->post_type === 'elementor_library' || !is_post_type_viewable($post->post_type);
		$allowed = $protected
			? user_can($userId, 'read_post', $postId) && user_can($userId, 'edit_post', $postId)
			: user_can($userId, 'read_post', $postId) || user_can($userId, 'edit_post', $postId);
		if (!$allowed) {
			return Toolkit::error('forbidden', __('The connected user cannot read this post.', 'llamly'), 403);
		}
		if ($post->post_type === 'elementor_library') {
			return Toolkit::error('use_get_template', __('This is an Elementor library template. Read it with wp_elementor_get_template.', 'llamly'), 422);
		}
		if ((string) get_post_meta($postId, '_elementor_edit_mode', true) !== 'builder') {
			return Toolkit::error('not_elementor', __('This post is not built with the Elementor builder.', 'llamly'), 422);
		}
		$content = self::content($postId, !array_key_exists('summarize', $args) || !empty($args['summarize']));
		if (is_wp_error($content)) {
			return $content;
		}
		return ['post_id' => $postId, 'title' => $post->post_title, 'post_type' => $post->post_type, 'status' => $post->post_status] + $content;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function getGlobalStyles(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_elementor_get_global_styles', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		$kitId = (int) get_option('elementor_active_kit');
		$kit = $kitId > 0 ? get_post($kitId) : null;
		if (!$kit instanceof \WP_Post) {
			return Toolkit::error('object_not_found', __('There is no active Elementor kit.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], 'edit_post', $kitId)) {
			return Toolkit::error('forbidden', __('The connected user cannot edit the Elementor kit.', 'llamly'), 403);
		}
		$settings = get_post_meta($kitId, '_elementor_page_settings', true);
		$settings = is_array($settings) ? $settings : [];
		$colors = ['system' => (array) ($settings['system_colors'] ?? []), 'custom' => (array) ($settings['custom_colors'] ?? [])];
		$typography = ['system' => (array) ($settings['system_typography'] ?? []), 'custom' => (array) ($settings['custom_typography'] ?? [])];
		$result = [
			'kit_id' => $kitId,
			'colors' => Redactor::deep($colors),
			'typography' => Redactor::deep($typography),
			'data_hash' => hash('sha256', (string) wp_json_encode(['colors' => $colors, 'typography' => $typography], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
		];
		if (strlen((string) wp_json_encode($result)) > self::MAX_OUTPUT_BYTES) {
			$result['colors'] = ['system' => array_slice($result['colors']['system'], 0, 100), 'custom' => array_slice($result['colors']['custom'], 0, 100)];
			$result['typography'] = ['system' => array_slice($result['typography']['system'], 0, 100), 'custom' => array_slice($result['typography']['custom'], 0, 100)];
			$result['truncated'] = true;
		}
		return $result;
	}

	/** @param array<string, mixed> $args @param array<string, mixed> $auth */
	public static function listWidgets(array $args, array $auth): array|\WP_Error
	{
		$gate = self::gate('wp_elementor_list_widgets', $auth);
		if (is_wp_error($gate)) {
			return $gate;
		}
		[$page, $perPage] = Toolkit::page($args);
		try {
			$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			$types = is_object($manager) ? $manager->get_widget_types() : null;
		} catch (\Throwable $error) {
			$types = null;
		}
		if (!is_array($types)) {
			return Toolkit::error('elementor_unavailable', __('The Elementor widget registry is not available.', 'llamly'), 503);
		}
		ksort($types);
		$names = array_keys($types);
		$items = [];
		foreach (array_slice($names, ($page - 1) * $perPage, $perPage) as $name) {
			$name = (string) $name;
			$widget = $types[$name];
			$items[] = [
				'name' => $name,
				'title' => self::widgetString($widget, 'get_title', $name, 200),
				'icon' => self::widgetString($widget, 'get_icon', '', 100),
				'categories' => self::widgetCategories($widget),
			];
		}
		$result = Toolkit::listResult($items, count($names), $page, $perPage);
		$result['executes_third_party_code'] = true;
		return $result;
	}

	// ------------------------------------------------------------------ shared

	/** Availability plus the primitive capability / credential scope of the tool. */
	private static function gate(string $tool, array $auth): bool|\WP_Error
	{
		if (!self::available()) {
			return Toolkit::error('elementor_unavailable', __('Elementor is not active on this site.', 'llamly'), 503);
		}
		$definition = ToolRegistry::get($tool);
		if (!$definition) {
			return Toolkit::error('tool_not_found', __('The requested tool is not available.', 'llamly'), 404);
		}
		return Authorization::requireTool($auth, $definition);
	}

	/**
	 * Decode and shape the element tree stored in `_elementor_data`.
	 *
	 * @return array<string, mixed>|\WP_Error elements, summarized, truncated, forced_summarize, element_count, data_hash
	 */
	private static function content(int $postId, bool $summarize): array|\WP_Error
	{
		global $wpdb;
		$length = (int) $wpdb->get_var($wpdb->prepare("SELECT LENGTH(meta_value) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1", $postId));
		if ($length > self::MAX_RAW_BYTES) {
			return Toolkit::error('content_too_large', __('The stored Elementor data is too large to read through Llamly.', 'llamly'), 413);
		}
		$raw = get_post_meta($postId, '_elementor_data', true);
		if ($raw === '' || $raw === false || $raw === null) {
			return Toolkit::error('no_content', __('This item has no Elementor content.', 'llamly'), 404);
		}
		$data = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($data)) {
			return Toolkit::error('invalid_data', __('The stored Elementor data could not be decoded.', 'llamly'), 422);
		}
		$data = array_values($data);
		$hash = self::rawHash($postId);
		$count = 0;
		self::countNodes($data, $count, 0);

		$forced = false;
		$truncated = false;
		if (!$summarize) {
			// Cheap pre-check on the stored size before paying for redaction of a tree that cannot fit anyway.
			if ($length <= self::MAX_OUTPUT_BYTES * 2) {
				$full = Redactor::deep($data);
				if (strlen((string) wp_json_encode($full, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) <= self::MAX_OUTPUT_BYTES) {
					return ['elements' => $full, 'summarized' => false, 'truncated' => false, 'element_count' => $count, 'data_hash' => $hash];
				}
			}
			$forced = true;
			$truncated = true;
		}

		$budget = 0;
		$summary = self::summarize($data, true, $budget, 0);
		if (self::size($summary) > self::MAX_OUTPUT_BYTES) {
			$budget = 0;
			$summary = self::summarize($data, false, $budget, 0);
			$truncated = true;
		}
		while (count($summary) > 1 && self::size($summary) > self::MAX_OUTPUT_BYTES) {
			$summary = array_slice($summary, 0, (int) max(1, floor(count($summary) / 2)));
			$truncated = true;
		}
		if ($budget >= self::MAX_NODES) {
			$truncated = true;
		}
		$summary = Redactor::deep($summary);
		return ['elements' => $summary, 'summarized' => true, 'truncated' => $truncated, 'forced_summarize' => $forced, 'element_count' => $count, 'data_hash' => $hash];
	}

	/** @param array<int|string, mixed> $elements @return list<array<string, mixed>> */
	private static function summarize(array $elements, bool $withSettings, int &$budget, int $depth): array
	{
		$out = [];
		foreach ($elements as $element) {
			if (!is_array($element)) {
				continue;
			}
			if ($budget >= self::MAX_NODES) {
				break;
			}
			$budget++;
			$item = ['id' => (string) ($element['id'] ?? ''), 'elType' => (string) ($element['elType'] ?? 'unknown')];
			if (!empty($element['widgetType'])) {
				$item['widgetType'] = (string) $element['widgetType'];
			}
			if ($withSettings && !empty($element['settings']) && is_array($element['settings'])) {
				$settings = [];
				foreach (self::SUMMARY_SETTINGS as $key) {
					if (!isset($element['settings'][$key])) {
						continue;
					}
					$value = $element['settings'][$key];
					if (is_string($value) && mb_strlen($value) > 200) {
						$value = mb_substr($value, 0, 200) . '...';
					} elseif (is_array($value) && strlen((string) wp_json_encode($value)) > 1000) {
						$value = '[omitted: too large]';
					}
					$settings[$key] = $value;
				}
				if ($settings) {
					$item['settings'] = $settings;
				}
			}
			if (!empty($element['elements']) && is_array($element['elements'])) {
				if ($depth + 1 >= self::MAX_DEPTH) {
					$item['elements_omitted'] = true;
				} else {
					$item['elements'] = self::summarize($element['elements'], $withSettings, $budget, $depth + 1);
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/** @param array<int|string, mixed> $elements */
	private static function countNodes(array $elements, int &$count, int $depth): void
	{
		foreach ($elements as $element) {
			if (!is_array($element)) {
				continue;
			}
			$count++;
			if (!empty($element['elements']) && is_array($element['elements']) && $depth < 200) {
				self::countNodes($element['elements'], $count, $depth + 1);
			}
		}
	}

	private static function size(mixed $value): int
	{
		return strlen((string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** Informational digest of the stored `_elementor_data` (not a revision_hash; nothing here is writable). */
	private static function rawHash(int $postId): string
	{
		$raw = get_post_meta($postId, '_elementor_data', true);
		if (!is_string($raw)) {
			$raw = (string) wp_json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		return hash('sha256', $raw);
	}

	private static function templateType(int $postId): string
	{
		$terms = get_the_terms($postId, 'elementor_library_type');
		if (is_array($terms) && $terms) {
			$first = reset($terms);
			return $first instanceof \WP_Term ? $first->slug : 'unknown';
		}
		$meta = (string) get_post_meta($postId, '_elementor_template_type', true);
		return $meta !== '' ? $meta : 'unknown';
	}

	private static function date(string $gmt): ?string
	{
		if ($gmt === '' || strpos($gmt, '0000-00-00') === 0) {
			return null;
		}
		$time = strtotime($gmt . ' UTC');
		return $time ? gmdate('c', $time) : null;
	}

	/** Widget methods belong to third-party code: any Throwable is swallowed, results are coerced to short plain strings. */
	private static function widgetString(mixed $widget, string $method, string $fallback, int $max): string
	{
		try {
			if (is_object($widget) && method_exists($widget, $method)) {
				$value = $widget->$method();
				if (is_scalar($value)) {
					return mb_substr(trim(wp_strip_all_tags((string) $value)), 0, $max);
				}
			}
		} catch (\Throwable $error) {
			// Fall through to the fallback; a broken widget must not break the listing.
		}
		return $fallback;
	}

	/** @return list<string> */
	private static function widgetCategories(mixed $widget): array
	{
		try {
			if (is_object($widget) && method_exists($widget, 'get_categories')) {
				$value = $widget->get_categories();
				if (is_array($value)) {
					$out = [];
					foreach (array_slice($value, 0, 10) as $category) {
						if (is_scalar($category)) {
							$out[] = mb_substr(sanitize_text_field((string) $category), 0, 100);
						}
					}
					return $out;
				}
			}
		} catch (\Throwable $error) {
			// A broken widget reports no categories.
		}
		return [];
	}
}
