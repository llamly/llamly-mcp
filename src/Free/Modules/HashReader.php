<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

use Llamly\Free\History\Engine;

/**
 * Read-only revision hashes for a module's read handlers (third argument), so a read tool can return the value a later
 * write needs as expected_hash, including for core items (for example the "p2_" hash of a block page). It reads the
 * module's own types and the core types, never another module's, and only items the connected user may change: a hash
 * is only useful to write, so the check is the adapter's own undo/redo capability check.
 */
final class HashReader
{
	/** @internal Created by ModuleRegistry for each read handler call. */
	public function __construct(private string $moduleId, private int $userId)
	{
	}

	/** Revision hash of an item, the value expected_hash must carry. Null for an unknown type, a missing item or an item the user cannot change; the absent hash for ID 0 (a create). */
	public function hash(string $type, int $objectId): ?string
	{
		$owner = ModuleRegistry::ownerOfType($type);
		if ($owner !== null && ($owner['id'] !== $this->moduleId || !$owner['active'])) {
			return null;
		}
		$adapter = Engine::adapter($type);
		if ($adapter === null || ($owner === null && strpos(get_class($adapter), 'Llamly\\Free\\') !== 0) || $this->userId < 1) {
			return null;
		}
		if ($objectId < 1) {
			return $adapter->fingerprint(null);
		}
		// A missing item answers like one the user cannot change, so the reader never tells whether an ID exists.
		$state = $adapter->capture($objectId);
		if ($state === null || !$adapter->canInverse($this->userId, $objectId, $state, $state)) {
			return null;
		}
		return $adapter->fingerprint($state);
	}
}
