<?php

namespace iikiti\CMS\Tests\Query\Expression;

use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Expression\FunctionExpression;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;
use iikiti\CMS\Tests\Query\CreatesQueryBuilders;
use PHPUnit\Framework\TestCase;

final class FunctionExpressionTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testRendersFunctionOverColumn(): void
	{
		$expression = new FunctionExpression('lower', [new Column('u.name')], $this->createStrategy());

		$this->assertSame('LOWER(u.name)', (string) $expression);
		$this->assertTrue($expression->getParameters()->isEmpty());
	}

	public function testBindsScalarArguments(): void
	{
		$bag = new ParameterBag();
		$expression = new FunctionExpression(
			'coalesce',
			[new Column('u.name'), $bag->add(new Parameter('anonymous'))],
			$this->createStrategy()
		);

		$this->assertSame('COALESCE(u.name, :qp1)', (string) $expression);
		$this->assertCount(1, $expression->getParameters()->all());
	}

	public function testUnknownFunctionThrows(): void
	{
		$this->expectException(UnsupportedFeatureException::class);

		(string) new FunctionExpression('doesNotExist', [], $this->createStrategy());
	}
}
