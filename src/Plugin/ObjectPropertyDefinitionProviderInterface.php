<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.cms.object_property_definition')]
interface ObjectPropertyDefinitionProviderInterface
{
	/** @return iterable<mixed> */
	public function getObjectPropertyDefinitions(): iterable;
}
