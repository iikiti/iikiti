<?php

namespace iikiti\CMS\Trait;

use iikiti\CMS\Service\Configuration;

/**
 * Configurable trait for entities that need to return a configuration.
 */
trait ConfigurableTrait
{
	use PropertiedTrait;

	private ?Configuration $configuration = null;

	public const CONFIGURATION_KEY = 'configuration';

	public function getConfiguration(): Configuration
	{
		if (null === $this->configuration) {
			$this->configuration = new Configuration(
				(array) ($this->getProperties()->get(self::CONFIGURATION_KEY)?->getValue() ?? [])
			);
		}

		return $this->configuration;
	}

	public function setConfiguration(Configuration $configuration): void
	{
		$this->getProperties()->set(self::CONFIGURATION_KEY, $configuration);
	}
}
