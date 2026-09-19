<?php

namespace iikiti\CMS\Search\Service;

/**
 * Parameters that control how a search query is executed.
 *
 * This value object is passed through the search pipeline: the SearchService
 * resolves the active SearchIndex config for the current site, applies the
 * appropriate filters, and delegates to the SearchEngineInterface.
 */
final readonly class SearchParams
{
	public function __construct(
		public ?int $limit = 20,
		public ?int $offset = 0,
		public ?string $language = null,
		public ?string $queryType = null,
		public bool $highlight = true,
		/** @var array<string,mixed> */
		public array $filters = [],
		/** @var array<string,mixed> */
		public array $extraCriteria = [],
		public ?string $sort = null,
		public ?string $direction = 'desc',
		public ?string $analyzer = null,
		public ?int $minWordLength = null,
	) {
	}

	/**
	 * Build SearchParams from an options array (used by repository search()).
	 *
	 * @param array<string,mixed> $options
	 */
	public static function fromOptions(array $options): self
	{
		return new self(
			limit: isset($options['limit']) ? (int) $options['limit'] : 20,
			offset: isset($options['offset']) ? (int) $options['offset'] : 0,
			language: isset($options['language']) ? (string) $options['language'] : null,
			queryType: isset($options['queryType']) ? (string) $options['queryType'] : null,
			highlight: (bool) ($options['highlight'] ?? true),
			filters: isset($options['filters']) && is_array($options['filters']) ? $options['filters'] : [],
			extraCriteria: isset($options['criteria']) && is_array($options['criteria']) ? $options['criteria'] : [],
			sort: isset($options['sort']) ? (string) $options['sort'] : null,
			direction: isset($options['direction']) ? (string) $options['direction'] : 'desc',
			analyzer: isset($options['analyzer']) ? (string) $options['analyzer'] : null,
			minWordLength: isset($options['minWordLength']) ? (int) $options['minWordLength'] : null,
		);
	}
}
