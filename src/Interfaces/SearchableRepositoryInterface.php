<?php

namespace iikiti\CMS\Interfaces;

use iikiti\CMS\Search\Service\SearchResult;

/**
 * Ensures repositories that can be searched implement the necessary methods.
 */
interface SearchableRepositoryInterface
{
	/**
	 * @param array<string,mixed> $options
	 */
	public function search(string $query, array $options = []): SearchResult;
}
