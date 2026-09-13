<?php

namespace iikiti\CMS\Tests\Query\Expression;

use iikiti\CMS\Query\Expression\Comparison;
use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\NullCheckExpression;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Operator;
use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;
use iikiti\CMS\Tests\Query\CreatesQueryBuilders;
use PHPUnit\Framework\TestCase;

final class CompositeExpressionTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testAndRendering(): void
	{
		$bag = new ParameterBag();
		$strategy = $this->createStrategy();
		$left = new Comparison(new Column('a'), Operator::EQ, $bag->add(new Parameter(1)), $strategy);
		$right = new Comparison(new Column('b'), Operator::EQ, $bag->add(new Parameter(2)), $strategy);

		$composite = CompositeExpression::and($left, $right);

		$this->assertSame('(a = :qp1) AND (b = :qp2)', (string) $composite);
		$this->assertSame(CompositeExpression::TYPE_AND, $composite->getType());
		$this->assertCount(2, $composite->getParameters()->all());
	}

	public function testOrRendering(): void
	{
		$strategy = $this->createStrategy();
		$left = new NullCheckExpression(new Column('a'), false, $strategy);
		$right = new NullCheckExpression(new Column('b'), false, $strategy);

		$composite = CompositeExpression::or($left, $right);

		$this->assertSame('(a IS NULL) OR (b IS NULL)', (string) $composite);
		$this->assertSame(CompositeExpression::TYPE_OR, $composite->getType());
	}

	public function testSinglePartIsNotWrapped(): void
	{
		$expression = new NullCheckExpression(new Column('a'), false, $this->createStrategy());

		$this->assertSame('a IS NULL', (string) CompositeExpression::and($expression));
	}
}
