<?php

namespace iikiti\CMS\Query\Expression;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Exception\UnsafeExpressionException;
use iikiti\CMS\Query\Operator;
use iikiti\CMS\Query\ParameterBag;

/**
 * A binary operation between a left expression and a right expression.
 *
 * The operator is stored abstractly and translated to SQL by the active
 * platform strategy, so the same comparison works across databases.
 */
final class Comparison implements ExpressionInterface
{
	public function __construct(
		private readonly ExpressionInterface $left,
		private readonly Operator $operator,
		private readonly ExpressionInterface $right,
		private readonly DatabasePlatformStrategyInterface $platform,
	) {
		if (!$operator->isBinary()) {
			throw new UnsafeExpressionException(sprintf('Operator "%s" is not a binary operator and cannot be used in a comparison.', $operator->value));
		}
	}

	public function getLeft(): ExpressionInterface
	{
		return $this->left;
	}

	public function getOperator(): Operator
	{
		return $this->operator;
	}

	public function getRight(): ExpressionInterface
	{
		return $this->right;
	}

	public function __toString(): string
	{
		return sprintf(
			'%s %s %s',
			$this->left,
			$this->platform->resolveOperator($this->operator),
			$this->right
		);
	}

	public function getParameters(): ParameterBag
	{
		$bag = $this->left->getParameters();
		$bag->merge($this->right->getParameters());

		return $bag;
	}
}
