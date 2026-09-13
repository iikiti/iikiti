<?php

namespace iikiti\CMS\Cache\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Caches API Platform collection query results.
 *
 * Registered with a priority lower than the pagination extension so the
 * paginator result takes precedence when pagination is active. Non-paginated
 * collections are executed through the caching strategy.
 *
 * @template T of object
 *
 * @implements QueryResultCollectionExtensionInterface<T>
 */
class ApiPlatformCacheCollectionExtension extends ApiPlatformCacheExtension implements
	QueryCollectionExtensionInterface,
	QueryResultCollectionExtensionInterface
{
	public function applyToCollection(
		QueryBuilder $queryBuilder,
		QueryNameGeneratorInterface $queryNameGenerator,
		string $resourceClass,
		?Operation $operation = null,
		array $context = [],
	): void {
	}

	public function supportsResult(
		string $resourceClass,
		?Operation $operation = null,
		array $context = [],
	): bool {
		return $this->cacheManager->isCachingEnabled($this->operationCacheOptions($operation));
	}

	/**
	 * @return iterable<int,mixed>
	 */
	public function getResult(
		QueryBuilder $queryBuilder,
		?string $resourceClass = null,
		?Operation $operation = null,
		array $context = [],
	): iterable {
		$query = $queryBuilder->getQuery();
		$this->decorate(
			$queryBuilder,
			$query,
			(string) $resourceClass,
			'collection',
			$operation,
			$context
		);

		return $query->getResult();
	}
}
