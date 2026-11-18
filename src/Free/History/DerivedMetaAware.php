<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * Optional capability of an Adapter: meta that a hook writes while the object is saved is derived, not corruption. The Engine uses it only for adapters that implement it; every other adapter stays strict.
 */
interface DerivedMetaAware
{
	/**
	 * Compares a forward write's result with the pre-effect state outside the intent.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $intent
	 * @return array{ok:bool,derived:list<string>,reason:?string}
	 */
	public function derivedMeta(?array $before, array $current, array $intent): array;

	/**
	 * Verifies an undo/redo result against its target. Keys the inverse had to change ($before differs from $target) must
	 * match exactly; keys inherited from the original operation are skipped; anything else a hook wrote is derived.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $observed
	 * @param array<string, mixed>|null $before State right before the inverse ran.
	 * @param list<string> $inheritedDerived
	 * @return array{ok:bool,derived:list<string>,reason:?string}
	 */
	public function derivedMetaInverse(array $target, array $observed, ?array $before, array $inheritedDerived): array;

	/**
	 * The target an undo/redo restores: the journaled one, with the derived keys left as they are now.
	 *
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $current
	 * @param list<string> $derived
	 * @return array<string, mixed>|null
	 */
	public function inverseTarget(?array $target, ?array $current, array $derived): ?array;

	/** Whether the meta kept outside the object state is as it was before the effect (one-shot). */
	public function guardedMetaIntact(int $id): bool;
}
