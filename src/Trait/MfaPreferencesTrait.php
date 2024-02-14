<?php

namespace iikiti\CMS\Trait;

trait MfaPreferencesTrait
{
	use PreferentialTrait;

	public const MFA_KEY = 'mfa';

	public function getMultifactorPreferences(): array|null
	{
		$mfaConfig = $this->getPreferences()->get(self::MFA_KEY);
		if (!is_array($mfaConfig)) {
			return []; // User does not have MFA preferences
		}

		return $mfaConfig;
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		$prefs = $this->getPreferences();
		$prefs->set(self::MFA_KEY, $preferences);
		$this->setPreferences($prefs);
	}
}
