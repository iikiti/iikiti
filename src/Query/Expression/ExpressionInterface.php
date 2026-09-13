<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\ParameterBag;

/**
 * Anything that can be rendered as a SQL fragment while carrying its own
 * bound parameters.
 *
 * Expressions are converted to SQL through {@see __toString()}. Values that
 * must not be interpolated into the SQL string are represented as
 * {@see \iikiti\CMS\Query\Parameter} objects and surfaced through
 * {@see getParameters()} so the query builder can bind them.
 */
interface ExpressionInterface extends \Stringable
{
	/**
	 * Render the expression as SQL, using parameter placeholders for values.
	 */
	public function __toString(): string;

	/**
	 * Parameters referenced by this expression.
	 */
	public function getParameters(): ParameterBag;
}
