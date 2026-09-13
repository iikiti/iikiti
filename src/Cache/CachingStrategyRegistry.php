<?php

namespace iikiti\CMS\Cache;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry of available caching strategies.
 *
 * Strategies are collected at compile time from services tagged with
 * 'iikiti.cache_strategy', and can also be registered at runtime (e.g. by
 * plugins or bundles) through {@see register()}.
 */
class CachingStrategyRegistry
{
	public const DEFAULT_STRATEGY = 'doctrine_result_cache';
	public const NONE_STRATEGY = 'none';

	/** @var array<string,CachingStrategyInterface> */
	private array $strategies = [];

	private string $defaultStrategy;

	/**
	 * @param iterable<CachingStrategyInterface> $strategies
	 */
	public function __construct(
		#[AutowireIterator('iikiti.cache_strategy')]
		iterable $strategies = [],
		#[Autowire('%iikiti_cache.strategy%')]
		string $defaultStrategy = self::DEFAULT_STRATEGY,
	) {
		foreach ($strategies as $strategy) {
			$this->register($strategy->getName(), $strategy);
		}
		$this->defaultStrategy = $defaultStrategy;
	}

	/**
	 * Register (or replace) a strategy at runtime.
	 */
	public function register(string $name, CachingStrategyInterface $strategy): void
	{
		$this->strategies[$name] = $strategy;
	}

	/**
	 * Remove a strategy from the registry.
	 */
	public function unregister(string $name): void
	{
		unset($this->strategies[$name]);
	}

	public function getStrategy(string $name): ?CachingStrategyInterface
	{
		return $this->strategies[$name] ?? null;
	}

	public function hasStrategy(string $name): bool
	{
		return isset($this->strategies[$name]);
	}

	public function getDefaultStrategy(): string
	{
		return $this->defaultStrategy;
	}

	public function setDefaultStrategy(string $name): void
	{
		$this->defaultStrategy = $name;
	}

	/**
	 * Return metadata for every registered strategy. Suitable for admin UIs.
	 *
	 * @return array<int,StrategyMetadata>
	 */
	public function getAvailableStrategies(): array
	{
		$available = [];
		foreach ($this->strategies as $strategy) {
			$available[] = new StrategyMetadata(
				$strategy->getName(),
				$strategy->getLabel(),
				$strategy->getDescription(),
				$strategy->getCapabilities()
			);
		}

		return $available;
	}
}
