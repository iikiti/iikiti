<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\ParameterBag;

/**
 * A generic SQL function call.
 *
 * The function name is an abstract catalog key (for example `coalesce` or
 * `dateTrunc`) resolved by the active platform strategy. Scalar arguments are
 * wrapped as bound parameters before construction, so a function expression
 * never contains an inline literal.
 */
final class FunctionExpression implements ExpressionInterface
{
	/**
	 * @param list<ExpressionInterface> $arguments
	 */
	public function __construct(
		private readonly string $name,
		private readonly array $arguments,
		private readonly DatabasePlatformStrategyInterface $platform,
	) {
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return list<ExpressionInterface>
	 */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public function __toString(): string
	{
		$arguments = array_map(
			static fn (ExpressionInterface $argument): string => (string) $argument,
			$this->arguments
		);

		return sprintf('%s(%s)', $this->platform->resolveFunction($this->name), implode(', ', $arguments));
	}

	public function getParameters(): ParameterBag
	{
		$bag = new ParameterBag();
		foreach ($this->arguments as $argument) {
			$bag->merge($argument->getParameters());
		}

		return $bag;
	}
}
