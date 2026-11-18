<?php

declare(strict_types=1);

namespace Llamly\Free\Freemius;

/**
 * Reads src/Free/Freemius/freemius-settings.php. Three states: development (id and public_key empty), invalid (anything
 * else that is not a complete, well-formed setting, or a secret key) and ready. Only "ready" loads the Freemius SDK.
 * Unlike Llamly Pro, nothing in Llamly depends on the state: every feature works the same in all three.
 */
final class Config
{
	public const DEVELOPMENT = 'development';
	public const INVALID = 'invalid';
	public const READY = 'ready';

	/** The Llamly admin page; Freemius shows its opt-in screen there and nowhere else. */
	public const MENU_SLUG = 'llamly';

	/** @var array<string,mixed> */
	private array $values;
	private string $state;

	/** @param array<string,mixed> $values */
	private function __construct(array $values)
	{
		$this->values = $values;
		$this->state = self::decide($values);
	}

	public static function load(): self
	{
		try {
			$values = include __DIR__ . '/freemius-settings.php';
		} catch (\Throwable $error) {
			$values = null; // A broken settings file must not take the site down: invalid, no SDK.
		}
		return new self(is_array($values) ? $values : ['id' => 'invalid']);
	}

	/** @param array<string,mixed> $values */
	public static function fromArray(array $values): self
	{
		return new self($values);
	}

	/** @param array<string,mixed> $v */
	private static function decide(array $v): string
	{
		foreach (array_keys($v) as $key) {
			if (stripos((string) $key, 'secret') !== false) {
				return self::INVALID;
			}
		}
		$id = (string) ($v['id'] ?? '');
		$key = (string) ($v['public_key'] ?? '');
		if ($id === '' && $key === '') {
			return self::DEVELOPMENT;
		}
		$url = $v['upgrade_url'] ?? '';
		$addon = $v['pro_addon_id'] ?? '';
		$valid = preg_match('/^[1-9]\d{0,11}$/', $id)
			&& is_string($addon) && ($addon === '' || preg_match('/^[1-9]\d{0,11}$/', $addon))
			&& preg_match('/^pk_[A-Za-z0-9]{8,64}$/', $key)
			&& ($v['slug'] ?? '') === 'llamly'
			&& is_string($url) && ($url === '' || self::isHttpsUrl($url));
		return $valid ? self::READY : self::INVALID;
	}

	private static function isHttpsUrl(string $url): bool
	{
		$parts = wp_parse_url($url);
		return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== ''
			&& !isset($parts['user']) && !isset($parts['pass']) && esc_url_raw($url, ['https']) === $url;
	}

	/** The product id when the settings are ready, else ''. */
	public function sdkArgsId(): string
	{
		return $this->state === self::READY ? (string) $this->values['id'] : '';
	}

	public function state(): string
	{
		return $this->state;
	}

	/** The Llamly Pro destination, or '' (no link yet, or settings not ready). */
	public function upgradeUrl(): string
	{
		return $this->state === self::READY ? (string) ($this->values['upgrade_url'] ?? '') : '';
	}

	/** Freemius id of the Llamly Pro add-on (Llamly > Llamly Pro reads its plans), or '' (none, or settings not ready). */
	public function proAddonId(): string
	{
		return $this->state === self::READY ? (string) ($this->values['pro_addon_id'] ?? '') : '';
	}

	/**
	 * Arguments of fs_dynamic_init(): the free WordPress.org plugin (is_org_compliant), no paid plan of its own; Llamly
	 * Pro is a paid add-on (has_addons). No pricing, add-ons, support or contact pages of the SDK: Llamly Pro has its
	 * own page in Llamly (Admin\ProPage, LLP-462), because the SDK add-ons page asks Freemius for data as soon as it
	 * opens. The Account page only appears after an opt-in, so the administrator can see and change what is shared.
	 *
	 * @return array<string,mixed>
	 */
	public function sdkArgs(): array
	{
		return [
			'id' => (string) $this->values['id'],
			'slug' => 'llamly',
			'type' => 'plugin',
			'public_key' => (string) $this->values['public_key'],
			'is_premium' => false,
			'has_addons' => true,
			'has_paid_plans' => false,
			'is_org_compliant' => true,
			'is_live' => true,
			'menu' => [
				'slug' => self::MENU_SLUG,
				// LLP-458: while the opt-in is unanswered, the SDK takes over only the opt-in URL (admin.php?page=llamly).
				// Without this it empties every Llamly submenu, and add-on pages (Llamly > Llamly Pro) answer 403.
				'override_exact' => true,
				'account' => true,
				'support' => false,
				'contact' => false,
				'pricing' => false,
				'addons' => false,
			],
		];
	}
}
