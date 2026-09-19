<?php

namespace iikiti\CMS\Tests\Search\Expression;

use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\Expression\RawExpression;
use iikiti\CMS\Query\ExpressionBuilder;
use iikiti\CMS\Query\ParameterBag;
use iikiti\CMS\Search\Expression\SearchExpressionBuilder;
use PHPUnit\Framework\TestCase;

final class SearchExpressionBuilderTest extends TestCase
{
	private SearchExpressionBuilder $builder;

	protected function setUp(): void
	{
		$strategy = new PostgreSQLPlatformStrategy();
		$connection = $this->createStub(\Doctrine\DBAL\Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(new \Doctrine\DBAL\Platforms\PostgreSQLPlatform());
		$connection->method('quote')->willReturnCallback(
			static fn (string $v): string => "'".str_replace("'", "''", $v)."'"
		);

		$expr = new ExpressionBuilder($connection, $strategy, new ParameterBag());
		$this->builder = new SearchExpressionBuilder($expr, $strategy);
	}

	public function testToTsVectorSingleField(): void
	{
		$expr = $this->builder->toTsVector([
			['weight' => 1, 'column' => 'o.title'],
		]);

		self::assertInstanceOf(RawExpression::class, $expr);
		$sql = (string) $expr;
		self::assertStringContainsString('setweight', $sql);
		self::assertStringContainsString("to_tsvector('english', COALESCE(o.title::text, ''))", $sql);
		self::assertStringContainsString("'A'", $sql);
	}

	public function testToTsVectorMultipleFieldsWithWeights(): void
	{
		$expr = $this->builder->toTsVector([
			['weight' => 1, 'column' => 'o.title::text'],
			['weight' => 2, 'column' => 'o.content::text'],
		]);

		$sql = (string) $expr;
		self::assertStringContainsString("'A'", $sql);
		self::assertStringContainsString("'B'", $sql);
		self::assertStringContainsString(' || ', $sql);
	}

	public function testToTsVectorEmptyFields(): void
	{
		$expr = $this->builder->toTsVector([]);

		self::assertInstanceOf(RawExpression::class, $expr);
		self::assertStringContainsString("to_tsvector('english', '')", (string) $expr);
	}

	public function testTsRank(): void
	{
		$expr = $this->builder->tsRank('search_vector', 'tsquery');

		self::assertStringContainsString('TS_RANK', $expr);
		self::assertStringContainsString('search_vector', $expr);
		self::assertStringContainsString('tsquery', $expr);
	}

	public function testTsHeadline(): void
	{
		$expr = $this->builder->tsHeadline('title', 'tsquery', 'english');

		self::assertStringContainsString('TS_HEADLINE', $expr);
		self::assertStringContainsString('title', $expr);
		self::assertStringContainsString('tsquery', $expr);
	}

	public function testTsHeadlineWithOptions(): void
	{
		$expr = $this->builder->tsHeadline('title', 'tsquery', 'english', ['MaxWords' => 10]);

		self::assertStringContainsString('MaxWords=10', $expr);
	}

	public function testConcatTsVectors(): void
	{
		$result = $this->builder->concatTsVectors('a', 'b', 'c');

		self::assertSame('a || b || c', $result);
	}

	public function testGetExpressionBuilder(): void
	{
		self::assertInstanceOf(ExpressionBuilder::class, $this->builder->getExpressionBuilder());
	}
}
