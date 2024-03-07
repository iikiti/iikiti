<?php

namespace iikiti\CMS\Interfaces;

/**
 * Ensures repositories that can be searched implement the necessary methods.
 */
interface SearchableRepositoryInterface
{
	public function search(string $query): mixed;
}
