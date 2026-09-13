<?php

namespace iikiti\CMS\Cache;

use Doctrine\ORM\Query;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for database query caching strategies.
 *
 * Implementations determine how query results are cached (e.g. Doctrine ORM
 * result cache, Redis-backed pools, database-backed key/value stores). Each
 * strategy exposes metadata so that plugins and an administrative UI can
 * enumerate available strategies.
 *
 * Strategies are auto-discovered via the {@see AutoconfigureTag} attribute
 * ('iikiti.cache_strategy') at compile time, or can be registered at runtime
 * through {@see CachingStrategyRegistry::register()}.
 */
#[AutoconfigureTag('iikiti.cache_strategy')]
interface CachingStrategyInterface
{
	public function getName(): string;

	public function getLabel(): string;

	public function getDescription(): string;

	/**
	 * Declares what the strategy supports. Example values:
	 * 'query_level' (decorates Doctrine Query objects), 'method_level'
	 * (wraps callbacks), 'ttl', 'invalidation', 'tags'.
	 *
	 * @return array<int,string>
	 */
	public function getCapabilities(): array;

	/**
	 * Whether caching should be applied for the given options.
	 *
	 * @param array<string,mixed> $options
	 */
	public function isEnabled(array $options): bool;

	/**
	 * Apply caching to a Doctrine ORM query before its result is fetched.
	 * Implementations that operate at the method level can ignore this.
	 *
	 * @param Query<array-key,mixed> $query
	 */
	public function decorateQuery(Query $query, string $cacheKey, ?int $ttl): void;

	/**
	 * Execute a callback and cache its result. Implementations that operate at
	 * the query level should simply invoke the callback.
	 *
	 * @param callable(): mixed $callback
	 * @param array<int,string> $tags
	 */
	public function cacheResult(string $cacheKey, callable $callback, ?int $ttl, array $tags): mixed;

	/**
	 * Invalidate cached entries for an entity class.
	 */
	public function invalidate(string $entityClass): void;

	/**
	 * Clear all cached entries managed by this strategy.
	 */
	public function clear(): void;
}
