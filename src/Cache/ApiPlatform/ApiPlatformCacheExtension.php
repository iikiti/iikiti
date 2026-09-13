<?php

namespace iikiti\CMS\Cache\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Shared logic for applying the active caching strategy to API Platform
 * Doctrine queries.
 *
 * API Platform's collection and item providers build Doctrine QueryBuilders
 * and fetch results after all query extensions have run. The result-producing
 * extensions intercept that fetch and execute the query through the active
 * caching strategy, so REST requests for {@see \iikiti\CMS\Entity\DbObject}
 * resources benefit from the same query result cache as the repositories.
 */
abstract class ApiPlatformCacheExtension
{
	public function __construct(
		protected readonly DatabaseCacheManager $cacheManager,
	) {
	}

	/**
	 * @param Query<array-key,mixed> $query
	 * @param array<string,mixed>    $context
	 */
	protected function decorate(
		QueryBuilder $queryBuilder,
		Query $query,
		string $resourceClass,
		string $operationName,
		?Operation $operation,
		array $context,
	): void {
		$parameters = [];
		foreach ($queryBuilder->getParameters() as $parameter) {
			$parameters[$parameter->getName()] = $parameter->getValue();
		}

		$this->cacheManager->decorateQuery($query, $resourceClass, [
			'operation' => $operationName,
			'options' => $this->operationCacheOptions($operation),
			'entityClass' => $resourceClass,
			'filters' => $context['filters'] ?? [],
			'sql' => $query->getSQL(),
			'parameters' => $parameters,
		]);
	}

	/**
	 * Per-operation cache settings read from the operation's 'iikiti_cache'
	 * extra property (e.g. ['cache' => false] to bypass caching for a route).
	 *
	 * @return array<string,mixed>
	 */
	protected function operationCacheOptions(?Operation $operation): array
	{
		if (null === $operation) {
			return [];
		}

		$extra = $operation->getExtraProperties()['iikiti_cache'] ?? [];

		return is_array($extra) ? $extra : [];
	}
}
