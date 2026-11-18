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
 * Taxonomy terms.
 *
 * The fingerprint covers the term row, its term_taxonomy row (without count) and termmeta. The journal snapshot also
 * carries the retain scope of a delete: relations (with term_order), children and the objects that were given the default
 * term because they lost their only term. Deleting and restoring use the same term_id and term_taxonomy_id.
 */
final class TermObject implements Adapter
{
	/** @var array<int, string> taxonomy of each term read before its effect, for the post-outcome invalidation of a removed term */
	private static array $taxonomies = [];

	public const MARKER = '_llamly_pending_operation';
	public const MAX_RELATIONS = 5000;
	public const INTERNAL = ['nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'post_format', 'elementor_library_type'];
	private const META_EXCLUDE = '/^_llamly_pending_operation$|secret|token|api_?key|_key$|password|passwd|nonce|^auth_|salt|^llamly_|^mailserver_|user_roles$/i';

	public static function allowedTaxonomy(string $taxonomy): bool
	{
		if ($taxonomy === '' || in_array($taxonomy, self::INTERNAL, true) || !taxonomy_exists($taxonomy)) {
			return false;
		}
		$object = get_taxonomy($taxonomy);
		return $object instanceof \WP_Taxonomy && !empty($object->show_ui);
	}

	/** @return list<string> */
	public static function allowedTaxonomies(): array
	{
		$names = array_keys(get_taxonomies(['show_ui' => true], 'names'));
		return array_values(array_diff($names, self::INTERNAL));
	}

	/** Taxonomy of a term ID straight from the database ('' when unknown). */
	public static function taxonomyOf(int $termId): string
	{
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d ORDER BY term_taxonomy_id ASC LIMIT 1", $termId));
	}

	/** Revision hash without the retain scope (cheap; used by listings). */
	public static function revisionHash(int $termId): ?string
	{
		$adapter = new self();
		return $adapter->fingerprint($adapter->capture($termId, false, false));
	}

	/** @return array{code:string,message:string,status:int}|null Why a delete must be refused before mutating. */
	public static function deleteBlocker(int $termId): ?array
	{
		global $wpdb;
		$state = (new self())->capture($termId, false, true);
		if ($state === null) {
			return ['code' => 'object_not_found', 'message' => __('The term was not found.', 'llamly'), 'status' => 404];
		}
		$taxonomy = (string) $state['taxonomy'];
		if (!self::allowedTaxonomy($taxonomy)) {
			return ['code' => 'taxonomy_not_allowed', 'message' => __('This taxonomy cannot be changed through Llamly.', 'llamly'), 'status' => 400];
		}
		if (self::isDefault($termId, $taxonomy)) {
			return ['code' => 'default_term', 'message' => __('The default term cannot be deleted.', 'llamly'), 'status' => 409];
		}
		if (!empty($state['shared'])) {
			return ['code' => 'shared_term', 'message' => __('This term row is shared with another taxonomy and cannot be deleted reversibly.', 'llamly'), 'status' => 409];
		}
		if ((int) $state['objects_total'] > self::MAX_RELATIONS || (int) $state['children_total'] > self::MAX_RELATIONS) {
			return ['code' => 'too_many_relations', 'message' => __('This term has too many assigned items or child terms to delete reversibly.', 'llamly'), 'status' => 409];
		}
		$sensitive = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->termmeta} WHERE term_id = %d", $termId));
		foreach ((array) $sensitive as $key) {
			if (preg_match(self::META_EXCLUDE, (string) $key) && (string) $key !== self::MARKER) {
				return ['code' => 'sensitive_meta', 'message' => __('This term carries protected metadata that cannot be journaled, so it cannot be deleted reversibly.', 'llamly'), 'status' => 409];
			}
		}
		return null;
	}

	public static function isDefault(int $termId, string $taxonomy): bool
	{
		if ($taxonomy === 'category' && (int) get_option('default_category') === $termId) {
			return true;
		}
		$object = get_taxonomy($taxonomy);
		return $object instanceof \WP_Taxonomy && !empty($object->default_term) && (int) get_option('default_term_' . $taxonomy) === $termId;
	}

	/** Default term WordPress assigns when a deletion leaves an object without terms: [term_id, term_taxonomy_id] or null. */
	private static function defaultTerm(string $taxonomy): ?array
	{
		global $wpdb;
		$id = 0;
		if ($taxonomy === 'category') {
			$id = (int) get_option('default_category');
		}
		$object = get_taxonomy($taxonomy);
		if ($object instanceof \WP_Taxonomy && !empty($object->default_term)) {
			$id = (int) get_option('default_term_' . $taxonomy);
		}
		if ($id < 1) {
			return null;
		}
		$tt = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $id, $taxonomy));
		return $tt > 0 ? [$id, $tt] : null;
	}

	public function type(): string
	{
		return 'term';
	}

	public function prefix(): string
	{
		return 't1';
	}

	public function capture(int $id, bool $forUpdate = false, bool $withObjects = true): ?array
	{
		global $wpdb;
		if ($id < 1) {
			return null;
		}
		$rows = $forUpdate
			? $wpdb->get_results($wpdb->prepare("SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d ORDER BY tt.term_taxonomy_id ASC FOR UPDATE", $id), ARRAY_A)
			: $wpdb->get_results($wpdb->prepare("SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d ORDER BY tt.term_taxonomy_id ASC", $id), ARRAY_A);
		if (!is_array($rows) || $wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The term could not be read from the database.', 'llamly'), 503);
		}
		if ($rows) {
			self::$taxonomies[$id] = (string) $rows[0]['taxonomy'];
		}
		if (!$rows) {
			return null;
		}
		$row = $rows[0];
		$meta = RawRows::captureMeta($wpdb->termmeta, 'term_id', $id, null, self::META_EXCLUDE, $forUpdate);
		if ($meta === null) {
			throw new MutationFailure('journal_unavailable', esc_html__('The term metadata could not be read from the database.', 'llamly'), 503);
		}
		$state = [
			'term_id' => (int) $row['term_id'],
			'name' => (string) $row['name'],
			'slug' => (string) $row['slug'],
			'term_group' => (int) $row['term_group'],
			'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
			'taxonomy' => (string) $row['taxonomy'],
			'description' => (string) $row['description'],
			'parent' => (int) $row['parent'],
			'meta' => $meta,
			'count' => (int) $row['count'],
			'shared' => count($rows) > 1,
		];
		if (!$withObjects) {
			return $state;
		}
		$tt = $state['term_taxonomy_id'];
		$taxonomy = $state['taxonomy'];
		$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt));
		$objects = [];
		if ($total <= self::MAX_RELATIONS) {
			$found = $wpdb->get_results($wpdb->prepare("SELECT object_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d ORDER BY object_id ASC", $tt), ARRAY_A);
			foreach ((array) $found as $relation) {
				$objects[] = [(int) $relation['object_id'], (int) $relation['term_order']];
			}
		}
		$childrenTotal = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = %s", $id, $taxonomy));
		$children = [];
		if ($childrenTotal <= self::MAX_RELATIONS) {
			foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = %s ORDER BY term_id ASC", $id, $taxonomy)) as $childId) {
				$children[] = (int) $childId;
			}
		}
		$default = self::defaultTerm($taxonomy);
		$assigned = [];
		if ($default !== null && $default[0] !== $id && $objects) {
			$found = $wpdb->get_col($wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = %d AND (SELECT COUNT(*) FROM {$wpdb->term_relationships} r2 INNER JOIN {$wpdb->term_taxonomy} t2 ON t2.term_taxonomy_id = r2.term_taxonomy_id WHERE r2.object_id = tr.object_id AND t2.taxonomy = %s) = 1 ORDER BY tr.object_id ASC",
				$tt,
				$taxonomy
			));
			foreach ((array) $found as $objectId) {
				$assigned[] = (int) $objectId;
			}
		}
		return $state + [
			'objects_total' => $total,
			'objects' => $objects,
			'children_total' => $childrenTotal,
			'children' => $children,
			'default_term_id' => $default !== null ? $default[0] : 0,
			'default_assigned' => $assigned,
		];
	}

	public function fingerprint(?array $state): ?string
	{
		if ($state === null) {
			return State::hash('t1', null);
		}
		return State::hash('t1', array_intersect_key($state, array_flip(['term_id', 'name', 'slug', 'term_group', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'meta'])));
	}

	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error
	{
		global $wpdb;
		$op = (string) ($intent['op'] ?? '');
		$taxonomy = (string) ($intent['taxonomy'] ?? '');
		if (!self::allowedTaxonomy($taxonomy)) {
			throw new MutationFailure('taxonomy_not_allowed', esc_html__('This taxonomy cannot be changed through Llamly.', 'llamly'), 400);
		}
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($op === 'create') {
			$name = (string) ($fields['name'] ?? '');
			$args = array_intersect_key($fields, array_flip(['slug', 'description', 'parent']));
			$created = wp_insert_term(wp_slash($name), $taxonomy, wp_slash($args));
			if (is_wp_error($created)) {
				throw new MutationFailure(esc_html($created->get_error_code() ?: 'mutation_failed'), esc_html($created->get_error_message()), 409);
			}
			$termId = (int) $created['term_id'];
			if ($wpdb->insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => self::MARKER, 'meta_value' => $operationId], ['%d', '%s', '%s']) !== 1) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded lookup (single object or capped list) needed to find references; no unbounded scan.
				throw new MutationFailure('journal_unavailable', esc_html__('The creation marker could not be stored.', 'llamly'), 503);
			}
			wp_cache_delete($termId, 'term_meta');
			clean_term_cache($termId, $taxonomy);
			return $termId;
		}
		$current = $this->capture($id, true, false);
		if ($current === null || (string) $current['taxonomy'] !== $taxonomy) {
			throw new MutationFailure('object_not_found', esc_html__('The term was not found.', 'llamly'), 404);
		}
		if ($op === 'update') {
			if (!empty($current['shared'])) {
				throw new MutationFailure('shared_term', esc_html__('This term row is shared with another taxonomy.', 'llamly'), 409);
			}
			if (array_key_exists('parent', $fields) && $this->createsLoop($id, (int) $fields['parent'], $taxonomy)) {
				throw new MutationFailure('invalid_parent', esc_html__('A term cannot be its own parent or a child of its own descendants.', 'llamly'), 400);
			}
			$args = array_intersect_key($fields, array_flip(['name', 'slug', 'description', 'parent']));
			$updated = wp_update_term($id, $taxonomy, wp_slash($args));
			if (is_wp_error($updated)) {
				throw new MutationFailure(esc_html($updated->get_error_code() ?: 'mutation_failed'), esc_html($updated->get_error_message()), 409);
			}
			clean_term_cache($id, $taxonomy);
			return $id;
		}
		if ($op === 'delete') {
			$this->remove($id, $taxonomy);
			return $id;
		}
		throw new MutationFailure('invalid_arguments', esc_html__('Unknown term operation.', 'llamly'), 400);
	}

	/** wp_delete_term plus the checks that make the delete reversible. */
	private function remove(int $id, string $taxonomy): void
	{
		global $wpdb;
		$blocker = self::deleteBlocker($id);
		if ($blocker !== null) {
			throw new MutationFailure(esc_html($blocker['code']), esc_html($blocker['message']), (int) $blocker['status']);
		}
		$state = $this->capture($id, true, true);
		if ($state === null) {
			throw new MutationFailure('object_not_found', esc_html__('The term was not found.', 'llamly'), 404);
		}
		$default = self::defaultTerm($taxonomy);
		$objectIds = array_map(static fn(array $pair): int => $pair[0], $state['objects']);
		$before = $default !== null ? $this->relatedTo($default[1], $objectIds) : [];
		$deleted = wp_delete_term($id, $taxonomy);
		if (is_wp_error($deleted)) {
			throw new MutationFailure(esc_html($deleted->get_error_code() ?: 'mutation_failed'), esc_html($deleted->get_error_message()), 409);
		}
		if ($deleted !== true) {
			throw new MutationFailure('mutation_failed', esc_html__('WordPress did not delete the term.', 'llamly'), 409);
		}
		$assigned = $default !== null ? array_values(array_diff($this->relatedTo($default[1], $objectIds), $before)) : [];
		sort($assigned);
		if ($assigned !== array_map('intval', $state['default_assigned'])) {
			throw new MutationFailure('restore_mismatch', esc_html__('The default term was assigned differently than expected, so the delete was cancelled.', 'llamly'), 409);
		}
		if ($wpdb->last_error !== '') {
			throw new MutationFailure('journal_unavailable', esc_html__('The term could not be deleted.', 'llamly'), 503);
		}
	}

	/** @param list<int> $objectIds @return list<int> object IDs (of $objectIds) that carry term_taxonomy_id $tt */
	private function relatedTo(int $tt, array $objectIds): array
	{
		global $wpdb;
		$found = [];
		foreach (array_chunk($objectIds, 500) as $chunk) {
			$rows = $wpdb->get_col($wpdb->prepare(
				"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')',
				array_merge([$tt], $chunk)
			));
			foreach ((array) $rows as $objectId) {
				$found[] = (int) $objectId;
			}
		}
		sort($found);
		return $found;
	}

	private function createsLoop(int $termId, int $parent, string $taxonomy): bool
	{
		global $wpdb;
		$seen = 0;
		while ($parent > 0 && $seen++ < 1000) {
			if ($parent === $termId) {
				return true;
			}
			$parent = (int) $wpdb->get_var($wpdb->prepare("SELECT parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $parent, $taxonomy));
		}
		return false;
	}

	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error
	{
		global $wpdb;
		if ($target === null) {
			if ($current === null) {
				return $id;
			}
			$taxonomy = (string) $current['taxonomy'];
			if (!self::allowedTaxonomy($taxonomy)) {
				throw new MutationFailure('taxonomy_not_allowed', esc_html__('This taxonomy cannot be changed through Llamly.', 'llamly'), 400);
			}
			if ($this->undoesCreate($operationId)) {
				// A term created by the agent may only vanish while nothing hangs from it.
				$full = $this->capture($id, true, true);
				if ($full === null || (int) $full['objects_total'] > 0 || (int) $full['children_total'] > 0) {
					throw new MutationFailure('undo_conflict', esc_html__('Items or child terms now use this term, so removing it would detach them.', 'llamly'), 409);
				}
			}
			$this->remove($id, $taxonomy);
			return $id;
		}
		$taxonomy = (string) $target['taxonomy'];
		if (!self::allowedTaxonomy($taxonomy)) {
			throw new MutationFailure('undo_conflict', esc_html__('The taxonomy of this term is no longer available.', 'llamly'), 409);
		}
		$parent = (int) $target['parent'];
		if ($parent > 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $parent, $taxonomy)) < 1) {
			throw new MutationFailure('undo_conflict', esc_html__('The parent term of this term no longer exists.', 'llamly'), 409);
		}
		$otherSlug = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s AND t.term_id <> %d",
			(string) $target['slug'],
			$taxonomy,
			$id
		));
		if ($otherSlug > 0) {
			throw new MutationFailure('undo_conflict', esc_html__('Another term now uses this slug.', 'llamly'), 409);
		}
		if ($current === null) {
			$this->recreate($id, $target, $taxonomy);
		} else {
			$this->rewind($id, $target, $taxonomy);
		}
		return $id;
	}

	/** True when the running inverse operation undoes a wp_create_term (not the redo of a delete). */
	private function undoesCreate(string $operationId): bool
	{
		$inverse = Engine::operation($operationId);
		if (!$inverse || empty($inverse['undo_of'])) {
			return false;
		}
		$original = Engine::operation((string) $inverse['undo_of']);
		return $original !== null && (string) $original['tool'] === 'wp_create_term';
	}

	/** @param array<string, mixed> $target */
	private function rewind(int $id, array $target, string $taxonomy): void
	{
		global $wpdb;
		// Someone may have moved the old parent below this term since; restoring it would write a cycle.
		if ($this->createsLoop($id, (int) $target['parent'], $taxonomy)) {
			throw new MutationFailure('undo_conflict', esc_html__('The previous parent is now a descendant of this term, so restoring it would create a loop.', 'llamly'), 409);
		}
		$updated = wp_update_term($id, $taxonomy, wp_slash([
			'name' => (string) $target['name'],
			'slug' => (string) $target['slug'],
			'description' => (string) $target['description'],
			'parent' => (int) $target['parent'],
		]));
		if (is_wp_error($updated)) {
			throw new MutationFailure(esc_html($updated->get_error_code() ?: 'mutation_failed'), esc_html($updated->get_error_message()), 409);
		}
		// The API sanitizes and may rewrite values; the journaled ones win.
		$termRow = ['name' => (string) $target['name'], 'slug' => (string) $target['slug'], 'term_group' => (int) $target['term_group']];
		$ttRow = ['description' => (string) $target['description'], 'parent' => (int) $target['parent']];
		if ($wpdb->update($wpdb->terms, $termRow, ['term_id' => $id], ['%s', '%s', '%d'], ['%d']) === false
			|| $wpdb->update($wpdb->term_taxonomy, $ttRow, ['term_taxonomy_id' => (int) $target['term_taxonomy_id']], ['%s', '%d'], ['%d']) === false
		) {
			throw new MutationFailure('journal_unavailable', esc_html__('The term rows could not be restored.', 'llamly'), 503);
		}
		RawRows::restoreMeta($wpdb->termmeta, 'term_id', $id, (array) $target['meta'], null, self::META_EXCLUDE, 'term_meta');
		clean_term_cache($id, $taxonomy);
	}

	/** @param array<string, mixed> $target */
	private function recreate(int $id, array $target, string $taxonomy): void
	{
		global $wpdb;
		$tt = (int) $target['term_taxonomy_id'];
		RawRows::reinsert($wpdb->terms, 'term_id', [
			'term_id' => $id,
			'name' => (string) $target['name'],
			'slug' => (string) $target['slug'],
			'term_group' => (int) $target['term_group'],
		]);
		RawRows::reinsert($wpdb->term_taxonomy, 'term_taxonomy_id', [
			'term_taxonomy_id' => $tt,
			'term_id' => $id,
			'taxonomy' => $taxonomy,
			'description' => (string) $target['description'],
			'parent' => (int) $target['parent'],
			'count' => 0,
		]);
		RawRows::restoreMeta($wpdb->termmeta, 'term_id', $id, (array) $target['meta'], null, self::META_EXCLUDE, 'term_meta');

		$orphans = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt));
		if ($orphans > 0) {
			throw new MutationFailure('undo_conflict', esc_html__('Leftover relationships already use this term identifier.', 'llamly'), 409);
		}
		$objects = (array) ($target['objects'] ?? []);
		if (!empty($target['objects_truncated']) || (int) ($target['objects_total'] ?? 0) !== count($objects)) {
			throw new MutationFailure('undo_conflict', esc_html__('The saved state of this term is incomplete.', 'llamly'), 409);
		}
		foreach ($objects as $pair) {
			$inserted = $wpdb->insert($wpdb->term_relationships, ['object_id' => (int) $pair[0], 'term_taxonomy_id' => $tt, 'term_order' => (int) $pair[1]], ['%d', '%d', '%d']);
			if ($inserted !== 1 || $wpdb->last_error !== '') {
				throw new MutationFailure('journal_unavailable', esc_html__('The term relationships could not be restored.', 'llamly'), 503);
			}
		}
		// WordPress moved the children to this term's parent; hand them back.
		foreach ((array) ($target['children'] ?? []) as $childId) {
			$row = $wpdb->get_row($wpdb->prepare("SELECT term_taxonomy_id, parent FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", (int) $childId, $taxonomy), ARRAY_A);
			if (!is_array($row)) {
				continue;
			}
			if ((int) $row['parent'] !== (int) $target['parent']) {
				throw new MutationFailure('undo_conflict', esc_html__('A child term was moved after this term was deleted.', 'llamly'), 409);
			}
			if ($wpdb->update($wpdb->term_taxonomy, ['parent' => $id], ['term_taxonomy_id' => (int) $row['term_taxonomy_id']], ['%d'], ['%d']) === false) {
				throw new MutationFailure('journal_unavailable', esc_html__('A child term could not be restored.', 'llamly'), 503);
			}
		}
		$touched = [$tt];
		$defaultTt = 0;
		$assigned = array_map('intval', (array) ($target['default_assigned'] ?? []));
		if ($assigned && (int) ($target['default_term_id'] ?? 0) > 0) {
			$default = self::defaultTerm($taxonomy);
			if ($default === null || $default[0] !== (int) $target['default_term_id']) {
				throw new MutationFailure('undo_conflict', esc_html__('The default term changed after the delete, so the items it was assigned to cannot be put back safely.', 'llamly'), 409);
			}
			{
				$defaultTt = $default[1];
				foreach (array_chunk($assigned, 500) as $chunk) {
					$wpdb->query($wpdb->prepare(
						"DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ')',
						array_merge([$defaultTt], $chunk)
					));
					if ($wpdb->last_error !== '') {
						throw new MutationFailure('journal_unavailable', esc_html__('The default term assignment could not be undone.', 'llamly'), 503);
					}
				}
				$touched[] = $defaultTt;
			}
		}
		wp_update_term_count_now($touched, $taxonomy);
		clean_term_cache(array_merge([$id], array_map('intval', (array) ($target['children'] ?? []))), $taxonomy);
		$object = get_taxonomy($taxonomy);
		if ($object instanceof \WP_Taxonomy) {
			$objectIds = array_merge($assigned, array_map(static fn($pair): int => (int) $pair[0], $objects));
			foreach ((array) $object->object_type as $objectType) {
				clean_object_term_cache($objectIds, (string) $objectType);
			}
		}
	}

	public function matchesIntent(?array $before, array $current, array $intent): bool
	{
		$op = (string) ($intent['op'] ?? '');
		$taxonomy = (string) ($current['taxonomy'] ?? '');
		$fields = is_array($intent['fields'] ?? null) ? $intent['fields'] : [];
		if ($taxonomy !== (string) ($intent['taxonomy'] ?? $taxonomy)) {
			return false;
		}
		if (isset($fields['name']) && $current['name'] !== (string) sanitize_term_field('name', (string) $fields['name'], 0, $taxonomy, 'db')) {
			return false;
		}
		if (isset($fields['description']) && $current['description'] !== (string) sanitize_term_field('description', (string) $fields['description'], 0, $taxonomy, 'db')) {
			return false;
		}
		if (isset($fields['slug']) && (string) $fields['slug'] !== '' && $current['slug'] !== sanitize_title((string) $fields['slug'])) {
			return false;
		}
		if (isset($fields['parent']) && (int) $current['parent'] !== (int) $fields['parent']) {
			return false;
		}
		if ($op === 'update' && $before !== null) {
			return $before['term_taxonomy_id'] === $current['term_taxonomy_id'] && $before['meta'] === $current['meta'] && $before['term_group'] === $current['term_group'];
		}
		return true;
	}

	public function impact(?array $before, ?array $after, array $intent): array
	{
		$op = (string) ($intent['op'] ?? '');
		$state = $before ?? $after ?? [];
		$taxonomy = (string) ($state['taxonomy'] ?? $intent['taxonomy'] ?? '');
		$object = $taxonomy !== '' ? get_taxonomy($taxonomy) : null;
		$publicTaxonomy = $object instanceof \WP_Taxonomy && !empty($object->public);
		$used = static function (?array $s): bool {
			return $s !== null && ((int) ($s['count'] ?? 0) > 0 || (int) ($s['objects_total'] ?? 0) > 0);
		};
		if ($op === 'undo' || $op === 'redo') {
			$restoring = $before === null;
			$removing = $after === null;
			$inUse = $used($before) || $used($after);
			return [
				'destructive' => $removing,
				'public' => ($restoring || $removing) ? $inUse : ($publicTaxonomy && $inUse),
				'sensitive' => false,
				'external_effects' => [],
			];
		}
		if ($op === 'delete') {
			return ['destructive' => true, 'public' => $used($before), 'sensitive' => false, 'external_effects' => []];
		}
		if ($op === 'update') {
			return ['destructive' => false, 'public' => $publicTaxonomy && $used($before), 'sensitive' => false, 'external_effects' => []];
		}
		return ['destructive' => false, 'public' => false, 'sensitive' => false, 'external_effects' => []];
	}

	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool
	{
		$state = $before ?? $after;
		$taxonomy = $state !== null && !empty($state['taxonomy']) ? (string) $state['taxonomy'] : ($id > 0 ? self::taxonomyOf($id) : '');
		$object = $taxonomy !== '' ? get_taxonomy($taxonomy) : null;
		if (!$object instanceof \WP_Taxonomy || !self::allowedTaxonomy($taxonomy)) {
			return $taxonomy === '' && user_can($userId, 'manage_categories');
		}
		if ($id > 0 && self::taxonomyOf($id) !== '') {
			// Removing needs delete_term; changing in place needs edit_term. Approval of a delete has no states yet: ask for both.
			return user_can($userId, 'edit_term', $id) && ($after !== null || user_can($userId, 'delete_term', $id));
		}
		return user_can($userId, $object->cap->edit_terms);
	}

	public function label(?array $state): string
	{
		$name = trim((string) ($state['name'] ?? ''));
		return $name !== '' ? $name : __('(unnamed term)', 'llamly');
	}

	public function tables(): array
	{
		global $wpdb;
		return [$wpdb->terms, $wpdb->term_taxonomy, $wpdb->termmeta, $wpdb->term_relationships];
	}

	public function findMarked(string $operationId): array
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY term_id ASC LIMIT 2",
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

	/** Called by the engine after the final cache pass for this term. */
	public function forgetRequestState(int $id): void
	{
		unset(self::$taxonomies[$id]);
	}

	public function flushCaches(int $id): void
	{
		global $wpdb;
		$taxonomy = (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1", $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		$children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent = %d LIMIT 500", $id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the invalidation must read the database, never the cache.
		// The taxonomy is remembered from the pre-effect read, so a removed term still cleans the right taxonomy.
		$taxonomy = $taxonomy !== '' ? $taxonomy : (self::$taxonomies[$id] ?? '');
		if ($taxonomy !== '') {
			clean_term_cache(array_merge([$id], $children), $taxonomy);
		} else {
			// Unknown taxonomy: clean_term_cache() would need one, so invalidate the cached term lists by marker.
			wp_cache_set_terms_last_changed();
			foreach (array_merge([$id], $children) as $termId) {
				wp_cache_delete($termId, 'terms');
			}
		}
		wp_cache_delete($id, 'term_meta');
		wp_cache_delete($id, 'terms');
	}

	public function result(int $id, ?array $before, ?array $after, array $intent): array
	{
		$state = $after ?? $before;
		$result = ['term_id' => $id, 'taxonomy' => (string) ($state['taxonomy'] ?? ($intent['taxonomy'] ?? '')), 'name' => (string) ($state['name'] ?? '')];
		if (($intent['op'] ?? '') === 'update') {
			$result['changed_fields'] = array_values(array_keys((array) ($intent['fields'] ?? [])));
		}
		if (($intent['op'] ?? '') === 'delete') {
			$result['deleted'] = true;
			$result['detached_items'] = (int) ($before['objects_total'] ?? 0);
		}
		return $result;
	}
}
