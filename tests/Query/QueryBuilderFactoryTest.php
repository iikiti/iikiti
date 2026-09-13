<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\PostgreSQLQueryBuilder;
use iikiti\CMS\Query\QueryBuilderFactory;
use PHPUnit\Framework\TestCase;

final class QueryBuilderFactoryTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testCreateDetectsPlatform(): void
	{
		$factory = $this->createFactory();
		$qb = $factory->create();

		$this->assertInstanceOf(PostgreSQLQueryBuilder::class, $qb);
		$this->assertSame('postgresql', $qb->getPlatformStrategy()->getName());
	}

	public function testCreateWithExplicitStrategy(): void
	{
		$factory = $this->createFactory();
		$qb = $factory->createForStrategy(new PostgreSQLPlatformStrategy());

		$this->assertInstanceOf(PostgreSQLQueryBuilder::class, $qb);
	}

	public function testSafetyFlagPropagates(): void
	{
		$factory = new QueryBuilderFactory($this->createConnection(), $this->createRegistry(), false);

		$this->assertFalse($factory->create()->isSafetyEnabled());
	}

	public function testExposesConnectionAndRegistry(): void
	{
		$registry = $this->createRegistry();
		$factory = new QueryBuilderFactory($this->createConnection(), $registry, true);

		$this->assertSame($registry, $factory->getPlatformRegistry());
	}

	private function createFactory(): QueryBuilderFactory
	{
		return new QueryBuilderFactory($this->createConnection(), $this->createRegistry(), true);
	}

	private function createRegistry(): DatabasePlatformRegistry
	{
		return new DatabasePlatformRegistry(
			[new PostgreSQLPlatformStrategy()],
			PostgreSQLPlatformStrategy::NAME
		);
	}
}
