<?php

namespace iikiti\CMS\Query\Clause;

use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\ExpressionInterface;
use iikiti\CMS\Query\ParameterBag;

/**
 * Shared behaviour for predicate clauses (WHERE and HAVING).
 *
 * Predicate clauses wrap one or more predicate expressions and render them
 * combined with AND or OR, carrying the parameters of their parts.
 */
abstract class AbstractPredicateClause implements ExpressionInterface
{
	/**
	 * @param list<ExpressionInterface> $predicates
	 */
	final protected function __construct(
		private readonly array $predicates,
		private readonly string $type,
	) {
	}

	/**
	 * @param list<ExpressionInterface> $predicates
	 */
	protected static function create(array $predicates, string $type): static
	{
		return new static($predicates, $type);
	}

	public function __toString(): string
	{
		if ([] === $this->predicates) {
			return '1 = 1';
		}

		return (string) $this->composite();
	}

	public function getParameters(): ParameterBag
	{
		if ([] === $this->predicates) {
			return new ParameterBag();
		}

		return $this->composite()->getParameters();
	}

	private function composite(): CompositeExpression
	{
		return CompositeExpression::TYPE_OR === $this->type ?
			CompositeExpression::or(...$this->predicates) :
			CompositeExpression::and(...$this->predicates);
	}
}
