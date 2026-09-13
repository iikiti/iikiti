<?php

namespace iikiti\CMS\Cache\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryResultItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Caches API Platform item query results through the active caching strategy.
 *
 * @template T of object
 *
 * @implements QueryResultItemExtensionInterface<T>
 */
class ApiPlatformCacheItemExtension extends ApiPlatformCacheExtension implements
	QueryItemExtensionInterface,
	QueryResultItemExtensionInterface
{
	public function applyToItem(
		QueryBuilder $queryBuilder,
		QueryNameGeneratorInterface $queryNameGenerator,
		string $resourceClass,
		array $identifiers,
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

	public function getResult(
		QueryBuilder $queryBuilder,
		?string $resourceClass = null,
		?Operation $operation = null,
		array $context = [],
	): ?object {
		$query = $queryBuilder->getQuery();
		$this->decorate(
			$queryBuilder,
			$query,
			(string) $resourceClass,
			'item',
			$operation,
			$context
		);

		return $query->getOneOrNullResult();
	}
}
