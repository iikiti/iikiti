<?php

namespace iikiti\CMS\Search\Service;

/**
 * The result of executing a search query.
 */
final readonly class SearchResult
{
	/**
	 * @param list<SearchHit>   $hits      Ordered list of matching hits
	 * @param int               $total     Total matches (before limit/offset)
	 * @param int               $limit     Applied limit
	 * @param int               $offset    Applied offset
	 * @param array<string,int> $facets    Facet name => count
	 * @param float             $elapsedMs Query execution time in milliseconds
	 */
	public function __construct(
		public array $hits = [],
		public int $total = 0,
		public int $limit = 20,
		public int $offset = 0,
		public array $facets = [],
		public float $elapsedMs = 0.0,
	) {
	}

	/**
	 * @return list<SearchHit>
	 */
	public function getHits(): array
	{
		return $this->hits;
	}
}
