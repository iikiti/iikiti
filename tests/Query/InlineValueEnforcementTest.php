<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Exception\InlineValueException;
use PHPUnit\Framework\TestCase;

final class InlineValueEnforcementTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testNumericLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);
		$this->expectExceptionMessageMatches('/42/');

		$qb->select('*')->from('sites')->where('id = 42');
	}

	public function testSingleQuotedStringIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where("name = 'John'");
	}

	public function testDoubleQuotedStringIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('name = "John"');
	}

	public function testOrWhereIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->orWhere('id = 1');
	}

	public function testSetValueIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->update('sites')->set('active', "'true'");
	}

	public function testParameterisedPlaceholderIsAllowed(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where('id = :id');

		$this->assertSame('SELECT * FROM sites WHERE id = :id', $qb->getSQL());
	}

	public function testPlaceholderWithDigitsIsNotMistakenForLiteral(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where('id = :qp12');

		$this->assertStringContainsString(':qp12', $qb->getSQL());
	}

	public function testExpressionBuilderOutputPasses(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where($qb->expr()->eq('id', 42));

		$this->assertSame('SELECT * FROM sites WHERE id = :qp1', $qb->getSQL());
	}

	public function testRawExpressionIsAllowed(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where($qb->expr()->raw('id = 42'));

		$this->assertSame('SELECT * FROM sites WHERE id = 42', $qb->getSQL());
	}

	public function testSafetyCanBeDisabled(): void
	{
		$qb = $this->createQueryBuilder(safetyEnabled: false);
		$qb->select('*')->from('sites')->where('id = 42');

		$this->assertSame('SELECT * FROM sites WHERE id = 42', $qb->getSQL());
	}

	public function testCompositeExpressionWithLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where($qb->expr()->and('id = 1', 'name = :name'));
	}

	public function testDollarQuotedLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('name = $$John$$');
	}

	public function testTaggedDollarQuotedLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('name = $tag$John$tag$');
	}

	public function testScientificNumericLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('id = 1e5');
	}

	public function testHexNumericLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('id = 0x1F');
	}

	public function testDigitSeparatedNumericLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('id = 1_000');
	}

	public function testBooleanLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('active = true');
	}

	public function testNullKeywordLiteralIsRejected(): void
	{
		$qb = $this->createQueryBuilder();

		$this->expectException(InlineValueException::class);

		$qb->select('*')->from('sites')->where('deleted_at = NULL');
	}

	public function testIsNullOperatorIsAllowed(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where('deleted_at IS NULL');

		$this->assertSame('SELECT * FROM sites WHERE deleted_at IS NULL', $qb->getSQL());
	}

	public function testIsNotNullOperatorIsAllowed(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where('published_at IS NOT NULL');

		$this->assertSame('SELECT * FROM sites WHERE published_at IS NOT NULL', $qb->getSQL());
	}

	public function testLikeWithEscapeCharIsParameterised(): void
	{
		$qb = $this->createQueryBuilder();
		$qb->select('*')->from('sites')->where($qb->expr()->like('name', 'A\\%', '\\'));

		$this->assertStringContainsString('LIKE :qp1 ESCAPE :qp2', $qb->getSQL());
		$this->assertSame(['qp1' => 'A\\%', 'qp2' => '\\'], $qb->getParameters());
	}
}
