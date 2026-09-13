<?php

namespace iikiti\CMS\Query\Identifier;

use iikiti\CMS\Query\Exception\IdentifierValidationException;

/**
 * A validated, unqualified SQL alias.
 *
 * Aliases are intentionally restricted to a single identifier part: a table
 * alias may not itself be qualified.
 */
final class Alias implements IdentifierInterface
{
	use ValidatesIdentifier;

	private readonly string $value;

	public function __construct(string $name)
	{
		if (str_contains($name, '.')) {
			throw new IdentifierValidationException(sprintf('"%s" is not a valid SQL alias. Aliases may not be qualified with a dot.', $name));
		}
		self::assertValid($name);
		$this->value = $name;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
