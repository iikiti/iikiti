<?php

namespace iikiti\CMS\Authentication;

/**
 * Read-only view over a user's resolved multi-factor preferences.
 *
 * Preferences from the application, site and user levels are merged before
 * being wrapped, so this object always represents the effective settings.
 */
final class MfaUserConfiguration
{
	public const METHOD_EMAIL = 'email';
	public const METHOD_TOTP = 'totp';
	public const METHOD_BACKUP_CODE = 'backup_code';

	public const KEY_ENABLED = 'enabled';
	public const KEY_METHODS = 'methods';
	public const KEY_TOTP_SECRET = 'totp_secret';
	public const KEY_BACKUP_CODES = 'backup_codes';

	/**
	 * @param array<string,mixed> $preferences
	 */
	public function __construct(private readonly array $preferences)
	{
	}

	public function isEnabled(): bool
	{
		return true === ($this->preferences[self::KEY_ENABLED] ?? false);
	}

	/**
	 * @return array<int,string>
	 */
	public function getEnabledMethods(): array
	{
		$methods = $this->preferences[self::KEY_METHODS] ?? [];
		if (!is_array($methods)) {
			return [];
		}

		$enabled = [];
		foreach ($methods as $method) {
			if (is_string($method) && '' !== $method) {
				$enabled[] = $method;
			}
		}

		return array_values(array_unique($enabled));
	}

	public function hasMethod(string $method): bool
	{
		return in_array($method, $this->getEnabledMethods(), true);
	}

	public function getTotpSecret(): ?string
	{
		$secret = $this->preferences[self::KEY_TOTP_SECRET] ?? null;

		return is_string($secret) && '' !== $secret ? $secret : null;
	}

	/**
	 * @return array<int,string>
	 */
	public function getBackupCodeHashes(): array
	{
		$codes = $this->preferences[self::KEY_BACKUP_CODES] ?? [];
		if (!is_array($codes)) {
			return [];
		}

		return array_values(array_filter(
			$codes,
			static fn (mixed $code): bool => is_string($code) && '' !== $code
		));
	}
}
