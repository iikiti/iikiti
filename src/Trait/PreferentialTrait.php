<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\Preferences;

trait PreferentialTrait
{
	use PropertiedTrait;

	private ?Preferences $preferences = null;
	public const PREFERENCES_KEY = 'preferences';

	public function getPreferences(): Preferences
	{
		if (null === $this->preferences) {
			$this->preferences = new Preferences(
				(array) ($this->getProperties()->get(self::PREFERENCES_KEY) ?? [])
			);
		}

		return $this->preferences;
	}

	public function setPreferences(Preferences $preferences): void
	{
	}
}
