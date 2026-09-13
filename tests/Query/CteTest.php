<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Cte;
use iikiti\CMS\Query\Exception\CteException;
use PHPUnit\Framework\TestCase;

final class CteTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testWithRawSql(): void
	{
		$cte = new Cte('active', 'SELECT id FROM sites');

		$this->assertSame('active', $cte->name);
		$this->assertSame('SELECT id FROM sites', $cte->getQuerySql());
		$this->assertTrue($cte->getParameters()->isEmpty());
	}

	public function testWithQueryBuilderCapturesSqlAndParameters(): void
	{
		$builder = $this->createQueryBuilder();
		$builder->select('id')->from('sites')->where($builder->expr()->eq('id', 5));

		$cte = new Cte('active', $builder, ['id']);

		$this->assertSame('SELECT id FROM sites WHERE id = :qp1', $cte->getQuerySql());
		$this->assertCount(1, $cte->getParameters()->all());
	}

	public function testRejectsInvalidName(): void
	{
		$this->expectException(CteException::class);

		new Cte('bad name', 'SELECT 1');
	}

	public function testRejectsInvalidColumnName(): void
	{
		$this->expectException(CteException::class);

		new Cte('active', 'SELECT 1', ['bad;column']);
	}
}
