<?php

namespace iikiti\CMS\Trait;

trait MfaConfigurableTrait
{
	use ConfigurableTrait;

	public const MFA_KEY = 'mfa';

	public function getMultifactorPreferences(): array|null
	{
		$mfaConfig = $this->getConfiguration()->get(self::MFA_KEY);
		if (!is_array($mfaConfig)) {
			return []; // User does not have MFA configuration
		}

		return $mfaConfig;
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		$config = $this->getConfiguration();
		$config->set(self::MFA_KEY, $preferences);
		$this->setConfiguration($config);
	}
}
