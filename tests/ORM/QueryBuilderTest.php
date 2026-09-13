<?php

namespace iikiti\CMS\Tests\ORM;

use iikiti\CMS\Query\Exception\IdentifierValidationException;
use iikiti\CMS\Query\Exception\InlineValueException;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
	use CreatesOrmQueryBuilders;

	public function testSelectFromWhereRendersDqlWithBoundParameter(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s')->from('sites', 's')->
			where($qb->expr()->eq('s.domain', 'example.com'));

		$this->assertStringContainsString('s.domain = :qp1', $qb->getDQL());
		$this->assertSame('example.com', $qb->getParameterBag()->get('qp1')->getValue());
	}

	public function testCriteriaValueIsBoundNotInlined(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s')->from('sites', 's')->
			where($qb->expr()->in('s.id', [1, 2, 3]));

		$this->assertStringContainsString('s.id IN(:qp1)', $qb->getDQL());
		$this->assertSame([1, 2, 3], $qb->getParameterBag()->get('qp1')->getValue());
	}

	public function testInlineValueInWhereIsRejected(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s')->from('sites', 's');

		$this->expectException(InlineValueException::class);

		$qb->where('s.id = 42');
	}

	public function testUnsafeSetValueIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->set('s.active', "'true'");
	}

	public function testSafetyToggle(): void
	{
		$qb = $this->createQueryBuilder();

		$this->assertTrue($qb->isSafetyEnabled());
		$qb->disableSafety();
		$this->assertFalse($qb->isSafetyEnabled());
	}

	public function testRawDqlBypassesSafety(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s')->from('sites', 's')->
			where($qb->expr()->raw('s.id = 42'));

		$this->assertStringContainsString('s.id = 42', $qb->getDQL());
	}

	public function testColumnAliasIsValidatedInPredicate(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('s')->from('sites', 's');

		$this->expectException(IdentifierValidationException::class);

		$qb->where($qb->expr()->eq('bad field', 1));
	}
}
