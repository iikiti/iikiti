<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Operator;
use iikiti\CMS\Query\ParameterBag;

/**
 * A postfix null check (`IS NULL` / `IS NOT NULL`).
 */
final class NullCheckExpression implements ExpressionInterface
{
	public function __construct(
		private readonly ExpressionInterface $expression,
		private readonly bool $negated,
		private readonly DatabasePlatformStrategyInterface $platform,
	) {
	}

	public function __toString(): string
	{
		$operator = $this->negated ? Operator::IS_NOT_NULL : Operator::IS_NULL;

		return sprintf('%s %s', $this->expression, $this->platform->resolveOperator($operator));
	}

	public function getParameters(): ParameterBag
	{
		return $this->expression->getParameters();
	}
}
