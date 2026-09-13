<?php

namespace iikiti\CMS\Query\Exception;

/**
 * Thrown when an expression string contains an inline literal value.
 *
 * The query builder requires values to be bound as query parameters. When a
 * literal (a quoted string, a bare number, or a boolean/NULL keyword) is
 * detected in an expression that was not produced by this library, the builder
 * refuses to build the SQL. Use the expression builder or `raw()` to opt out.
 */
class InlineValueException extends QueryBuilderException
{
	public static function forExpression(string $expression, string $literal): self
	{
		return new self(sprintf(
			'Inline value %s detected in expression "%s". '.
			'Bind the value as a query parameter using the expression builder, '.
			'or wrap trusted SQL in %s.',
			$literal,
			$expression,
			'QueryBuilder::raw()'
		));
	}
}
