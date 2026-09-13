<?php

namespace iikiti\CMS\Service;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\CachingStrategyInterface;
use iikiti\CMS\Cache\CachingStrategyRegistry;
use iikiti\CMS\Cache\Strategy\DoctrineResultCacheStrategy;
use iikiti\CMS\Cache\Strategy\NoCacheStrategy;
use iikiti\CMS\Cache\StrategyMetadata;
use iikiti\CMS\Registry\SiteRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Coordinates query result caching for repositories and the API Platform
 * cache extension.
 *
 * Caching is delegated to the active {@see CachingStrategyInterface}, resolved
 * through {@see CachingStrategyRegistry}. Generations are tracked per entity
 * class so that cache keys change whenever an entity type is written,
 * invalidating previously cached results.
 */
class DatabaseCacheManager
{
	private const GENERATION_KEY_PREFIX = 'db_cache_generation_';
	private const CACHE_KEY_PREFIX = 'db_cache';

	private readonly NoCacheStrategy $fallbackStrategy;

	public function __construct(
		#[Autowire(service: 'cache.database')]
		private readonly CacheItemPoolInterface $cachePool,
		private readonly CacheState $cacheState,
		private readonly CachingStrategyRegistry $strategyRegistry,
		#[Autowire('%iikiti_cache.default_ttl%')]
		private readonly int $defaultTTL = 300,
	) {
		$this->fallbackStrategy = new NoCacheStrategy();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function isCachingEnabled(array $options): bool
	{
		return $this->getStrategy()->isEnabled($options);
	}

	public function getStrategy(): CachingStrategyInterface
	{
		if (!$this->cacheState->isEnabled()) {
			return $this->resolve('none');
		}

		return $this->resolve($this->cacheState->getOverrideStrategy() ?? $this->strategyRegistry->getDefaultStrategy());
	}

	/**
	 * @param Query<array-key,mixed> $query
	 * @param array<string,mixed>    $context
	 */
	public function decorateQuery(Query $query, string $entityClass, array $context): void
	{
		$strategy = $this->getStrategy();
		if (!$strategy->isEnabled($context['options'] ?? [])) {
			return;
		}

		$cacheKey = $this->generateCacheKey(
			$entityClass,
			(string) ($context['operation'] ?? 'query'),
			$context
		);
		$strategy->decorateQuery($query, $cacheKey, $this->resolveTtl($context['options'] ?? []));
	}

	/**
	 * @param callable(): mixed   $callback
	 * @param array<string,mixed> $options
	 */
	public function cacheResult(string $entityClass, string $operation, callable $callback, array $options): mixed
	{
		$strategy = $this->getStrategy();
		if (!$strategy->isEnabled($options)) {
			return $callback();
		}

		$cacheKey = $this->generateCacheKey($entityClass, $operation, ['operation' => $operation, 'options' => $options]);

		return $strategy->cacheResult($cacheKey, $callback, $this->resolveTtl($options), [$entityClass]);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function generateCacheKey(string $entityClass, string $operation, array $context): string
	{
		$siteId = $this->currentSiteId();
		$generation = $this->getGeneration($entityClass);
		$hashContext = $context;
		unset($hashContext['options'], $hashContext['cache']);
		$hash = hash('xxh3', serialize($hashContext));

		return sprintf(
			'%s:v%d:%s:%s:%s:%d',
			self::CACHE_KEY_PREFIX,
			$generation,
			str_replace('\\', '_', $entityClass),
			$operation,
			$hash,
			$siteId
		);
	}

	public function invalidate(string $entityClass): void
	{
		$this->bumpGeneration($entityClass);
		$this->getStrategy()->invalidate($entityClass);
	}

	public function getGeneration(string $entityClass): int
	{
		$item = $this->cachePool->getItem(self::GENERATION_KEY_PREFIX.str_replace('\\', '_', $entityClass));
		if (!$item->isHit()) {
			return 1;
		}

		return max(1, (int) $item->get());
	}

	public function bumpGeneration(string $entityClass): void
	{
		$item = $this->cachePool->getItem(
			self::GENERATION_KEY_PREFIX.str_replace('\\', '_', $entityClass)
		);
		$item->set($this->getGeneration($entityClass) + 1);
		$this->cachePool->save($item);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function resolveTtl(array $options): int
	{
		$ttl = $options['cacheTTL'] ?? null;

		return (is_int($ttl) && $ttl > 0) ? $ttl : $this->defaultTTL;
	}

	public function clear(): void
	{
		$this->cachePool->clear();
		$this->getStrategy()->clear();
	}

	/**
	 * @return array<int,StrategyMetadata>
	 */
	public function getAvailableStrategies(): array
	{
		return $this->strategyRegistry->getAvailableStrategies();
	}

	private function resolve(string $name): CachingStrategyInterface
	{
		$strategy = $this->strategyRegistry->getStrategy($name);
		if (null !== $strategy) {
			return $strategy;
		}

		return $this->strategyRegistry->getStrategy(DoctrineResultCacheStrategy::NAME) ?? $this->fallbackStrategy;
	}

	/**
	 * Current site identifier, or 0 when no site context is available
	 * (e.g. console commands or unit tests without a booted request).
	 */
	private function currentSiteId(): int
	{
		if (!SiteRegistry::isInitialized()) {
			return 0;
		}

		return SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent()->getId() : 0;
	}
}
