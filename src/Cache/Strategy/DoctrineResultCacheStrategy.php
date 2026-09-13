<?php

namespace iikiti\CMS\Cache\Strategy;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\CachingStrategyInterface;
use iikiti\CMS\Service\CacheState;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Caches raw DBAL query results using Doctrine ORM's result cache.
 *
 * Result cache entries store the raw database rows, so entities are
 * re-hydrated on every cache hit. This preserves Doctrine lifecycle events
 * (e.g. postLoad) and avoids entity serialization problems.
 *
 * Invalidation is driven by a per-entity-class generation number. Cache keys
 * include the generation, so bumping it makes previously cached entries
 * unreachable (they expire via TTL as a safety net).
 */
class DoctrineResultCacheStrategy implements CachingStrategyInterface
{
	public const NAME = 'doctrine_result_cache';

	public function __construct(
		#[Autowire(service: 'cache.database')]
		private readonly CacheItemPoolInterface $cachePool,
		private readonly CacheState $cacheState,
		#[Autowire('%iikiti_cache.default_ttl%')]
		private readonly int $defaultTTL = 300,
	) {
	}

	public function getName(): string
	{
		return self::NAME;
	}

	public function getLabel(): string
	{
		return 'Doctrine ORM Result Cache';
	}

	public function getDescription(): string
	{
		return 'Caches raw DBAL query results via Doctrine ORM result cache. '.
			'Entities are re-hydrated on cache hits, preserving lifecycle events.';
	}

	public function getCapabilities(): array
	{
		return ['query_level', 'ttl', 'invalidation'];
	}

	public function isEnabled(array $options): bool
	{
		if (!$this->cacheState->isEnabled()) {
			return false;
		}

		return false !== ($options['cache'] ?? true);
	}

	/**
	 * @param Query<array-key,mixed> $query
	 */
	public function decorateQuery(Query $query, string $cacheKey, ?int $ttl): void
	{
		$query->setResultCache($this->cachePool);
		$query->enableResultCache($ttl ?? $this->defaultTTL, $cacheKey);
	}

	public function cacheResult(string $cacheKey, callable $callback, ?int $ttl, array $tags): mixed
	{
		return $callback();
	}

	public function invalidate(string $entityClass): void
	{
	}

	public function clear(): void
	{
		$this->cachePool->clear();
	}
}
