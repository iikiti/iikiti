<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Exception\UnsafeExpressionException;
use iikiti\CMS\Query\ParameterBag;

/**
 * A value type cast, rendered by the active platform strategy.
 *
 * The target type is validated so that a cast can not be used to inject
 * arbitrary SQL.
 */
final class CastExpression implements ExpressionInterface
{
	private const TYPE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_ ]*(?:\(\d+(?:\s*,\s*\d+)?\))?$/';

	public function __construct(
		private readonly ExpressionInterface $expression,
		private readonly string $type,
		private readonly DatabasePlatformStrategyInterface $platform,
	) {
		if (1 !== preg_match(self::TYPE_PATTERN, $type)) {
			throw new UnsafeExpressionException(sprintf('"%s" is not a valid cast target type.', $type));
		}
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function __toString(): string
	{
		return $this->platform->renderCast((string) $this->expression, $this->type);
	}

	public function getParameters(): ParameterBag
	{
		return $this->expression->getParameters();
	}
}
