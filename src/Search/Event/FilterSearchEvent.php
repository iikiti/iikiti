<?php

namespace iikiti\CMS\Search\Event;

use iikiti\CMS\Search\Entity\SearchFilter;
use iikiti\CMS\Search\Entity\SearchIndex;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when a search filter is being resolved, allowing plugins to modify
 * filter visibility, roles, or inject additional filter configurations.
 */
class FilterSearchEvent extends Event
{
	/** @var array<string,mixed> */
	private array $filterValues = [];

	/** @var list<SearchFilter> */
	private array $resolvedFilters = [];

	/**
	 * @param array<string,mixed> $filterValues    Filter name => user-supplied value
	 * @param list<SearchFilter>  $resolvedFilters Filters already resolved for this index
	 */
	public function __construct(
		private SearchIndex $index,
		array $filterValues,
		array $resolvedFilters,
	) {
		$this->filterValues = $filterValues;
		$this->resolvedFilters = $resolvedFilters;
	}

	public function getIndex(): SearchIndex
	{
		return $this->index;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getFilterValues(): array
	{
		return $this->filterValues;
	}

	public function setFilterValue(string $name, mixed $value): static
	{
		$this->filterValues[$name] = $value;

		return $this;
	}

	public function removeFilterValue(string $name): static
	{
		unset($this->filterValues[$name]);

		return $this;
	}

	/**
	 * @return list<SearchFilter>
	 */
	public function getResolvedFilters(): array
	{
		return $this->resolvedFilters;
	}

	/**
	 * @param list<SearchFilter> $filters
	 */
	public function setResolvedFilters(array $filters): static
	{
		$this->resolvedFilters = $filters;

		return $this;
	}

	public function addResolvedFilter(SearchFilter $filter): static
	{
		if (!in_array($filter, $this->resolvedFilters, true)) {
			$this->resolvedFilters[] = $filter;
		}

		return $this;
	}
}
