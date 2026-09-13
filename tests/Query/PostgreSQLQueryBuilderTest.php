<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Cte;
use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\PostgreSQLQueryBuilder;
use PHPUnit\Framework\TestCase;

final class PostgreSQLQueryBuilderTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testWithRecursiveCte(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->withRecursiveCte(new Cte('tree', 'SELECT 1 AS id'));
		$qb->select('t.id')->from('tree', 't');

		$this->assertSame(
			'WITH RECURSIVE tree AS (SELECT 1 AS id) SELECT t.id FROM tree t',
			$qb->getSQL()
		);
	}

	public function testReturningAppendsClause(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->update('sites')->
			set('active', $qb->parameter(false))->
			where($qb->expr()->eq('id', 1))->
			returning('id', 'active');

		$this->assertSame(
			'UPDATE sites SET active = :qp1 WHERE id = :qp2 RETURNING id, active',
			$qb->getSQL()
		);
	}

	public function testReturningRejectedWhenUnsupported(): void
	{
		$strategy = new class extends \iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy {
			public function supportsReturning(): bool
			{
				return false;
			}
		};

		$qb = new PostgreSQLQueryBuilder($this->createConnection(), $strategy, true);

		$this->expectException(UnsupportedFeatureException::class);

		$qb->returning('id');
	}
}
