<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Cte;
use iikiti\CMS\Query\CteSet;
use iikiti\CMS\Query\Exception\CteException;
use PHPUnit\Framework\TestCase;

final class CteSetTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testOrderedSet(): void
	{
		$set = new CteSet([
			new Cte('first', 'SELECT id FROM a'),
			new Cte('second', 'SELECT id FROM first'),
		]);

		$set->validate();

		$this->assertCount(2, $set->all());
		$this->assertFalse($set->isEmpty());
		$this->assertFalse($set->isRecursive());
	}

	public function testDuplicateNameThrows(): void
	{
		$this->expectException(CteException::class);

		new CteSet([
			new Cte('dup', 'SELECT 1'),
			new Cte('dup', 'SELECT 2'),
		]);
	}

	public function testForwardReferenceThrows(): void
	{
		$set = new CteSet([
			new Cte('first', 'SELECT id FROM second'),
			new Cte('second', 'SELECT id FROM a'),
		]);

		$this->expectException(CteException::class);

		$set->validate();
	}

	public function testColumnNamedLikeLaterCteDoesNotTriggerForwardReference(): void
	{
		$set = new CteSet([
			new Cte('first', 'SELECT second FROM a'),
			new Cte('second', 'SELECT id FROM b'),
		]);

		$set->validate();

		$this->assertCount(2, $set->all());
	}

	public function testRecursiveFlag(): void
	{
		$set = new CteSet([new Cte('tree', 'SELECT 1', null, true)]);

		$this->assertTrue($set->isRecursive());
	}

	public function testMergesParameters(): void
	{
		$builder = $this->createQueryBuilder();
		$builder->select('id')->from('sites')->where($builder->expr()->eq('id', 7));

		$set = new CteSet([new Cte('active', $builder)]);

		$this->assertCount(1, $set->getParameters()->all());
	}
}
