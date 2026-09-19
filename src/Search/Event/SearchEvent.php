<?php

namespace iikiti\CMS\Search\Event;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Service\SearchParams;
use iikiti\CMS\Search\Service\SearchResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired before a search query is executed against the engine.
 *
 * Listeners can inspect or override the query, params, and result.
 */
class SearchEvent extends Event
{
	private SearchResult $result;

	public function __construct(
		private SearchIndex $index,
		private string $query,
		private SearchParams $params,
	) {
		$this->result = new SearchResult(
			hits: [],
			total: 0,
			limit: $params->limit ?? 20,
			offset: $params->offset ?? 0,
		);
	}

	public function getIndex(): SearchIndex
	{
		return $this->index;
	}

	public function getQuery(): string
	{
		return $this->query;
	}

	public function setQuery(string $query): static
	{
		$this->query = $query;

		return $this;
	}

	public function getParams(): SearchParams
	{
		return $this->params;
	}

	public function setParams(SearchParams $params): static
	{
		$this->params = $params;

		return $this;
	}

	public function getResult(): SearchResult
	{
		return $this->result;
	}

	public function setResult(SearchResult $result): static
	{
		$this->result = $result;

		return $this;
	}
}
