<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\Configuration;

trait ConfigurableTrait
{
	use PropertiedTrait;

	public const CONFIGURATION_KEY = 'configuration';

	public function getConfiguration(): Configuration
	{
		return new Configuration(
			(array) ($this->getProperties()->get(self::CONFIGURATION_KEY) ?? [])
		);
	}
}
