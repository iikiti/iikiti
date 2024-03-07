<?php

namespace iikiti\CMS\Enum;

/**
 * Class that stores a dynamically generated case.
 */
class EnumCase
{
	public function __construct(public string $name, public int|string $value)
	{
	}

	public function __toString(): string
	{
		return (string) $this->value;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getValue(): int|string
	{
		return $this->value;
	}
}
