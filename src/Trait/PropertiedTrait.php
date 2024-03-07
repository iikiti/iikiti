<?php

namespace iikiti\CMS\Trait;

use Doctrine\Common\Collections\Collection;
use iikiti\CMS\Entity\ObjectProperty;

/**
 * Trait for entities that need to get and set a collection of properties.
 */
trait PropertiedTrait
{
	/** @return Collection<string,ObjectProperty> */
	abstract public function getProperties(): Collection;

	/** @param Collection<string,ObjectProperty> $properties */
	abstract public function setProperties(Collection $properties): void;

	abstract public function setProperty(string $name, mixed $value): void;
}
