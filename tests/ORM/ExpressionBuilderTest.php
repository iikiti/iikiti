<?php

namespace iikiti\CMS\Tests\ORM;

use Doctrine\ORM\Query\Expr\Func;
use iikiti\CMS\Query\ParameterBag;
use PHPUnit\Framework\TestCase;

final class ExpressionBuilderTest extends TestCase
{
	use CreatesOrmQueryBuilders;

	public function testComparisonHelpersBindValues(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id = :qp1', (string) $expr->eq('u.id', 5));
		$this->assertSame('u.id <> :qp2', (string) $expr->neq('u.id', 5));
		$this->assertSame('u.id < :qp3', (string) $expr->lt('u.id', 5));
		$this->assertSame('u.id <= :qp4', (string) $expr->lte('u.id', 5));
		$this->assertSame('u.id > :qp5', (string) $expr->gt('u.id', 5));
		$this->assertSame('u.id >= :qp6', (string) $expr->gte('u.id', 5));
	}

	public function testComparisonPassesPlaceholdersThrough(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id = :crit_id', (string) $expr->eq('u.id', ':crit_id'));
	}

	public function testLikeHelperBindsPattern(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.name LIKE :qp1', (string) $expr->like('u.name', 'A%'));
		$this->assertSame('u.name NOT LIKE :qp2', (string) $expr->notLike('u.name', 'A%'));
	}

	public function testInHelperBindsScalarAndArray(): void
	{
		$parameters = new ParameterBag();
		$expr = $this->createExpressionBuilder($parameters);

		$this->assertSame('u.tags IN(:qp1)', (string) $expr->in('u.tags', [1, 2, 3]));
		$this->assertSame('u.id IN(:qp2)', (string) $expr->in('u.id', 1));

		$this->assertCount(2, $parameters->all());
	}

	public function testNullHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.deleted_at IS NULL', (string) $expr->isNull('u.deleted_at'));
		$this->assertSame('u.deleted_at IS NOT NULL', (string) $expr->isNotNull('u.deleted_at'));
	}

	public function testAndOrCombineExpressions(): void
	{
		$expr = $this->createExpressionBuilder();

		$expression = $expr->and(
			$expr->eq('u.id', 1),
			$expr->eq('u.name', 'x')
		);

		$dql = (string) $expression;
		$this->assertStringContainsString('u.id = :qp1', $dql);
		$this->assertStringContainsString('u.name = :qp2', $dql);
		$this->assertStringContainsString('AND', $dql);
	}

	public function testFuncHelper(): void
	{
		$expr = $this->createExpressionBuilder();

		$function = $expr->func('coalesce', $expr->column('u.name'), 'anonymous');

		$this->assertInstanceOf(Func::class, $function);
		$this->assertSame('COALESCE(u.name, :qp1)', (string) $function);
	}

	public function testJsonbContainsHelper(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame(
			'JSONB_CONTAINS(p.value, :qp1) = true',
			(string) $expr->jsonbContains('p.value', ['type' => 'page'])
		);
	}

	public function testCastHelper(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('CAST(u.created_at AS date)', (string) $expr->cast('u.created_at', 'date'));
	}

	public function testRawReturnsRawDql(): void
	{
		$expr = $this->createExpressionBuilder();

		$raw = $expr->raw('u.id = 1');

		$this->assertInstanceOf(\iikiti\CMS\ORM\RawDql::class, $raw);
		$this->assertSame('u.id = 1', (string) $raw);
	}

	public function testColumnHelper(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id', (string) $expr->column('id', 'u'));
	}

	private function createExpressionBuilder(?ParameterBag $parameters = null): \iikiti\CMS\ORM\ExpressionBuilder
	{
		return new \iikiti\CMS\ORM\ExpressionBuilder(
			$this->createStrategy(),
			$parameters ?? new ParameterBag()
		);
	}
}
