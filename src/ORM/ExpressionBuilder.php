<?php

namespace iikiti\CMS\ORM;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Query\Expr\Orx;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Exception\UnsafeExpressionException;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;

/**
 * DQL-flavoured expression builder.
 *
 * Extends Doctrine's ORM expression builder (so every inherited helper such as
 * `andX`/`orX`/`literal`/`between` remains available) but overrides the
 * comparison helpers so scalar values are bound as parameters instead of being
 * inlined. That mirrors the safety contract of the DBAL
 * {@see \iikiti\CMS\Query\ExpressionBuilder}.
 *
 * Only the DQL-portable predicate set is overridden. PostgreSQL-specific
 * operators (regular-expression match, full-text search, array operators and
 * the JSON `->`/`->>`/`@>` operators) have no DQL equivalent; use the DBAL
 * query builder for those.
 */
final class ExpressionBuilder extends Expr
{
	public function __construct(
		private readonly DatabasePlatformStrategyInterface $platform,
		private readonly ParameterBag $parameters,
	) {
	}

	public function getPlatform(): DatabasePlatformStrategyInterface
	{
		return $this->platform;
	}

	public function getParameterBag(): ParameterBag
	{
		return $this->parameters;
	}

	/**
	 * Bind a value and return the resulting parameter.
	 */
	public function param(
		mixed $value,
		ParameterType|ArrayParameterType|string $type = ParameterType::STRING,
	): Parameter {
		return $this->parameters->add(new Parameter($value, $type));
	}

	/**
	 * Create a column reference.
	 */
	public function column(string $name, ?string $qualifier = null): Column
	{
		return new Column($name, $qualifier);
	}

	public function eq(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::EQ, $y);
	}

	public function neq(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::NEQ, $y);
	}

	public function lt(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::LT, $y);
	}

	public function lte(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::LTE, $y);
	}

	public function gt(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::GT, $y);
	}

	public function gte(mixed $x, mixed $y): Comparison
	{
		return $this->comparison($x, Comparison::GTE, $y);
	}

	public function isNull(string|Column $x): string
	{
		return $this->leftExpression($x).' IS NULL';
	}

	public function isNotNull(string|Column $x): string
	{
		return $this->leftExpression($x).' IS NOT NULL';
	}

	public function like(string $x, mixed $y): Comparison
	{
		return $this->comparison($x, 'LIKE', $y);
	}

	public function notLike(string $x, mixed $y): Comparison
	{
		return $this->comparison($x, 'NOT LIKE', $y);
	}

	public function in(string $x, mixed $y): Func
	{
		return new Func($this->leftExpression($x).' IN', [(string) $this->resolveValue($y)]);
	}

	public function notIn(string $x, mixed $y): Func
	{
		return new Func($this->leftExpression($x).' NOT IN', [(string) $this->resolveValue($y)]);
	}

	public function and(mixed ...$parts): Andx
	{
		return $this->andX(...$parts);
	}

	public function or(mixed ...$parts): Orx
	{
		return $this->orX(...$parts);
	}

	/**
	 * Build a DQL function call from the platform function catalog.
	 */
	public function func(string $name, mixed ...$arguments): Func
	{
		$resolved = $this->platform->resolveFunction($name);
		$args = array_map(fn (mixed $argument): string => (string) $this->resolveValue($argument), $arguments);

		return new Func($resolved, $args);
	}

	/**
	 * JSON containment via the registered `JSONB_CONTAINS` DQL function.
	 */
	public function jsonbContains(string $column, mixed $value): string
	{
		$placeholder = $this->param(json_encode($value, JSON_THROW_ON_ERROR))->getPlaceholder();

		return $this->platform->resolveFunction('jsonbContains').'('.$this->leftExpression($column).', '.$placeholder.') = true';
	}

	/**
	 * Cast a column or expression to another DQL type.
	 */
	public function cast(string $x, string $type): string
	{
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_ ]*$/', $type)) {
			throw new UnsafeExpressionException(sprintf('"%s" is not a valid cast target type.', $type));
		}

		return sprintf('CAST(%s AS %s)', $this->leftExpression($x), $type);
	}

	/**
	 * Wrap trusted DQL in a raw fragment that bypasses the safety scan.
	 */
	public function raw(string $dql, ?ParameterBag $parameters = null): RawDql
	{
		if (null !== $parameters) {
			$this->parameters->merge($parameters);
		}

		return new RawDql($dql);
	}

	private function comparison(mixed $x, string $operator, mixed $y): Comparison
	{
		return new Comparison($this->leftExpression($x), $operator, $this->resolveValue($y));
	}

	private function leftExpression(mixed $value): string
	{
		if ($value instanceof Column) {
			return (string) $value;
		}

		if (is_string($value) && !str_starts_with($value, ':') && '?' !== $value) {
			Column::assertValid($value);

			return $value;
		}

		return is_string($value) ? $value : (string) $value;
	}

	private function resolveValue(mixed $value): mixed
	{
		if ($value instanceof Column) {
			return (string) $value;
		}

		if (is_string($value) && (str_starts_with($value, ':') || '?' === $value)) {
			return $value;
		}

		if (is_array($value)) {
			return $this->bindArray($value);
		}

		return $this->bindScalar($value);
	}

	/**
	 * @return string the bound parameter placeholder
	 */
	private function bindScalar(mixed $value): string
	{
		return $this->parameters->add(new Parameter($value))->getPlaceholder();
	}

	/**
	 * @param array<array-key,mixed> $values
	 *
	 * @return string the bound parameter placeholder
	 */
	private function bindArray(array $values): string
	{
		return $this->parameters->add(new Parameter($values, $this->inferArrayType($values)))->getPlaceholder();
	}

	/**
	 * @param array<array-key,mixed> $values
	 */
	private function inferArrayType(array $values): ArrayParameterType
	{
		foreach ($values as $value) {
			if (!is_int($value)) {
				return ArrayParameterType::STRING;
			}
		}

		return ArrayParameterType::INTEGER;
	}
}
