<?php

namespace iikiti\CMS\Search\Service;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Search\Entity\SearchFilter;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Event\FilterSearchEvent;
use iikiti\CMS\Search\Event\SearchEvent;
use iikiti\CMS\Search\Event\SearchEvents;
use iikiti\CMS\Search\Repository\SearchFilterRepository;
use iikiti\CMS\Search\Repository\SearchIndexRepository;
use iikiti\CMS\Search\Strategy\SearchEngineRegistry;
use iikiti\CMS\Search\Strategy\SearchFilterRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates search queries across the configured indexes.
 *
 * The SearchService resolves the active SearchIndex configuration for the
 * current site, applies visible filters, dispatches lifecycle events so
 * plugins can participate, and delegates to the appropriate
 * {@see \iikiti\CMS\Search\Strategy\SearchEngineInterface} implementation.
 */
final class SearchService
{
	public function __construct(
		private readonly SearchEngineRegistry $engineRegistry,
		private readonly SearchIndexRepository $indexRepository,
		private readonly SearchFilterRepository $filterRepository,
		private readonly SearchFilterRegistry $filterRegistry,
		private readonly EventDispatcherInterface $eventDispatcher,
		#[Autowire('%iikiti_search.enabled%')]
		private readonly bool $enabled = true,
	) {
	}

	/**
	 * Search using the configuration matching the given slug for the
	 * current site (or the global fallback).
	 *
	 * @param array<string,mixed> $options
	 */
	public function searchBySlug(string $slug, string $query, array $options = []): SearchResult
	{
		$index = $this->resolveIndex($slug);
		if (null === $index) {
			return new SearchResult();
		}

		return $this->searchWithIndex($index, $query, SearchParams::fromOptions($options));
	}

	/**
	 * Search the default front-end index for the current site.
	 *
	 * @param array<string,mixed> $options
	 */
	public function searchFrontend(string $query, array $options = []): SearchResult
	{
		return $this->searchBySlug('frontend', $query, $options);
	}

	/**
	 * Search the system admin index (system-locked, non-deletable).
	 *
	 * @param array<string,mixed> $options
	 */
	public function searchAdmin(string $query, array $options = []): SearchResult
	{
		return $this->searchBySlug('admin', $query, $options);
	}

	/**
	 * Search a specific index configuration by ID.
	 *
	 * @param array<string,mixed> $options
	 */
	public function searchByIndex(int|string $indexId, string $query, array $options = []): SearchResult
	{
		$index = $this->indexRepository->find($indexId);
		if (null === $index) {
			return new SearchResult();
		}

		return $this->searchWithIndex($index, $query, SearchParams::fromOptions($options));
	}

	private function searchWithIndex(SearchIndex $index, string $query, SearchParams $params): SearchResult
	{
		if (!$this->enabled) {
			return new SearchResult();
		}

		// Resolve active filters for this index + user context
		$filters = $this->filterRepository->findEnabledByIndex($index->getId() ?? 0);
		$resolvedConfigs = $this->filterRegistry->resolveForContext($filters, true);
		$resolvedFilterEntities = array_map(
			static fn (array $item): SearchFilter => $item['config'],
			$resolvedConfigs
		);

		// Allow plugins to modify filters and values
		$filterEvent = new FilterSearchEvent($index, $params->filters, $resolvedFilterEntities);
		$this->eventDispatcher->dispatch($filterEvent, SearchEvents::FILTER_RESOLVE);

		// Dispatch pre-search event — listeners can short-circuit
		$searchEvent = new SearchEvent($index, $query, $params);
		$this->eventDispatcher->dispatch($searchEvent, SearchEvents::SEARCH);

		// If a listener set a result, use it instead of the engine
		if ([] !== $searchEvent->getResult()->hits) {
			return $searchEvent->getResult();
		}

		// Delegate to the engine
		$engine = $this->engineRegistry->resolveForConfig($index);

		return $engine->search($index, $searchEvent->getParams(), $searchEvent->getQuery());
	}

	/**
	 * Autocomplete suggestions for the front-end index.
	 *
	 * @return list<array{id:int|string, type:string, label:string}>
	 */
	public function autocomplete(string $query, int $limit = 10): array
	{
		if (!$this->enabled) {
			return [];
		}

		$index = $this->resolveIndex('frontend');
		if (null === $index) {
			return [];
		}

		$engine = $this->engineRegistry->resolveForConfig($index);

		return $engine->autocomplete($index, $query, $limit);
	}

	/**
	 * Get the names of filters visible to the front-end for a given index.
	 *
	 * @return list<array{name:string,label:string}>
	 */
	public function getPublicFilterNames(string $slug): array
	{
		$index = $this->resolveIndex($slug);
		if (null === $index) {
			return [];
		}

		$filters = $this->filterRepository->findEnabledByIndex($index->getId() ?? 0);
		$resolved = $this->filterRegistry->resolveForContext($filters, false);

		$result = [];
		foreach ($resolved as $item) {
			$filter = $item['config'];
			$result[] = [
				'name' => $filter->getName(),
				'label' => $filter->getLabel(),
			];
		}

		return $result;
	}

	private function resolveIndex(string $slug): ?SearchIndex
	{
		$siteId = null;
		try {
			$site = SiteRegistry::getCurrent();
			$siteId = $site->getId();
		} catch (\Throwable) {
			// No current site (e.g. CLI) — search global configs only
		}

		return $this->indexRepository->findBySlug($slug, $siteId);
	}
}
