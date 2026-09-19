<?php

namespace iikiti\CMS\Search\Service;

/**
 * A single hit returned by a search.
 */
final readonly class SearchHit
{
	/**
	 * @param int|string          $id         Object identifier
	 * @param string              $type       Object discriminator type (e.g. 'Page', 'User')
	 * @param float               $rank       Relevance score
	 * @param array<string,mixed> $data       Stored field data (label, excerpt, etc.)
	 * @param array<string,mixed> $highlights Highlighted fragments per field
	 */
	public function __construct(
		public int|string $id,
		public string $type,
		public float $rank = 0.0,
		public array $data = [],
		public array $highlights = [],
	) {
	}
}
