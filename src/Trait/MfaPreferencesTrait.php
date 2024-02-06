<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Entity\ObjectProperty;

trait MfaPreferencesTrait
{
	public const MFA_KEY = 'mfa';

	public function getMultifactorPreferences(): array|null
	{
		if (false == $this->getProperties()->containsKey(self::MFA_KEY)) {
			return []; // User does not have MFA preferences
		}

		/** @var ObjectProperty<array>|null $property */
		$property = $this->getProperties()->get(self::MFA_KEY);

		return $property?->getValue();
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		$this->setProperty(self::MFA_KEY, $preferences);
	}
}
