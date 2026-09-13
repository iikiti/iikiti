<?php

namespace iikiti\CMS\Tests\Query\Expression;

use iikiti\CMS\Query\Expression\NullCheckExpression;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Tests\Query\CreatesQueryBuilders;
use PHPUnit\Framework\TestCase;

final class NullCheckExpressionTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testIsNull(): void
	{
		$expression = new NullCheckExpression(new Column('deleted_at'), false, $this->createStrategy());

		$this->assertSame('deleted_at IS NULL', (string) $expression);
		$this->assertTrue($expression->getParameters()->isEmpty());
	}

	public function testIsNotNull(): void
	{
		$expression = new NullCheckExpression(new Column('published_at'), true, $this->createStrategy());

		$this->assertSame('published_at IS NOT NULL', (string) $expression);
	}
}
