<?php

namespace iikiti\CMS\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\ExpressionBuilder;
use iikiti\CMS\Query\ParameterBag;
use iikiti\CMS\Query\PostgreSQLQueryBuilder;
use iikiti\CMS\Query\QueryBuilder;

/**
 * Shared helpers for query builder tests.
 *
 * A mocked connection backed by a real PostgreSQL platform is used so tests
 * exercise real SQL generation without requiring a live database.
 */
trait CreatesQueryBuilders
{
	private function createStrategy(): PostgreSQLPlatformStrategy
	{
		return new PostgreSQLPlatformStrategy();
	}

	private function createConnection(): Connection
	{
		$connection = $this->createStub(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$connection->method('quote')->willReturnCallback(
			static fn (string $value): string => "'".str_replace("'", "''", $value)."'"
		);

		return $connection;
	}

	private function createExpressionBuilder(
		?DatabasePlatformStrategyInterface $strategy = null,
		?ParameterBag $parameters = null,
		?Connection $connection = null,
	): ExpressionBuilder {
		return new ExpressionBuilder(
			$connection ?? $this->createConnection(),
			$strategy ?? $this->createStrategy(),
			$parameters ?? new ParameterBag(),
		);
	}

	private function createQueryBuilder(
		bool $safetyEnabled = true,
		?DatabasePlatformStrategyInterface $strategy = null,
		?Connection $connection = null,
	): PostgreSQLQueryBuilder {
		return new PostgreSQLQueryBuilder(
			$connection ?? $this->createConnection(),
			$strategy ?? $this->createStrategy(),
			$safetyEnabled,
		);
	}

	private function createGenericQueryBuilder(bool $safetyEnabled = true): QueryBuilder
	{
		return new QueryBuilder($this->createConnection(), $this->createStrategy(), $safetyEnabled);
	}
}
