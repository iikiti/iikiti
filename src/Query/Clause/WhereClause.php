<?php

namespace iikiti\CMS\Query\Clause;

use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\ExpressionInterface;

/**
 * A WHERE clause built from one or more predicates.
 *
 * Predicates are combined with AND when created through {@see and()}, or with
 * OR through {@see or()}. The clause renders as a SQL predicate and carries
 * the parameters of its parts.
 */
final class WhereClause extends AbstractPredicateClause
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
