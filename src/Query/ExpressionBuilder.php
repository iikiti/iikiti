<?php

namespace iikiti\CMS\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder as DbalExpressionBuilder;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Expression\CastExpression;
use iikiti\CMS\Query\Expression\Comparison;
use iikiti\CMS\Query\Expression\CompositeExpression;
use iikiti\CMS\Query\Expression\ExpressionInterface;
use iikiti\CMS\Query\Expression\FunctionExpression;
use iikiti\CMS\Query\Expression\NullCheckExpression;
use iikiti\CMS\Query\Expression\RawExpression;
use iikiti\CMS\Query\Identifier\Alias;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Identifier\Table;

/**
 * Expression builder with parameter binding and a database-neutral API.
 *
 * The builder extends Doctrine DBAL's expression builder, so every existing
 * helper remains available. The overridden helpers accept typed identifiers
 * and raw values: scalar values are automatically bound as query parameters
 * rather than interpolated, which is what makes inline-value injection
 * impossible through this API.
 *
 * Database-specific syntax is never hardcoded here. Each helper maps to an
 * abstract operator which the active platform strategy renders. New databases
 * therefore gain support for every helper simply by registering a strategy.
 */
final class ExpressionBuilder extends DbalExpressionBuilder
{
	public function __construct(
		private readonly Connection $connection,
		private readonly DatabasePlatformStrategyInterface $platform,
		private readonly ParameterBag $parameters,
	) {
		parent::__construct($connection);
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
	 * Build a typed binary expression object.
	 *
	 * The left hand side may be a plain column, a raw identifier string or any
	 * expression (a function call or a cast, for example).
	 */
	public function condition(
		Column|ExpressionInterface|string $left,
		Operator $operator,
		mixed $right,
	): Comparison {
		return new Comparison(
			$this->leftExpression($left),
			$operator,
			$this->toExpression($right),
			$this->platform
		);
	}

	/**
	 * Bind a value and return the resulting parameter.
	 */
	public function param(
		mixed $value,
		string|ParameterType|ArrayParameterType $type = ParameterType::STRING,
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

	/**
	 * Create a table reference.
	 */
	public function table(string $name, ?string $schema = null): Table
	{
		return new Table($name, $schema);
	}

	/**
	 * Create an alias reference.
	 */
	public function alias(string $name): Alias
	{
		return new Alias($name);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function eq(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::EQ, $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function neq(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::NEQ, $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function lt(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::LT, $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function lte(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::LTE, $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function gt(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::GT, $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function gte(string|Column $x, mixed $y): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::GTE, $y);
	}

	/**
	 * @phpstan-param string|Column|ExpressionInterface $x
	 */
	#[\Override]
	public function isNull(string|Column|ExpressionInterface $x): string
	{
		return (string) new NullCheckExpression($this->leftExpression($x), false, $this->platform);
	}

	/**
	 * @phpstan-param string|Column|ExpressionInterface $x
	 */
	#[\Override]
	public function isNotNull(string|Column|ExpressionInterface $x): string
	{
		return (string) new NullCheckExpression($this->leftExpression($x), true, $this->platform);
	}

	/**
	 * @phpstan-param mixed $pattern
	 */
	#[\Override]
	public function like(string|Column $expression, mixed $pattern, ?string $escapeChar = null): string
	{
		return $this->comparisonWithEscape($expression, Operator::LIKE, $pattern, $escapeChar);
	}

	/**
	 * @phpstan-param mixed $pattern
	 */
	#[\Override]
	public function notLike(string|Column $expression, mixed $pattern, ?string $escapeChar = null): string
	{
		return $this->comparisonWithEscape($expression, Operator::NOT_LIKE, $pattern, $escapeChar);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function in(string|Column $x, mixed $y): string
	{
		return $this->setComparison($x, 'IN', $y);
	}

	/**
	 * @phpstan-param mixed $y
	 */
	#[\Override]
	public function notIn(string|Column $x, mixed $y): string
	{
		return $this->setComparison($x, 'NOT IN', $y);
	}

	/**
	 * Case-sensitive regular expression match, mapped through the platform
	 * operator catalog (`~` on PostgreSQL).
	 */
	public function regex(string|Column $x, mixed $pattern): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::REGEX, $pattern);
	}

	public function notRegex(string|Column $x, mixed $pattern): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::NOT_REGEX, $pattern);
	}

	/**
	 * Case-insensitive regular expression match (`~*` on PostgreSQL).
	 */
	public function iregex(string|Column $x, mixed $pattern): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::IREGEX, $pattern);
	}

	public function notIregex(string|Column $x, mixed $pattern): string
	{
		return (string) $this->condition($this->columnFor($x), Operator::NOT_IREGEX, $pattern);
	}

	/**
	 * JSON member access returning a JSON value (`->` on PostgreSQL).
	 */
	public function jsonExtract(string|Column $x, int|string $key): string
	{
		return (string) $this->condition(
			$this->columnFor($x),
			Operator::JSON_EXTRACT,
			$this->param($key, is_int($key) ? ParameterType::INTEGER : ParameterType::STRING)
		);
	}

	/**
	 * JSON member access returning text (`->>` on PostgreSQL).
	 */
	public function jsonGetText(string|Column $x, int|string $key): string
	{
		return (string) $this->condition(
			$this->columnFor($x),
			Operator::JSON_GET_TEXT,
			$this->param($key, is_int($key) ? ParameterType::INTEGER : ParameterType::STRING)
		);
	}

	/**
	 * JSON containment test. The value is JSON encoded and cast by the
	 * platform so the bound parameter is interpreted as JSON.
	 */
	public function jsonContains(string|Column $x, mixed $value): string
	{
		return (string) $this->condition(
			$this->columnFor($x),
			Operator::JSON_CONTAINS,
			$this->castExpression($this->param(json_encode($value, JSON_THROW_ON_ERROR)), 'jsonb')
		);
	}

	/**
	 * Full-text search match. The query is parsed by the given abstract
	 * function name (for example `plaintoTsquery`, `toTsquery` or
	 * `websearchToTsquery`).
	 */
	public function ftsMatch(string|Column $x, string $query, string $function = 'plaintoTsquery'): string
	{
		$tsQuery = new FunctionExpression($function, [$this->param($query)], $this->platform);

		return (string) $this->condition($this->columnFor($x), Operator::FTS_MATCH, $tsQuery);
	}

	/**
	 * Array containment (`@>` on PostgreSQL).
	 *
	 * @param array<array-key,mixed> $values
	 */
	public function arrayContains(string|Column $x, array $values): string
	{
		return (string) $this->condition(
			$this->columnFor($x),
			Operator::ARRAY_CONTAINS,
			$this->param($values, $this->inferArrayType($values))
		);
	}

	/**
	 * Array overlap (`&&` on PostgreSQL).
	 *
	 * @param array<array-key,mixed> $values
	 */
	public function arrayOverlaps(string|Column $x, array $values): string
	{
		return (string) $this->condition(
			$this->columnFor($x),
			Operator::ARRAY_OVERLAPS,
			$this->param($values, $this->inferArrayType($values))
		);
	}

	/**
	 * Cast a column or expression to another database type, returning the SQL.
	 */
	public function cast(string|Column|ExpressionInterface $x, string $type): string
	{
		return (string) $this->castExpression($this->leftExpression($x), $type);
	}

	/**
	 * Cast a column or expression to another database type, returning the
	 * expression object so it can be used as a comparison operand.
	 */
	public function castExpression(string|Column|ExpressionInterface $x, string $type): CastExpression
	{
		return new CastExpression($this->leftExpression($x), $type, $this->platform);
	}

	/**
	 * Build a generic function call. Scalar arguments are bound as parameters.
	 */
	public function func(string $name, mixed ...$arguments): FunctionExpression
	{
		$expressions = array_map(
			fn (mixed $argument): ExpressionInterface => $this->toExpression($argument),
			$arguments
		);

		return new FunctionExpression($name, array_values($expressions), $this->platform);
	}

	/**
	 * Wrap trusted SQL in a raw expression. Use of this method is discouraged;
	 * prefer the typed helpers above.
	 */
	public function raw(string $sql, ?ParameterBag $parameters = null): RawExpression
	{
		return new RawExpression($sql, $parameters ?? new ParameterBag());
	}

	/**
	 * Render a quoted SQL literal as an explicitly safe raw expression.
	 */
	public function literalExpression(string $input): RawExpression
	{
		return new RawExpression($this->connection->quote($input));
	}

	/**
	 * Combine expressions with AND into a single expression object.
	 */
	public function conjunction(ExpressionInterface ...$parts): CompositeExpression
	{
		return CompositeExpression::and(...$parts);
	}

	/**
	 * Combine expressions with OR into a single expression object.
	 */
	public function disjunction(ExpressionInterface ...$parts): CompositeExpression
	{
		return CompositeExpression::or(...$parts);
	}

	private function comparisonWithEscape(
		string|Column $expression,
		Operator $operator,
		mixed $pattern,
		?string $escapeChar,
	): string {
		$sql = (string) $this->condition($this->columnFor($expression), $operator, $pattern);
		if (null !== $escapeChar) {
			// Bind the escape character rather than inlining a quoted literal:
			// the value stays parameterised and the builder's safety scan
			// accepts the generated expression.
			$sql .= ' ESCAPE '.$this->param($escapeChar)->getPlaceholder();
		}

		return $sql;
	}

	private function setComparison(string|Column $x, string $operator, mixed $y): string
	{
		if ($y instanceof ExpressionInterface) {
			$right = (string) $y;
		} elseif (is_array($y)) {
			$right = $this->parameters->add(new Parameter($y, $this->inferArrayType($y)))->getPlaceholder();
		} else {
			$right = $this->param($y)->getPlaceholder();
		}

		return sprintf('%s %s (%s)', $this->columnFor($x), $operator, $right);
	}

	private function columnFor(string|Column $x): Column
	{
		return $x instanceof Column ? $x : new Column($x);
	}

	private function leftExpression(string|Column|ExpressionInterface $x): ExpressionInterface
	{
		if ($x instanceof ExpressionInterface) {
			return $x;
		}

		return $this->columnFor($x);
	}

	private function toExpression(mixed $value): ExpressionInterface
	{
		if ($value instanceof ExpressionInterface) {
			return $value;
		}

		if (is_array($value)) {
			return $this->param($value, $this->inferArrayType($value));
		}

		return $this->param($value);
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
