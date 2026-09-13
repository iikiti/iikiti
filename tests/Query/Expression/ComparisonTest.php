<?php

namespace iikiti\CMS\Tests\Query\Expression;

use iikiti\CMS\Query\Exception\UnsafeExpressionException;
use iikiti\CMS\Query\Expression\Comparison;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Operator;
use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;
use iikiti\CMS\Tests\Query\CreatesQueryBuilders;
use PHPUnit\Framework\TestCase;

final class ComparisonTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testRendersWithBoundParameter(): void
	{
		$bag = new ParameterBag();
		$parameter = $bag->add(new Parameter(5));

		$comparison = new Comparison(new Column('u.id'), Operator::EQ, $parameter, $this->createStrategy());

		$this->assertSame('u.id = :qp1', (string) $comparison);
		$this->assertSame($parameter, $comparison->getParameters()->get('qp1'));
	}

	public function testRendersAbstractOperators(): void
	{
		$bag = new ParameterBag();
		$strategy = $this->createStrategy();

		$cases = [
			[Operator::NEQ, '<>'],
			[Operator::LT, '<'],
			[Operator::LTE, '<='],
			[Operator::GT, '>'],
			[Operator::GTE, '>='],
			[Operator::REGEX, '~'],
			[Operator::IREGEX, '~*'],
			[Operator::JSON_GET_TEXT, '->>'],
		];

		$index = 1;
		foreach ($cases as [$operator, $sqlOperator]) {
			$comparison = new Comparison(
				new Column('c'),
				$operator,
				$bag->add(new Parameter('v')),
				$strategy
			);
			$this->assertSame(sprintf('c %s :qp%d', $sqlOperator, $index), (string) $comparison);
			++$index;
		}
	}

	public function testRejectsPostfixOperator(): void
	{
		$this->expectException(UnsafeExpressionException::class);

		new Comparison(new Column('c'), Operator::IS_NULL, new Parameter(null), $this->createStrategy());
	}
}
