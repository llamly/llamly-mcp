<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * An adapter whose fingerprint changed form between Llamly versions. The journal keeps the hashes it stored, so the
 * Engine recognizes a stored hash of the old form by recomputing it from the stored state, and compares in the current
 * form. Internal to Llamly (not part of the module contract).
 */
interface LegacyFingerprint
{
	/** The fingerprint as an earlier Llamly computed it. @param array<string, mixed>|null $state */
	public function legacyFingerprint(?array $state): ?string;
}
