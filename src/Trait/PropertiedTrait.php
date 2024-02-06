<?php

namespace iikiti\CMS\Trait;

use Doctrine\Common\Collections\Collection;
use iikiti\CMS\Entity\ObjectProperty;

trait PropertiedTrait
{
	/** @return Collection<string,ObjectProperty> */
	abstract public function getProperties(): Collection;

	/** @param Collection<string,ObjectProperty> $properties */
	abstract public function setProperties(Collection $properties): void;

	abstract public function setProperty(string $name, mixed $value): void;
}
