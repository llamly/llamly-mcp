<?php

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Llamly reads and writes its own tables and locks WordPress rows inside journal transactions; a cached read would defeat the revision checks.

namespace Llamly\Free\Tools;

use Llamly\Free\History\Adapters\TermObject;

/**
 * Terms (categories, tags and other taxonomies shown in the admin). Reads are plain queries; writes go through the
 * engine with the TermObject adapter. Navigation menus and other internal taxonomies are refused here: they have their
 * own domains.
 */
final class TermTools
{
	public static function register(): void
	{
		$taxonomy = ['type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$', 'description' => 'Taxonomy name (category, post_tag or another taxonomy with an admin UI). Internal taxonomies such as nav_menu are not available.'];
		$termId = ['type' => 'integer', 'minimum' => 1];

		Toolkit::read('wp_list_terms', 'List terms of a taxonomy with their revision_hash. Defaults to categories.', 'terms', 'edit_posts', [
			'taxonomy' => $taxonomy + ['default' => 'category'],
			'search' => ['type' => 'string', 'maxLength' => 200],
			'parent' => ['type' => 'integer', 'minimum' => 0],
			'hide_empty' => ['type' => 'boolean', 'default' => false],
		] + Toolkit::pageProps(), [], [self::class, 'listTerms']);

		Toolkit::write('wp_create_term', 'Create a term in a taxonomy. Reversible: undo removes it again while nothing uses it.', 'terms', 'manage_categories', [
			'taxonomy' => $taxonomy,
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'slug' => ['type' => 'string', 'maxLength' => 200],
			'description' => ['type' => 'string', 'maxLength' => 10000],
			'parent' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Parent term ID (hierarchical taxonomies only).'],
		], ['taxonomy', 'name'], false, [self::class, 'create']);

		Toolkit::write('wp_update_term', 'Change the name, slug, description or parent of a term. Needs the revision_hash from wp_list_terms. Changing a term that is in use changes public archive URLs. With the optional approval mode on, a public change waits for a human in wp-admin.', 'terms', 'manage_categories', [
			'taxonomy' => $taxonomy,
			'term_id' => $termId,
			'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
			'description' => ['type' => 'string', 'maxLength' => 10000],
			'parent' => ['type' => 'integer', 'minimum' => 0],
		], ['taxonomy', 'term_id'], true, [self::class, 'update']);

		Toolkit::write('wp_delete_term', 'Delete a term. Items using it lose it (or get the default term when it was their only one) and child terms move up. Reversible with the same IDs: undo restores the term, its metadata, assignments and children. The default term is refused. With safe mode on, needs confirm: true; ask the user before sending it. With the optional approval mode on, a public change waits for a human in wp-admin.', 'terms', 'manage_categories', [
			'taxonomy' => $taxonomy,
			'term_id' => $termId,
		], ['taxonomy', 'term_id'], true, [self::class, 'delete']);
	}

	/** @return list<\Llamly\Free\History\Adapter> */
	public static function adapters(): array
	{
		return [new TermObject()];
	}

	public static function listTerms(array $args, array $auth): array|\WP_Error
	{
		$name = (string) ($args['taxonomy'] ?? 'category');
		$object = self::taxonomy($name);
		if (is_wp_error($object)) {
			return $object;
		}
		if (!user_can((int) $auth['user_id'], $object->cap->assign_terms)) {
			return new \WP_Error('forbidden', __('The connected user cannot list terms of this taxonomy.', 'llamly'), ['status' => 403]);
		}
		[$page, $perPage] = Toolkit::page($args);
		$query = [
			'taxonomy' => $name,
			'hide_empty' => !empty($args['hide_empty']),
			'orderby' => 'name',
			'order' => 'ASC',
			'search' => (string) ($args['search'] ?? ''),
		];
		if (isset($args['parent'])) {
			$query['parent'] = (int) $args['parent'];
		}
		$total = (int) (new \WP_Term_Query($query + ['fields' => 'count']))->get_terms();
		$terms = (new \WP_Term_Query($query + ['number' => $perPage, 'offset' => ($page - 1) * $perPage]))->get_terms();
		$items = [];
		foreach (is_array($terms) ? $terms : [] as $term) {
			if (!$term instanceof \WP_Term) {
				continue;
			}
			$items[] = [
				'term_id' => (int) $term->term_id,
				'name' => (string) $term->name,
				'slug' => (string) $term->slug,
				'description' => (string) $term->description,
				'parent' => (int) $term->parent,
				'count' => (int) $term->count,
				'taxonomy' => (string) $term->taxonomy,
				'revision_hash' => TermObject::revisionHash((int) $term->term_id),
			];
		}
		return Toolkit::listResult($items, $total, $page, $perPage);
	}

	public static function create(array $args, array $auth): mixed
	{
		$object = self::taxonomy((string) $args['taxonomy']);
		if (is_wp_error($object)) {
			return $object;
		}
		$userId = (int) $auth['user_id'];
		if (!user_can($userId, $object->cap->edit_terms)) {
			return Toolkit::error('forbidden', __('The connected user cannot create terms in this taxonomy.', 'llamly'), 403);
		}
		$name = trim((string) $args['name']);
		if ($name === '') {
			return Toolkit::error('invalid_arguments', __('A term name is required.', 'llamly'), 400);
		}
		$fields = ['name' => $name];
		foreach (['slug', 'description'] as $key) {
			if (isset($args[$key])) {
				$fields[$key] = (string) $args[$key];
			}
		}
		if (isset($args['parent'])) {
			$parent = self::checkParent((int) $args['parent'], $object, 0);
			if (is_wp_error($parent)) {
				return $parent;
			}
			$fields['parent'] = (int) $args['parent'];
		}
		return Toolkit::run('term', 'wp_create_term', 0, $args, $auth, ['op' => 'create', 'taxonomy' => $object->name, 'fields' => $fields], 'term_created');
	}

	public static function update(array $args, array $auth): mixed
	{
		$loaded = self::existing($args, $auth, 'edit_term');
		if (is_wp_error($loaded)) {
			return $loaded;
		}
		[$object, $termId] = $loaded;
		$fields = [];
		foreach (['name', 'slug', 'description'] as $key) {
			if (isset($args[$key])) {
				$fields[$key] = (string) $args[$key];
			}
		}
		if (isset($fields['name']) && trim($fields['name']) === '') {
			return Toolkit::error('invalid_arguments', __('A term name cannot be empty.', 'llamly'), 400);
		}
		if (isset($args['parent'])) {
			$parent = self::checkParent((int) $args['parent'], $object, $termId);
			if (is_wp_error($parent)) {
				return $parent;
			}
			$fields['parent'] = (int) $args['parent'];
		}
		if (!$fields) {
			return Toolkit::error('invalid_arguments', __('Send at least one of name, slug, description or parent.', 'llamly'), 400);
		}
		return Toolkit::run('term', 'wp_update_term', $termId, $args, $auth, ['op' => 'update', 'taxonomy' => $object->name, 'fields' => $fields], 'term_updated');
	}

	public static function delete(array $args, array $auth): mixed
	{
		$loaded = self::existing($args, $auth, 'delete_term');
		if (is_wp_error($loaded)) {
			return $loaded;
		}
		[$object, $termId, $exists] = $loaded;
		// A term that is already gone still goes through the engine so a retried call replays its stored result.
		$blocker = $exists ? TermObject::deleteBlocker($termId) : null;
		if ($blocker !== null) {
			return Toolkit::error($blocker['code'], $blocker['message'], $blocker['status']);
		}
		return Toolkit::run('term', 'wp_delete_term', $termId, $args, $auth, ['op' => 'delete', 'taxonomy' => $object->name], 'term_deleted');
	}

	/** @return array{0:\WP_Taxonomy,1:int,2:bool}|\WP_Error */
	private static function existing(array $args, array $auth, string $metaCap): array|\WP_Error
	{
		$object = self::taxonomy((string) $args['taxonomy']);
		if (is_wp_error($object)) {
			return $object;
		}
		$termId = (int) $args['term_id'];
		$actual = TermObject::taxonomyOf($termId);
		if ($actual === '') {
			if (!user_can((int) $auth['user_id'], $object->cap->edit_terms)) {
				return Toolkit::error('forbidden', __('The connected user cannot change terms of this taxonomy.', 'llamly'), 403);
			}
			return [$object, $termId, false];
		}
		if ($actual !== $object->name) {
			return Toolkit::error('object_not_found', __('The term was not found in this taxonomy.', 'llamly'), 404);
		}
		if (!user_can((int) $auth['user_id'], $object->cap->edit_terms)) {
			return Toolkit::error('forbidden', __('The connected user cannot change terms of this taxonomy.', 'llamly'), 403);
		}
		if ($metaCap === 'delete_term' && TermObject::isDefault($termId, $actual)) {
			// map_meta_cap hides this as a plain denial; say why.
			return Toolkit::error('default_term', __('The default term cannot be deleted.', 'llamly'), 409);
		}
		if (!user_can((int) $auth['user_id'], $metaCap, $termId)) {
			return Toolkit::error('forbidden', __('The connected user cannot change this term.', 'llamly'), 403);
		}
		return [$object, $termId, true];
	}

	private static function taxonomy(string $name): \WP_Taxonomy|\WP_Error
	{
		if (!TermObject::allowedTaxonomy($name)) {
			return Toolkit::error('taxonomy_not_allowed', __('This taxonomy is unknown or not available through Llamly.', 'llamly'), 400);
		}
		return get_taxonomy($name);
	}

	private static function checkParent(int $parent, \WP_Taxonomy $object, int $termId): bool|\WP_Error
	{
		global $wpdb;
		if ($parent === 0) {
			return true;
		}
		if (!$object->hierarchical) {
			return Toolkit::error('invalid_parent', __('This taxonomy is not hierarchical, so terms cannot have a parent.', 'llamly'), 400);
		}
		if ($termId > 0 && $parent === $termId) {
			return Toolkit::error('invalid_parent', __('A term cannot be its own parent.', 'llamly'), 400);
		}
		if (TermObject::taxonomyOf($parent) !== $object->name) {
			return Toolkit::error('invalid_parent', __('The parent term was not found in this taxonomy.', 'llamly'), 400);
		}
		// A descendant of the term cannot become its parent.
		for ($cursor = $parent, $steps = 0; $cursor > 0 && $steps < 1000; $steps++) {
			if ($termId > 0 && $cursor === $termId) {
				return Toolkit::error('invalid_parent', __('A term cannot be moved under its own descendant.', 'llamly'), 400);
			}
			$cursor = (int) $wpdb->get_var($wpdb->prepare("SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $cursor, $object->name));
		}
		return true;
	}
}
