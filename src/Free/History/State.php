<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/** Canonical hashing for adapter fingerprints. */
final class State
{
	/** "<prefix>_" + sha256 of the canonical JSON of $data; null data hashes to the ABSENT value. */
	public static function hash(string $prefix, ?array $data): ?string
	{
		if ($data === null) {
			return $prefix . '_' . Engine::ABSENT;
		}
		$encoded = wp_json_encode(self::canonical($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		return is_string($encoded) ? $prefix . '_' . hash('sha256', $encoded) : null;
	}

	/** Sorts associative arrays by key recursively; lists keep their order (callers sort sets themselves). */
	public static function canonical(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		$isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
		$out = [];
		foreach ($value as $key => $item) {
			if ($key === '_volatile') {
				continue;
			}
			$out[$key] = self::canonical($item);
		}
		if (!$isList) {
			ksort($out, SORT_STRING);
		}
		return $out;
	}
}
