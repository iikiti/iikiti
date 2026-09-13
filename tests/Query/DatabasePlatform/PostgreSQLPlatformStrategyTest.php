<?php

namespace iikiti\CMS\Tests\Query\DatabasePlatform;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Operator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgreSQLPlatformStrategyTest extends TestCase
{
	private PostgreSQLPlatformStrategy $strategy;

	protected function setUp(): void
	{
		$this->strategy = new PostgreSQLPlatformStrategy();
	}

	public function testMetadata(): void
	{
		$this->assertSame('postgresql', $this->strategy->getName());
		$this->assertSame('PostgreSQL', $this->strategy->getLabel());
	}

	public function testSupportsPostgreSqlPlatformOnly(): void
	{
		$this->assertTrue($this->strategy->supportsPlatform(new PostgreSQLPlatform()));
		$this->assertFalse($this->strategy->supportsPlatform(new SQLitePlatform()));
	}

	#[DataProvider('operatorProvider')]
	public function testResolvesOperators(Operator $operator, string $expected): void
	{
		$this->assertSame($expected, $this->strategy->resolveOperator($operator));
	}

	/**
	 * @return iterable<string,array{Operator,string}>
	 */
	public static function operatorProvider(): iterable
	{
		yield 'eq' => [Operator::EQ, '='];
		yield 'neq' => [Operator::NEQ, '<>'];
		yield 'regex' => [Operator::REGEX, '~'];
		yield 'iregex' => [Operator::IREGEX, '~*'];
		yield 'jsonExtract' => [Operator::JSON_EXTRACT, '->'];
		yield 'jsonGetText' => [Operator::JSON_GET_TEXT, '->>'];
		yield 'jsonContains' => [Operator::JSON_CONTAINS, '@>'];
		yield 'ftsMatch' => [Operator::FTS_MATCH, '@@'];
		yield 'arrayContains' => [Operator::ARRAY_CONTAINS, '@>'];
		yield 'arrayOverlaps' => [Operator::ARRAY_OVERLAPS, '&&'];
	}

	public function testResolvesFunctions(): void
	{
		$this->assertSame('COUNT', $this->strategy->resolveFunction('count'));
		$this->assertSame('DATE_TRUNC', $this->strategy->resolveFunction('dateTrunc'));
		$this->assertSame('JSONB_BUILD_OBJECT', $this->strategy->resolveFunction('jsonBuildObject'));
	}

	public function testUnknownFunctionThrows(): void
	{
		$this->expectException(UnsupportedFeatureException::class);

		$this->strategy->resolveFunction('nope');
	}

	public function testQuotesIdentifiers(): void
	{
		$this->assertSame('"objects"', $this->strategy->quoteIdentifier('objects'));
		$this->assertSame('"we""ird"', $this->strategy->quoteIdentifier('we"ird'));
	}

	public function testRendersCast(): void
	{
		$this->assertSame('(created_at)::date', $this->strategy->renderCast('created_at', 'date'));
	}

	public function testFeatureFlags(): void
	{
		$this->assertTrue($this->strategy->supportsCte());
		$this->assertTrue($this->strategy->supportsCte(true));
		$this->assertTrue($this->strategy->supportsUnion());
		$this->assertTrue($this->strategy->supportsReturning());
		$this->assertTrue($this->strategy->supportsUpsert());
	}
}
