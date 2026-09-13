<?php

namespace iikiti\CMS\Query\DatabasePlatform;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;

/**
 * Registry of database platform strategies.
 *
 * Strategies are collected at compile time from services tagged with
 * `iikiti.query.database_platform`, and may also be registered at runtime by
 * plugins or bundles through {@see register()}. Resolution can be explicit (by
 * name) or automatic (by matching the connection's Doctrine platform).
 */
final class DatabasePlatformRegistry
{
	public const DEFAULT_STRATEGY = PostgreSQLPlatformStrategy::NAME;

	/** @var array<string,DatabasePlatformStrategyInterface> */
	private array $strategies = [];

	private string $defaultStrategy;

	/**
	 * @param iterable<DatabasePlatformStrategyInterface> $strategies
	 */
	public function __construct(
		iterable $strategies = [],
		string $defaultStrategy = self::DEFAULT_STRATEGY,
	) {
		foreach ($strategies as $strategy) {
			$this->register($strategy->getName(), $strategy);
		}
		$this->defaultStrategy = $defaultStrategy;
	}

	public function register(string $name, DatabasePlatformStrategyInterface $strategy): void
	{
		$this->strategies[$name] = $strategy;
	}

	public function unregister(string $name): void
	{
		unset($this->strategies[$name]);
	}

	public function has(string $name): bool
	{
		return isset($this->strategies[$name]);
	}

	public function get(string $name): ?DatabasePlatformStrategyInterface
	{
		return $this->strategies[$name] ?? null;
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
	 * Resolve a strategy by name, falling back to the configured default.
	 */
	public function resolve(?string $name = null): DatabasePlatformStrategyInterface
	{
		if (null !== $name && isset($this->strategies[$name])) {
			return $this->strategies[$name];
		}

		if (isset($this->strategies[$this->defaultStrategy])) {
			return $this->strategies[$this->defaultStrategy];
		}

		throw new \RuntimeException(sprintf('No database platform strategy registered for "%s".', $name ?? $this->defaultStrategy));
	}

	/**
	 * Resolve the strategy matching the connection's Doctrine platform.
	 */
	public function resolveForConnection(
		Connection $connection,
		?string $name = null,
	): DatabasePlatformStrategyInterface {
		if (null !== $name) {
			return $this->resolve($name);
		}

		$platform = $connection->getDatabasePlatform();
		foreach ($this->strategies as $strategy) {
			if ($strategy->supportsPlatform($platform)) {
				return $strategy;
			}
		}

		return $this->resolve(null);
	}

	/**
	 * @return array<string,DatabasePlatformStrategyInterface>
	 */
	public function all(): array
	{
		return $this->strategies;
	}

	/**
	 * @return array<int,array{name:string,label:string}>
	 */
	public function describe(): array
	{
		$described = [];
		foreach ($this->strategies as $strategy) {
			$described[] = [
				'name' => $strategy->getName(),
				'label' => $strategy->getLabel(),
			];
		}

		return $described;
	}
}
