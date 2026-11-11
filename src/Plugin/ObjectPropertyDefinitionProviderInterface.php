<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

interface ObjectPropertyDefinitionProviderInterface
{
	/** @return iterable<mixed> */
	public function getObjectPropertyDefinitions(): iterable;
}
