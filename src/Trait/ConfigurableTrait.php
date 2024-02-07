<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\Configuration;

trait ConfigurableTrait
{
	use PropertiedTrait;

	private ?Configuration $configuration = null;

	public const CONFIGURATION_KEY = 'configuration';

	public function getConfiguration(): Configuration
	{
		if (null === $this->configuration) {
			$this->configuration = new Configuration(
				(array) ($this->getProperties()->get(self::CONFIGURATION_KEY) ?? [])
			);
		}

		return $this->configuration;
	}

	public function setConfiguration(Configuration $configuration): void
	{
	}
}
