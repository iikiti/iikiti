<?php

namespace iikiti\CMS\Tests\Query;

use Doctrine\DBAL\Query\UnionType;
use iikiti\CMS\Query\UnionQueryPart;
use PHPUnit\Framework\TestCase;

final class UnionQueryPartTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testDefaultsToDistinct(): void
	{
		$part = new UnionQueryPart('SELECT 1');

		$this->assertSame('SELECT 1', $part->getQuerySql());
		$this->assertSame(UnionType::DISTINCT, $part->type);
		$this->assertTrue($part->getParameters()->isEmpty());
	}

	public function testAllType(): void
	{
		$part = new UnionQueryPart('SELECT 1', UnionType::ALL);

		$this->assertSame(UnionType::ALL, $part->type);
	}

	public function testBuilderBackedPartCarriesParameters(): void
	{
		$builder = $this->createQueryBuilder();
		$builder->select('id')->from('sites')->where($builder->expr()->eq('id', 3));

		$part = new UnionQueryPart($builder);

		$this->assertStringContainsString('SELECT id FROM sites', $part->getQuerySql());
		$this->assertCount(1, $part->getParameters()->all());
	}
}
