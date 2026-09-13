<?php

namespace iikiti\CMS\Tests\ORM;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ORM\QueryBuilder;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;

trait CreatesOrmQueryBuilders
{
	private function createStrategy(): DatabasePlatformStrategyInterface
	{
		return new PostgreSQLPlatformStrategy();
	}

	private function createEntityManager(): EntityManagerInterface
	{
		return $this->createStub(EntityManagerInterface::class);
	}

	private function createQueryBuilder(bool $safetyEnabled = true): QueryBuilder
	{
		return new QueryBuilder(
			$this->createEntityManager(),
			$this->createStrategy(),
			$safetyEnabled
		);
	}
}
