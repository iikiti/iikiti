<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\Preferences;

trait PreferentialTrait
{
	use PropertiedTrait;

	public const PREFERENCES_KEY = 'preferences';

	public function getPreferences(): Preferences
	{
		return new Preferences(
			(array) ($this->getProperties()->get(self::PREFERENCES_KEY) ?? [])
		);
	}
}
