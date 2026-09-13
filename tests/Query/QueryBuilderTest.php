<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Clause\OffsetLimitClause;
use iikiti\CMS\Query\Clause\OrderByClause;
use iikiti\CMS\Query\Cte;
use iikiti\CMS\Query\Exception\IdentifierValidationException;
use iikiti\CMS\Query\Exception\UnionException;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\QueryType;
use iikiti\CMS\Query\UnionQueryPart;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testSelectFromWhere(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s.id', 's.name')->
			from('sites', 's')->
			where($qb->expr()->eq('s.domain', 'example.com'));

		$this->assertSame(
			'SELECT s.id, s.name FROM sites s WHERE s.domain = :qp1',
			$qb->getSQL()
		);
		$this->assertSame(['qp1' => 'example.com'], $qb->getParameters());
		$this->assertSame(QueryType::SELECT, $qb->getQueryType());
	}

	public function testSelectWithTypedIdentifiers(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->selectColumn($qb->expr()->column('id', 's'), $qb->expr()->alias('site_id'))->
			from($qb->expr()->table('sites'), $qb->expr()->alias('s'));

		$this->assertSame('SELECT s.id AS site_id FROM sites s', $qb->getSQL());
	}

	public function testUpdateWithParameter(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->update('sites')->
			set('active', $qb->parameter(true))->
			where($qb->expr()->eq('id', 5));

		$this->assertSame('UPDATE sites SET active = :qp1 WHERE id = :qp2', $qb->getSQL());
		$this->assertSame(['qp1' => true, 'qp2' => 5], $qb->getParameters());
		$this->assertSame(QueryType::UPDATE, $qb->getQueryType());
	}

	public function testDeleteWithCondition(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->delete('sites')->where($qb->expr()->eq('id', 5));

		$this->assertSame('DELETE FROM sites WHERE id = :qp1', $qb->getSQL());
		$this->assertSame(QueryType::DELETE, $qb->getQueryType());
	}

	public function testJoin(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s.id')->
			from('sites', 's')->
			innerJoin('s', 'objects', 'o', $qb->expr()->eq('o.site_id', $qb->expr()->column('s.id')));

		$this->assertSame('SELECT s.id FROM sites s INNER JOIN objects o ON o.site_id = s.id', $qb->getSQL());
	}

	public function testCommonTableExpression(): void
	{
		$inner = $this->createQueryBuilder();
		$inner->select('s.id')->from('sites', 's')->where($inner->expr()->eq('s.active', true));

		$qb = $this->createQueryBuilder();
		$qb->withCte(new Cte('active_sites', $inner, ['id']));
		$qb->select('a.id')->from('active_sites', 'a');

		$this->assertSame(
			'WITH active_sites (id) AS (SELECT s.id FROM sites s WHERE s.active = :qp1) SELECT a.id FROM active_sites a',
			$qb->getSQL()
		);
		$this->assertSame(['qp1' => true], $qb->getParameters());
	}

	public function testUnion(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->unionPart(new UnionQueryPart('SELECT id FROM sites'));
		$qb->addUnionPart(new UnionQueryPart('SELECT id FROM pages'));

		$this->assertSame('(SELECT id FROM sites) UNION (SELECT id FROM pages)', $qb->getSQL());
	}

	public function testCloneIsIndependent(): void
	{
		$original = $this->createQueryBuilder();
		$original->select('*')->from('sites');

		$clone = clone $original;
		$clone->where($clone->expr()->eq('id', 1));

		$this->assertStringNotContainsString('WHERE', $original->getSQL());
		$this->assertStringContainsString('WHERE', $clone->getSQL());
	}

	public function testSafetyToggle(): void
	{
		$qb = $this->createQueryBuilder();
		$this->assertTrue($qb->isSafetyEnabled());

		$qb->disableSafety();
		$this->assertFalse($qb->isSafetyEnabled());

		$qb->enableSafety();
		$this->assertTrue($qb->isSafetyEnabled());
	}

	public function testFromRejectsUnsafeStringIdentifier(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(IdentifierValidationException::class);

		$qb->select('*')->from('users; DROP TABLE users; --');
	}

	public function testSetRejectsUnsafeColumnKey(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(IdentifierValidationException::class);

		$qb->update('sites')->set('id = 1, hacked', $qb->parameter('x'));
	}

	public function testJoinRejectsUnsafeAlias(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(IdentifierValidationException::class);

		$qb->select('*')->from('sites', 's')->innerJoin('s; DROP TABLE x', 'objects', 'o');
	}

	public function testAddUnionPartWithoutInitialPartThrows(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(UnionException::class);

		$qb->addUnionPart(new UnionQueryPart('SELECT id FROM pages'));
	}

	public function testUnionPartThenAddUnionPart(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->unionPart(new UnionQueryPart('SELECT id FROM sites'));
		$qb->addUnionPart(new UnionQueryPart('SELECT id FROM pages'));

		$this->assertSame('(SELECT id FROM sites) UNION (SELECT id FROM pages)', $qb->getSQL());
	}

	public function testOrderByClauseAndApplyLimit(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->orderByClause(
			new OrderByClause(new Column('name'), 'DESC', 'LAST'),
			new OrderByClause(new Column('id')),
		);

		$this->assertSame('SELECT * FROM sites ORDER BY name DESC NULLS LAST, id ASC', $qb->getSQL());

		$qb->applyLimit(new OffsetLimitClause(10, 5));
		$this->assertSame(10, $qb->getMaxResults());
		$this->assertSame(5, $qb->getFirstResult());
	}

	public function testCrossBuilderCteParametersAreReboundWithoutCollision(): void
	{
		$inner = $this->createQueryBuilder();
		$inner->select('id')->from('sites')->where($inner->expr()->eq('active', true));

		$outer = $this->createQueryBuilder();
		$outer->select('*')->from('objects')->where($outer->expr()->eq('kind', 'page'));
		$outer->withCte(new Cte('active_sites', $inner, ['id']));

		$sql = $outer->getSQL();

		$this->assertStringContainsString('active_sites (id) AS (SELECT id FROM sites WHERE active = :qp2)', $sql);
		$this->assertStringContainsString('kind = :qp1', $sql);

		$parameters = $outer->getParameters();
		$this->assertCount(2, $parameters);
		$this->assertSame('page', $parameters['qp1']);
		$this->assertTrue($parameters['qp2']);
	}

	public function testCteSourceBuilderRemainsUsableAfterImport(): void
	{
		$inner = $this->createQueryBuilder();
		$inner->select('id')->from('sites')->where($inner->expr()->eq('active', true));

		$outer = $this->createQueryBuilder();
		$outer->select('*')->from('objects')->where($outer->expr()->eq('kind', 'page'));
		$outer->withCte(new Cte('active_sites', $inner, ['id']));

		// Importing the CTE's parameters into the outer builder must not break
		// the source builder, which keeps its own placeholder names.
		$this->assertStringContainsString('active = :qp1', $inner->getSQL());
		$this->assertSame(['qp1' => true], $inner->getParameters());
	}
}
