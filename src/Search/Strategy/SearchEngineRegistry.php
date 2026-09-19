<?php

namespace iikiti\CMS\Search\Strategy;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry of available search engine adapters.
 *
 * Engines are collected at compile time from services tagged with
 * `iikiti.search_engine`, and may also be registered at runtime by plugins
 * or bundles through {@see register()}. Resolution can be explicit (by name)
 * or automatic (by matching the connection's Doctrine platform).
 */
final class SearchEngineRegistry
{
	public const DEFAULT_ENGINE = 'postgresql';

	/** @var array<string,SearchEngineInterface> */
	private array $engines = [];

	private string $defaultEngine;

	/**
	 * @param iterable<SearchEngineInterface> $engines
	 */
	public function __construct(
		#[AutowireIterator('iikiti.search_engine')]
		iterable $engines = [],
		#[Autowire('%iikiti_search.default_engine%')]
		string $defaultEngine = self::DEFAULT_ENGINE,
	) {
		foreach ($engines as $engine) {
			$this->register($engine->getName(), $engine);
		}
		$this->defaultEngine = $defaultEngine;
	}

	public function register(string $name, SearchEngineInterface $engine): void
	{
		$this->engines[$name] = $engine;
	}

	public function unregister(string $name): void
	{
		unset($this->engines[$name]);
	}

	public function has(string $name): bool
	{
		return isset($this->engines[$name]);
	}

	public function get(string $name): ?SearchEngineInterface
	{
		return $this->engines[$name] ?? null;
	}

	public function getDefaultEngine(): string
	{
		return $this->defaultEngine;
	}

	public function setDefaultEngine(string $name): void
	{
		$this->defaultEngine = $name;
	}

	/**
	 * Resolve an engine by name, falling back to the configured default.
	 */
	public function resolve(?string $name = null): SearchEngineInterface
	{
		if (null !== $name && isset($this->engines[$name])) {
			return $this->engines[$name];
		}

		if (isset($this->engines[$this->defaultEngine])) {
			return $this->engines[$this->defaultEngine];
		}

		throw new \RuntimeException(sprintf('No search engine adapter registered for "%s".', $name ?? $this->defaultEngine));
	}

	/**
	 * Resolve the engine matching the connection's Doctrine platform.
	 */
	public function resolveForPlatform(AbstractPlatform $platform, ?string $name = null): SearchEngineInterface
	{
		if (null !== $name) {
			return $this->resolve($name);
		}

		foreach ($this->engines as $engine) {
			if ($engine->supportsPlatform($platform)) {
				return $engine;
			}
		}

		return $this->resolve(null);
	}

	/**
	 * Resolve the engine for a search index configuration.
	 */
	public function resolveForConfig(\iikiti\CMS\Search\Entity\SearchIndex $config): SearchEngineInterface
	{
		return $this->resolve($config->getEngine());
	}

	/**
	 * @return array<string,SearchEngineInterface>
	 */
	public function all(): array
	{
		return $this->engines;
	}

	/**
	 * @return array<int,array{name:string,label:string}>
	 */
	public function describe(): array
	{
		$described = [];
		foreach ($this->engines as $engine) {
			$described[] = [
				'name' => $engine->getName(),
				'label' => $engine->getLabel(),
			];
		}

		return $described;
	}
}
