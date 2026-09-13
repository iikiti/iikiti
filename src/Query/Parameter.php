<?php

namespace iikiti\CMS\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use iikiti\CMS\Query\Expression\ExpressionInterface;

/**
 * A single bound query parameter.
 *
 * The name is assigned lazily by {@see ParameterBag} so that parameters minted
 * by any expression builder receive a process-unique name and can be merged
 * into a query without collisions.
 */
final class Parameter implements ExpressionInterface
{
	private ?string $name = null;

	public function __construct(
		private readonly mixed $value,
		private readonly string|ParameterType|ArrayParameterType $type = ParameterType::STRING,
	) {
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function setName(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getType(): string|ParameterType|ArrayParameterType
	{
		return $this->type;
	}

	/**
	 * The placeholder (including the leading colon) for this parameter.
	 *
	 * @throws \LogicException when the parameter has not been named yet
	 */
	public function getPlaceholder(): string
	{
		if (null === $this->name) {
			throw new \LogicException('Parameter has not been assigned a name yet.');
		}

		return ':'.$this->name;
	}

	public function __toString(): string
	{
		return $this->getPlaceholder();
	}

	public function getParameters(): ParameterBag
	{
		$bag = new ParameterBag();
		$bag->add($this);

		return $bag;
	}
}
