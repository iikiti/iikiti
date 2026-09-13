<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\ParameterBag;

/**
 * A logical combination of expressions, joined with AND or OR.
 */
final class CompositeExpression implements ExpressionInterface
{
	public const TYPE_AND = 'AND';
	public const TYPE_OR = 'OR';

	/**
	 * @param list<ExpressionInterface> $parts
	 */
	private function __construct(
		private readonly string $type,
		private readonly array $parts,
	) {
	}

	public static function and(ExpressionInterface ...$parts): self
	{
		return new self(self::TYPE_AND, array_values($parts));
	}

	public static function or(ExpressionInterface ...$parts): self
	{
		return new self(self::TYPE_OR, array_values($parts));
	}

	public function getType(): string
	{
		return $this->type;
	}

	/**
	 * @return list<ExpressionInterface>
	 */
	public function getParts(): array
	{
		return $this->parts;
	}

	public function __toString(): string
	{
		if (1 === count($this->parts)) {
			return (string) $this->parts[0];
		}

		return '('.implode(') '.$this->type.' (', $this->parts).')';
	}

	public function getParameters(): ParameterBag
	{
		$bag = new ParameterBag();
		foreach ($this->parts as $part) {
			$bag->merge($part->getParameters());
		}

		return $bag;
	}
}
