<?php

namespace iikiti\CMS\Cache\Strategy;

use Doctrine\ORM\Query;
use iikiti\CMS\Cache\CachingStrategyInterface;

/**
 * Strategy that disables database query caching entirely.
 *
 * Used when caching is disabled for the request (batch processing) or when
 * the 'none' strategy is configured explicitly.
 */
class NoCacheStrategy implements CachingStrategyInterface
{
	public function getName(): string
	{
		return 'none';
	}

	public function getLabel(): string
	{
		return 'Disabled';
	}

	public function getDescription(): string
	{
		return 'Disables database query caching. Queries always hit the database.';
	}

	public function getCapabilities(): array
	{
		return [];
	}

	public function isEnabled(array $options): bool
	{
		return false;
	}

	/**
	 * @param Query<array-key,mixed> $query
	 */
	public function decorateQuery(Query $query, string $cacheKey, ?int $ttl): void
	{
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
	}
}
