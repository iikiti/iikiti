<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Clause\HavingClause;
use iikiti\CMS\Query\Clause\OffsetLimitClause;
use iikiti\CMS\Query\Clause\OrderByClause;
use iikiti\CMS\Query\Clause\WhereClause;
use iikiti\CMS\Query\Identifier\Column;
use PHPUnit\Framework\TestCase;

final class ClauseTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testWhereClauseCombinesWithAnd(): void
	{
		$expr = $this->createExpressionBuilder();
		$clause = WhereClause::and($expr->condition('a', \iikiti\CMS\Query\Operator::EQ, 1), $expr->condition('b', \iikiti\CMS\Query\Operator::EQ, 2));

		$this->assertSame('(a = :qp1) AND (b = :qp2)', (string) $clause);
		$this->assertCount(2, $clause->getParameters()->all());
	}

	public function testWhereClauseCombinesWithOr(): void
	{
		$expr = $this->createExpressionBuilder();
		$clause = WhereClause::or($expr->condition('a', \iikiti\CMS\Query\Operator::EQ, 1), $expr->condition('b', \iikiti\CMS\Query\Operator::EQ, 2));

		$this->assertSame('(a = :qp1) OR (b = :qp2)', (string) $clause);
	}

	public function testEmptyWhereClauseIsTautology(): void
	{
		$this->assertSame('1 = 1', (string) WhereClause::and());
	}

	public function testHavingClause(): void
	{
		$expr = $this->createExpressionBuilder();
		$clause = HavingClause::and($expr->condition('total', \iikiti\CMS\Query\Operator::GT, 10));

		$this->assertSame('total > :qp1', (string) $clause);
	}

	public function testOrderByClause(): void
	{
		$this->assertSame('u.name ASC', (string) new OrderByClause(new Column('u.name')));
		$this->assertSame(
			'u.created_at DESC NULLS LAST',
			(string) new OrderByClause(new Column('u.created_at'), 'DESC', 'LAST')
		);
	}

	public function testOrderByRejectsInvalidDirection(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new OrderByClause(new Column('u.name'), 'SIDEWAYS');
	}

	public function testOffsetLimitClause(): void
	{
		$clause = new OffsetLimitClause(10, 20);

		$this->assertSame(10, $clause->getLimit());
		$this->assertSame(20, $clause->getOffset());
		$this->assertTrue($clause->hasLimit());
		$this->assertTrue($clause->hasOffset());
	}

	public function testOffsetLimitRejectsNegativeValues(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new OffsetLimitClause(-1);
	}
}
