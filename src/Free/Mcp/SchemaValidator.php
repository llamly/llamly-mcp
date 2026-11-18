<?php

declare(strict_types=1);

namespace Llamly\Free\Mcp;

final class SchemaValidator
{
	public static function validate(mixed $value, array $schema, string $path = 'arguments'): bool|\WP_Error
	{
		if (array_key_exists('const', $schema) && $value !== $schema['const']) {
			return self::invalid($path, 'is not the allowed value');
		}
		foreach (['oneOf' => 1, 'anyOf' => 0] as $keyword => $exact) {
			if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
				continue;
			}
			$matches = 0;
			foreach ($schema[$keyword] as $option) {
				if (is_array($option) && self::validate($value, $option + array_diff_key($schema, ['oneOf' => 1, 'anyOf' => 1]), $path) === true) {
					$matches++;
				}
			}
			if ($exact ? $matches !== 1 : $matches < 1) {
				return self::invalid($path, $exact ? 'must match exactly one allowed form' : 'does not match any allowed form');
			}
			return true;
		}
		$type = $schema['type'] ?? null;
		if (is_array($type)) {
			foreach ($type as $candidate) {
				if (self::validate($value, ['type' => $candidate] + $schema, $path) === true) {
					return true;
				}
			}
			return self::invalid($path, 'has an invalid type');
		}
		if ($type === 'object') {
			$object = is_object($value) ? get_object_vars($value) : $value;
			if (!is_array($object) || (!is_object($value) && $object !== [] && self::isList($object))) {
				return self::invalid($path, 'must be an object');
			}
			$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
			foreach ((array) ($schema['required'] ?? []) as $required) {
				if (!array_key_exists($required, $object)) {
					return self::invalid($path . '.' . $required, 'is required');
				}
			}
			if (($schema['additionalProperties'] ?? true) === false) {
				$extra = array_diff(array_keys($object), array_keys($properties));
				if ($extra) {
					return self::invalid($path . '.' . (string) reset($extra), 'is not allowed');
				}
			}
			if (isset($schema['maxProperties']) && count($object) > (int) $schema['maxProperties']) {
				return self::invalid($path, 'has too many properties');
			}
			$additional = is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null;
			foreach ($object as $key => $item) {
				if (!isset($properties[$key])) {
					if ($additional !== null) {
						$result = self::validate($item, $additional, $path . '.' . $key);
						if (is_wp_error($result)) {
							return $result;
						}
					}
					continue;
				}
				$result = self::validate($item, $properties[$key], $path . '.' . $key);
				if (is_wp_error($result)) {
					return $result;
				}
			}
			return true;
		}

		$validType = match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => is_int($value) || is_float($value),
			'boolean' => is_bool($value),
			'array' => is_array($value) && self::isList($value),
			'null' => $value === null,
			default => false,
		};
		if (!$validType) {
			return self::invalid($path, 'has an invalid type');
		}
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			return self::invalid($path, 'is not an allowed value');
		}
		if (is_string($value)) {
			$length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
			if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
				return self::invalid($path, 'is too short');
			}
			if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
				return self::invalid($path, 'is too long (max ' . (int) $schema['maxLength'] . ' characters)');
			}
			if (isset($schema['pattern']) && @preg_match('/' . str_replace('/', '\\/', (string) $schema['pattern']) . '/D', $value) !== 1) {
				return self::invalid($path, 'has an invalid format');
			}
		}
		if (is_int($value) || is_float($value)) {
			if (isset($schema['minimum']) && $value < $schema['minimum']) {
				return self::invalid($path, 'is below the minimum');
			}
			if (isset($schema['maximum']) && $value > $schema['maximum']) {
				return self::invalid($path, 'is above the maximum (max ' . $schema['maximum'] . ')');
			}
		}
		if (is_array($value)) {
			if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
				return self::invalid($path, 'has too few items');
			}
			if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
				return self::invalid($path, 'has too many items (max ' . (int) $schema['maxItems'] . ')');
			}
			if (!empty($schema['uniqueItems']) && count(array_unique(array_map(static fn($item): string => (string) wp_json_encode($item), $value))) !== count($value)) {
				return self::invalid($path, 'has duplicate items');
			}
		}
		if (is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
			foreach ($value as $index => $item) {
				$result = self::validate($item, $schema['items'], $path . '.' . $index);
				if (is_wp_error($result)) {
					return $result;
				}
			}
		}
		return true;
	}

	/** Tool handlers receive plain PHP arrays: nested JSON objects are converted after validation. */
	public static function toArrays(mixed $value): mixed
	{
		if (is_object($value)) {
			$value = get_object_vars($value);
		}
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = self::toArrays($item);
			}
		}
		return $value;
	}

	private static function invalid(string $path, string $reason): \WP_Error
	{
		return new \WP_Error('invalid_arguments', sprintf(/* translators: 1: argument path, 2: what is wrong with it. */ __('Argument %1$s %2$s.', 'llamly'), $path, $reason), ['status' => 400]);
	}

	private static function isList(array $value): bool
	{
		$expected = 0;
		foreach ($value as $key => $_) {
			if ($key !== $expected++) {
				return false;
			}
		}
		return true;
	}
}
