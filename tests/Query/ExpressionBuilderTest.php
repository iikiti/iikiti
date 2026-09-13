<?php

namespace iikiti\CMS\Tests\Query;

use Doctrine\DBAL\ArrayParameterType;
use iikiti\CMS\Query\Expression\CastExpression;
use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\FunctionExpression;
use iikiti\CMS\Query\Expression\RawExpression;
use iikiti\CMS\Query\ParameterBag;
use PHPUnit\Framework\TestCase;

final class ExpressionBuilderTest extends TestCase
{
	use CreatesQueryBuilders;

	public function testComparisonHelpersBindValues(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id = :qp1', $expr->eq('u.id', 5));
		$this->assertSame('u.id <> :qp2', $expr->neq('u.id', 5));
		$this->assertSame('u.id < :qp3', $expr->lt('u.id', 5));
		$this->assertSame('u.id <= :qp4', $expr->lte('u.id', 5));
		$this->assertSame('u.id > :qp5', $expr->gt('u.id', 5));
		$this->assertSame('u.id >= :qp6', $expr->gte('u.id', 5));
	}

	public function testNullHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.deleted_at IS NULL', $expr->isNull('u.deleted_at'));
		$this->assertSame('u.deleted_at IS NOT NULL', $expr->isNotNull('u.deleted_at'));
	}

	public function testLikeHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.name LIKE :qp1', $expr->like('u.name', 'A%'));
		$this->assertSame('u.name NOT LIKE :qp2', $expr->notLike('u.name', 'A%'));
		$this->assertSame('u.name LIKE :qp3 ESCAPE :qp4', $expr->like('u.name', 'A\\%', '\\'));
		$this->assertSame('\\', $expr->getParameterBag()->get('qp4')?->getValue());
	}

	public function testInWithArrayParameter(): void
	{
		$parameters = new ParameterBag();
		$expr = $this->createExpressionBuilder(parameters: $parameters);

		$this->assertSame('u.id IN (:qp1)', $expr->in('u.id', [1, 2, 3]));
		$this->assertSame(ArrayParameterType::INTEGER, $parameters->get('qp1')?->getType());

		$this->assertSame('u.name IN (:qp2)', $expr->in('u.name', ['a', 'b']));
		$this->assertSame(ArrayParameterType::STRING, $parameters->get('qp2')?->getType());
	}

	public function testInWithSingleValueAndSubquery(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id IN (:qp1)', $expr->in('u.id', 1));
		$this->assertSame('u.id NOT IN (:qp2)', $expr->notIn('u.id', 1));
		$this->assertSame('u.id IN (SELECT id FROM x)', $expr->in('u.id', new RawExpression('SELECT id FROM x')));
	}

	public function testRegexHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.slug ~ :qp1', $expr->regex('u.slug', '^news-'));
		$this->assertSame('u.slug !~ :qp2', $expr->notRegex('u.slug', '^news-'));
		$this->assertSame('u.slug ~* :qp3', $expr->iregex('u.slug', '^NEWS-'));
		$this->assertSame('u.slug !~* :qp4', $expr->notIregex('u.slug', '^NEWS-'));
	}

	public function testJsonHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.data -> :qp1', $expr->jsonExtract('u.data', 'key'));
		$this->assertSame('u.data ->> :qp2', $expr->jsonGetText('u.data', 'key'));
		$this->assertSame('u.data @> (:qp3)::jsonb', $expr->jsonContains('u.data', ['a' => 1]));
	}

	public function testFullTextSearch(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.content @@ PLAINTO_TSQUERY(:qp1)', $expr->ftsMatch('u.content', 'hello'));
		$this->assertSame(
			'u.content @@ WEBSEARCH_TO_TSQUERY(:qp2)',
			$expr->ftsMatch('u.content', 'hello', 'websearchToTsquery')
		);
	}

	public function testArrayHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.tags @> :qp1', $expr->arrayContains('u.tags', ['a']));
		$this->assertSame('u.tags && :qp2', $expr->arrayOverlaps('u.tags', ['a']));
	}

	public function testCastHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('(u.created_at)::date', $expr->cast('u.created_at', 'date'));

		$cast = $expr->castExpression('u.created_at', 'numeric(10,2)');
		$this->assertInstanceOf(CastExpression::class, $cast);
		$this->assertSame('(u.created_at)::numeric(10,2)', (string) $cast);
	}

	public function testFunctionHelper(): void
	{
		$expr = $this->createExpressionBuilder();

		$function = $expr->func('coalesce', $expr->column('u.name'), 'anonymous');

		$this->assertInstanceOf(FunctionExpression::class, $function);
		$this->assertSame('COALESCE(u.name, :qp1)', (string) $function);
	}

	public function testRawAndLiteralHelpers(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertInstanceOf(RawExpression::class, $expr->raw('1 = 1'));
		$this->assertSame('1 = 1', (string) $expr->raw('1 = 1'));
		$this->assertSame("'hello'", (string) $expr->literalExpression('hello'));
	}

	public function testCombinators(): void
	{
		$expr = $this->createExpressionBuilder();

		$composite = $expr->conjunction(
			$expr->condition($expr->column('a'), \iikiti\CMS\Query\Operator::EQ, 1),
			$expr->condition($expr->column('b'), \iikiti\CMS\Query\Operator::EQ, 2)
		);

		$this->assertInstanceOf(CompositeExpression::class, $composite);
		$this->assertSame('(a = :qp1) AND (b = :qp2)', (string) $composite);
		$this->assertSame('(a = :qp3) OR (b = :qp4)', (string) $expr->disjunction(
			$expr->condition($expr->column('a'), \iikiti\CMS\Query\Operator::EQ, 1),
			$expr->condition($expr->column('b'), \iikiti\CMS\Query\Operator::EQ, 2)
		));
	}

	public function testIdentifierFactories(): void
	{
		$expr = $this->createExpressionBuilder();

		$this->assertSame('u.id', (string) $expr->column('id', 'u'));
		$this->assertSame('public.objects', (string) $expr->table('objects', 'public'));
		$this->assertSame('u', (string) $expr->alias('u'));
	}
}
