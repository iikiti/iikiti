<?php

namespace iikiti\CMS\Doctrine\Collections;

use Doctrine\Common\Collections\ArrayCollection as DoctrineArrayCollection;

/**
 * Extends DoctrineArrayCollection to add a unique() method.
 *
 * @psalm-template TKey of array-key
 * @psalm-template T
 *
 * @extends DoctrineArrayCollection<TKey,T>
 */
class ArrayCollection extends DoctrineArrayCollection
{
	public function unique(int $flags = SORT_STRING): static
	{
		return $this->createFrom(array_unique($this->toArray(), $flags));
	}
}
