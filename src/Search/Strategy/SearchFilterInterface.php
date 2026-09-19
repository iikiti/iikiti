<?php

namespace iikiti\CMS\Search\Strategy;

use iikiti\CMS\Search\Entity\SearchFilter;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Service\SearchParams;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for hookable search filters.
 *
 * Filters modify search queries (query-time) or indexed data (index-time).
 * Implementations are auto-discovered via the `iikiti.search_filter` DI tag
 * and can also be configured via the SearchFilter entity (whose `hook` field
 * references a service by name, matching {@see getName()}).
 *
 * @see SearchFilter
 */
#[AutoconfigureTag('iikiti.search_filter')]
interface SearchFilterInterface
{
	/**
	 * Machine name of this filter.
	 */
	public function getName(): string;

	/**
	 * Human-readable label.
	 */
	public function getLabel(): string;

	/**
	 * Apply a query-time filter to the search parameters.
	 *
	 * Implementations receive the active SearchIndex, the current SearchParams
	 * (which they may mutate), and the filter value from the request.
	 */
	public function apply(SearchIndex $index, SearchParams $params, mixed $value): void;

	/**
	 * Process data at index-time.
	 *
	 * @param mixed $data The raw field data from a DbObject
	 *
	 * @return mixed The processed data ready for indexing
	 */
	public function process(SearchIndex $index, mixed $data): mixed;

	/**
	 * Whether this filter applies to the given search index.
	 */
	public function supports(SearchIndex $index): bool;
}
