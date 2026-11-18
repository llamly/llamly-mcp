<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * One reversible WordPress domain (posts, terms, comments, ...). The Engine owns transactions, locks, idempotency,
 * the journal, permits, audit and recovery; an adapter only reads, applies and restores the state of one object.
 *
 * Contract:
 * - capture() reads the persisted state straight from the database (never the object cache) and returns null when
 *   the object does not exist. The array is the journal snapshot: it must be enough for restore() to rebuild the object
 *   with the same ID, and must never contain secrets (password hashes, session tokens, application passwords).
 * - fingerprint() hashes only what identifies a conflicting change; absent objects hash to "<prefix>_" + 64 zeros.
 * - apply() and restore() run inside the Engine's open transaction and must not COMMIT. They throw MutationFailure
 *   (or return WP_Error) on failure; the Engine rolls back. restore(id, null) removes the object.
 * - Effects that cannot ride the database transaction (files) must be staged so a rollback leaves no loss.
 */
interface Adapter
{
	/** Journal object_type, e.g. "post". */
	public function type(): string;

	/** Hash prefix, e.g. "p2". */
	public function prefix(): string;

	/** @return array<string, mixed>|null */
	public function capture(int $id, bool $forUpdate = false): ?array;

	/** @param array<string, mixed>|null $state */
	public function fingerprint(?array $state): ?string;

	/**
	 * Apply a forward intent. $id is 0 for a create. Returns the object ID.
	 *
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $auth
	 */
	public function apply(int $id, array $intent, array $auth, string $operationId): int|\WP_Error;

	/**
	 * Bring the object to exactly $target (null removes it). Returns the object ID (0 when removed).
	 *
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $current
	 */
	public function restore(int $id, ?array $target, ?array $current, string $operationId): int|\WP_Error;

	/**
	 * Whether a state observed after an interrupted forward write is exactly what the intent produces.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $intent
	 */
	public function matchesIntent(?array $before, array $current, array $intent): bool;

	/**
	 * Impact of a change for the permit policy.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after Target state for inverses, null for forward intents (use $intent).
	 * @param array<string, mixed> $intent
	 * @return array{destructive:bool,public:bool,sensitive:bool,external_effects:list<string>}
	 */
	public function impact(?array $before, ?array $after, array $intent): array;

	/** Capability check for undo/redo by a WordPress user (in addition to the credential scope). */
	public function canInverse(int $userId, int $id, ?array $before, ?array $after): bool;

	/** @param array<string, mixed>|null $state */
	public function label(?array $state): string;

	/** @return list<string> Tables that must be InnoDB for this domain. */
	public function tables(): array;

	/** @return list<int> Objects carrying the pending-create marker of $operationId (create reconciliation). */
	public function findMarked(string $operationId): array;

	public function clearMarker(int $id, string $operationId): void;

	/**
	 * Drop every cached copy of the object (and of what its write touched) again. The Engine calls it after the
	 * COMMIT, after every ROLLBACK or early return and once more at shutdown, because a reader that fetched the old
	 * database value before the COMMIT can put it back into a persistent object cache after the effect's own
	 * invalidation. Looks objects up in the database, never in the cache, writes nothing, and must tolerate an object that no longer exists.
	 * The only cache read allowed is to learn alias keys of the cached copy itself (e.g. a user's login/e-mail lookup keys).
	 */
	public function flushCaches(int $id): void;

	/**
	 * Tool-specific fields merged into the stored result.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>
	 */
	public function result(int $id, ?array $before, ?array $after, array $intent): array;
}
