<?php

namespace iikiti\CMS\Trait;

/**
 * Trait for entities that need to provide multi-factor authentication
 * preferences.
 */
trait MfaPreferencesTrait
{
	use PreferentialTrait;

	public const MFA_KEY = 'mfa';

	/**
	 * @return array<string,mixed>|null
	 */
	public function getMultifactorPreferences(): ?array
	{
		$mfaConfig = $this->getPreferences()->get(self::MFA_KEY);
		if (!is_array($mfaConfig)) {
			return []; // User does not have MFA preferences
		}

		return $mfaConfig;
	}

	/**
	 * @param array<string,mixed> $preferences
	 */
	public function setMultifactorPreferences(array $preferences): void
	{
		$prefs = $this->getPreferences();
		$prefs->set(self::MFA_KEY, $preferences);
		$this->setPreferences($prefs);
	}
}
