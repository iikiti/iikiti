<?php

namespace iikiti\CMS\Query\Identifier;

use iikiti\CMS\Query\Expression\ExpressionInterface;
use iikiti\CMS\Query\ParameterBag;

/**
 * A validated, optionally table- or alias-qualified column reference.
 *
 * Columns double as expressions: they render as their (qualified) name and
 * carry no parameters. Column names are validated on construction, so a value
 * can never escape its position in a query.
 */
final class Column implements IdentifierInterface, ExpressionInterface
{
	use ValidatesIdentifier;

	private readonly string $value;

	public function __construct(string $name, ?string $qualifier = null)
	{
		$candidate = (null !== $qualifier && '' !== $qualifier) ? $qualifier.'.'.$name : $name;
		self::assertValid($candidate);
		$this->value = $candidate;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}

	public function getParameters(): ParameterBag
	{
		return new ParameterBag();
	}
}
