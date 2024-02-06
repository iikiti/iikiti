<?php

namespace iikiti\CMS\Interfaces;

use iikiti\CMS\Service\Preferences;

interface PreferentialInterface
{
	public function getPreferences(): Preferences;

	public function setPreferences(Preferences $preferences): void;
}
