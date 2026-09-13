<?php

namespace iikiti\CMS\Query\Clause;

use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\ExpressionInterface;

/**
 * A HAVING clause built from one or more aggregate predicates.
 *
 * Predicates are combined with AND when created through {@see and()}, or with
 * OR through {@see or()}.
 */
final class HavingClause extends AbstractPredicateClause
{
	public static function and(ExpressionInterface ...$predicates): self
	{
		return static::create(array_values($predicates), CompositeExpression::TYPE_AND);
	}

	public static function or(ExpressionInterface ...$predicates): self
	{
		return static::create(array_values($predicates), CompositeExpression::TYPE_OR);
	}
}
