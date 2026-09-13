<?php

namespace iikiti\CMS\Query;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;

/**
 * Creates query builders bound to a connection and a platform strategy.
 *
 * The factory detects the database platform from the connection and selects
 * the matching registered strategy. An explicit strategy name can be supplied
 * to override detection.
 *
 * ```php
 * $qb = $queryBuilderFactory->create();
 * $qb->select('s.id')->from(new Table('sites', 's'));
 * ```
 */
final class QueryBuilderFactory
{
	public function __construct(
		private readonly Connection $connection,
		private readonly DatabasePlatformRegistry $platformRegistry,
		private readonly bool $safetyEnabled = true,
	) {
	}

	/**
	 * Create a builder for the connection's platform (or a named strategy).
	 */
	public function create(?string $platform = null): QueryBuilder
	{
		return $this->createForStrategy(
			$this->platformRegistry->resolveForConnection($this->connection, $platform)
		);
	}

	/**
	 * Create a builder using an explicit platform strategy.
	 */
	public function createForStrategy(DatabasePlatformStrategyInterface $strategy): QueryBuilder
	{
		$class = $strategy->getQueryBuilderClass();

		return new $class($this->connection, $strategy, $this->safetyEnabled);
	}

	public function getConnection(): Connection
	{
		return $this->connection;
	}

	public function getPlatformRegistry(): DatabasePlatformRegistry
	{
		return $this->platformRegistry;
	}
}
