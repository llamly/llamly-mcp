<?php

declare(strict_types=1);

namespace Llamly\Free\Core;

/** Output redaction for logs, health data and error text. */
final class Redactor
{
	public const SENSITIVE_KEY = '/secret|token|api_?key|_key$|password|passwd|nonce|^auth_|salt|^llamly_|^mailserver_|user_roles$|session|application_passwords/i';

	public static function isSensitiveKey(string $key): bool
	{
		return (bool) preg_match(self::SENSITIVE_KEY, $key);
	}

	/** Make absolute server paths relative and scrub credentials from free text. */
	public static function text(string $value): string
	{
		// Only searched for in the text and replaced by ~, never used to build a path.
		$paths = array_filter(array_unique([
			defined('ABSPATH') ? rtrim(wp_normalize_path(ABSPATH), '/') : '',
			defined('WP_CONTENT_DIR') ? rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') : '',
			isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(wp_normalize_path(sanitize_text_field(wp_unslash((string) $_SERVER['DOCUMENT_ROOT']))), '/') : '',
		]));
		usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
		foreach ($paths as $path) {
			if ($path !== '' && $path !== '/') {
				$value = str_replace([$path, str_replace('/', '\\', $path)], '~', $value);
			}
		}
		$value = (string) preg_replace('#/home/[^/\s]+#', '/home/~', $value);
		$patterns = [
			'/\b(Authorization|Cookie|Set-Cookie)\s*:\s*[^\r\n]+/i' => '$1: [redacted]',
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer [redacted]',
			'/\b(?:llm|llat|llrt|llp|mcp)_[A-Za-z0-9]{16,}/' => '[redacted]',
			'/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => '[redacted-jwt]',
			'#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1[redacted]@',
			'/([?&](?:key|token|secret|sig|signature|password|api_key|access_token|code)=)[^&\s"\']+/i' => '$1[redacted]',
			'/((?:password|passwd|pwd|secret|api[_-]?key|token|DB_[A-Z_]+|[A-Z_]+_KEY|[A-Z_]+_SALT)["\']?\s*(?:=>|=|:)\s*["\']?)[^"\'\s,;)]+/i' => '$1[redacted]',
			'/\$(?:P|H|wp\$2y|2y|2a|2b|argon2id?)\$[A-Za-z0-9.\/$=+,-]{8,}/' => '[redacted-hash]',
			'/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/' => '$1***@$2',
		];
		foreach ($patterns as $pattern => $replacement) {
			$value = (string) preg_replace($pattern, $replacement, $value);
		}
		return $value;
	}

	/** @param array<string, mixed> $data @return array<string, mixed> */
	public static function deep(array $data): array
	{
		$out = [];
		foreach ($data as $key => $value) {
			if (is_string($key) && self::isSensitiveKey($key)) {
				$out[$key] = '[redacted]';
			} elseif (is_array($value)) {
				$out[$key] = self::deep($value);
			} elseif (is_string($value)) {
				$out[$key] = self::text($value);
			} else {
				$out[$key] = $value;
			}
		}
		return $out;
	}
}
