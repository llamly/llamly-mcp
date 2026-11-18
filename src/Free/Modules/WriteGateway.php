<?php

declare(strict_types=1);

namespace Llamly\Free\Modules;

use Llamly\Free\History\Engine;
use Llamly\Free\Tools\Toolkit;

/**
 * The only write path handed to a module executor. It runs Toolkit::run(), so every module write gets the core
 * envelope (idempotency, expected_hash, dry_run, confirm) and goes through Engine::write(): journal, CAS, permits,
 * safe mode, approval queue, audit, undo/redo and recovery. A module writes to its own adapter types or to the core
 * domains, never to another module's.
 */
final class WriteGateway
{
	/** @internal Created by ModuleRegistry for each executor call. */
	public function __construct(private string $moduleId, private string $tool)
	{
	}

	/**
	 * Run one journaled write.
	 *
	 * @param array<string, mixed> $args Raw tool arguments (envelope included).
	 * @param array<string, mixed> $auth The $auth the executor received.
	 * @param array<string, mixed> $intent JSON-serializable intent understood by the adapter.
	 */
	public function write(string $type, int $objectId, array $args, array $auth, array $intent, string $event): mixed
	{
		if (!$this->mayWrite($type)) {
			return Engine::error('forbidden', __('This module cannot change that kind of item.', 'llamly'), 403);
		}
		return Toolkit::run($type, $this->tool, $objectId, $args, $auth, $intent, $event);
	}

	/** Revision hash of an item, the value expected_hash must carry. Null for an unknown type. */
	public function hash(string $type, int $objectId): ?string
	{
		return $this->mayWrite($type) ? Toolkit::hashOf($type, $objectId) : null;
	}

	private function mayWrite(string $type): bool
	{
		$owner = ModuleRegistry::ownerOfType($type);
		if ($owner !== null) {
			return $owner['id'] === $this->moduleId && $owner['active'];
		}
		$adapter = Engine::adapter($type);
		return $adapter !== null && strpos(get_class($adapter), 'Llamly\\Free\\') === 0;
	}
}
