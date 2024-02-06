<?php

namespace iikiti\CMS\Interfaces;

use iikiti\CMS\Service\Configuration;

interface ConfigurableInterface
{
	public function getConfiguration(): Configuration;

	public function setConfiguration(Configuration $configuration): void;
}
