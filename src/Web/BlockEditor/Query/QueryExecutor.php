<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Selects the appropriate source for a definition and executes it.
 */
final class QueryExecutor
{
	/**
	 * @param iterable<QuerySourceInterface> $sources
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cms.query_source')]
		private readonly iterable $sources = [],
	) {
	}

	/**
	 * @return list<mixed>
	 */
	public function execute(QueryDefinition $definition, ?int $siteId): array
	{
		$source = $this->sourceByName($definition->getSource());
		if (null === $source) {
			return [];
		}

		return $source->execute($definition, $siteId);
	}

	public function hasSource(string $name): bool
	{
		return null !== $this->sourceByName($name);
	}

	private function sourceByName(string $name): ?QuerySourceInterface
	{
		foreach ($this->sources as $source) {
			if ($source->getName() === $name) {
				return $source;
			}
		}

		return null;
	}
}
