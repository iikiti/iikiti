<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\ParameterBag;

/**
 * An explicitly trusted SQL fragment.
 *
 * Raw expressions bypass the inline-value safety scan. They exist so that
 * database-specific constructs that cannot be expressed through the builder
 * remain possible, but their use is discouraged: prefer the typed expression
 * builder, which binds values as parameters.
 */
final class RawExpression implements ExpressionInterface
{
	public function __construct(
		private readonly string $sql,
		private readonly ParameterBag $parameters = new ParameterBag(),
	) {
	}

	public function getSql(): string
	{
		return $this->sql;
	}

	public function __toString(): string
	{
		return $this->sql;
	}

	public function getParameters(): ParameterBag
	{
		return $this->parameters;
	}
}
