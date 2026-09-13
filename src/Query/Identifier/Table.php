<?php

namespace iikiti\CMS\Query\Identifier;

/**
 * A validated table reference, optionally schema-qualified.
 */
final class Table implements IdentifierInterface
{
	use ValidatesIdentifier;

	private readonly string $value;

	public function __construct(string $name, ?string $schema = null)
	{
		$candidate = (null !== $schema && '' !== $schema) ? $schema.'.'.$name : $name;
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
}
