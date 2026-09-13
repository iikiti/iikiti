<?php

namespace iikiti\CMS\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\Expression\CompositeExpression as DbalCompositeExpression;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use iikiti\CMS\Query\Clause\OffsetLimitClause;
use iikiti\CMS\Query\Clause\OrderByClause;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Exception\InlineValueException;
use iikiti\CMS\Query\Exception\UnionException;
use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Expression\ExpressionInterface;
use iikiti\CMS\Query\Identifier\Alias;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Identifier\Table;

/**
 * A safety-first SQL query builder for SELECT, UPDATE and DELETE statements.
 *
 * The builder extends Doctrine DBAL's query builder, so every DBAL capability,
 * including common table expressions and unions, remains available. It adds:
 *
 *  - a parameter binding API ({@see parameter()}, {@see addParameter()}); and
 *  - inline-value enforcement, which refuses to render an expression string
 *    that contains a literal value instead of a bound parameter.
 *
 * Database syntax is never hardcoded. Operators are resolved by the active
 * {@see DatabasePlatformStrategyInterface}, so additional databases can be
 * supported by registering another strategy.
 */
class QueryBuilder extends DbalQueryBuilder
{
	private ParameterBag $parameterBag;

	private bool $safetyEnabled;

	private QueryType $queryType = QueryType::SELECT;

	private bool $recursiveCtes = false;

	private bool $hasUnionPart = false;

	public function __construct(
		private readonly Connection $connection,
		private readonly DatabasePlatformStrategyInterface $platform,
		bool $safetyEnabled = true,
	) {
		parent::__construct($connection);
		$this->parameterBag = new ParameterBag();
		$this->safetyEnabled = $safetyEnabled;
	}

	public function getPlatformStrategy(): DatabasePlatformStrategyInterface
	{
		return $this->platform;
	}

	public function getParameterBag(): ParameterBag
	{
		return $this->parameterBag;
	}

	public function getQueryType(): QueryType
	{
		return $this->queryType;
	}

	public function isSafetyEnabled(): bool
	{
		return $this->safetyEnabled;
	}

	public function enableSafety(): self
	{
		$this->safetyEnabled = true;

		return $this;
	}

	public function disableSafety(): self
	{
		$this->safetyEnabled = false;

		return $this;
	}

	#[\Override]
	public function expr(): ExpressionBuilder
	{
		return new ExpressionBuilder($this->connection, $this->platform, $this->parameterBag);
	}

	/**
	 * Bind a value and return its placeholder.
	 */
	public function parameter(
		mixed $value,
		string|ParameterType|ArrayParameterType $type = ParameterType::STRING,
	): string {
		return $this->addParameter(new Parameter($value, $type));
	}

	/**
	 * Bind an existing parameter, assigning it a name when necessary.
	 */
	public function addParameter(Parameter $parameter): string
	{
		return $this->parameterBag->add($parameter)->getPlaceholder();
	}

	#[\Override]
	public function select(string|Column|ExpressionInterface ...$expressions): self
	{
		$this->queryType = QueryType::SELECT;

		parent::select(...array_map($this->renderSelectable(...), $expressions));

		return $this;
	}

	#[\Override]
	public function addSelect(string|Column|ExpressionInterface $expression, string|Column|ExpressionInterface ...$expressions): self
	{
		$this->queryType = QueryType::SELECT;

		parent::addSelect(
			$this->renderSelectable($expression),
			...array_map($this->renderSelectable(...), $expressions)
		);

		return $this;
	}

	/**
	 * Add a single selected column with an optional alias.
	 */
	public function selectColumn(Column $column, ?Alias $alias = null): self
	{
		return $this->select($this->aliased($column, $alias));
	}

	/**
	 * Add a selected expression with an optional alias.
	 */
	public function selectExpression(ExpressionInterface $expression, ?Alias $alias = null): self
	{
		return $this->select($this->aliased($expression, $alias));
	}

	/**
	 * Append ORDER BY terms built from validated clauses.
	 */
	public function orderByClause(OrderByClause ...$clauses): self
	{
		foreach ($clauses as $clause) {
			parent::addOrderBy((string) $clause);
		}

		return $this;
	}

	/**
	 * Apply a validated LIMIT / OFFSET pair.
	 */
	public function applyLimit(OffsetLimitClause $clause): self
	{
		if ($clause->hasLimit()) {
			parent::setMaxResults($clause->getLimit());
		}
		if ($clause->hasOffset()) {
			parent::setFirstResult($clause->getOffset());
		}

		return $this;
	}

	#[\Override]
	public function from(string|Table $table, string|Alias|null $alias = null): self
	{
		parent::from($this->resolveTable($table), null === $alias ? null : $this->resolveAlias($alias));

		return $this;
	}

	#[\Override]
	public function update(string|Table $table): self
	{
		$this->queryType = QueryType::UPDATE;

		parent::update($this->resolveTable($table));

		return $this;
	}

	#[\Override]
	public function delete(string|Table $table): self
	{
		$this->queryType = QueryType::DELETE;

		parent::delete($this->resolveTable($table));

		return $this;
	}

	#[\Override]
	public function insert(string|Table $table): self
	{
		parent::insert($this->resolveTable($table));

		return $this;
	}

	#[\Override]
	public function set(string|Column $key, string|ExpressionInterface $value): self
	{
		if ($value instanceof ExpressionInterface) {
			$this->adopt($value);
			$valueSql = (string) $value;
		} else {
			$this->assertSafe($value);
			$valueSql = $value;
		}

		parent::set($this->resolveColumn($key), $valueSql);

		return $this;
	}

	#[\Override]
	public function innerJoin(
		string|Alias $fromAlias,
		string|Table $join,
		string|Alias $alias,
		string|ExpressionInterface|null $condition = null,
	): self {
		parent::innerJoin(
			$this->resolveAlias($fromAlias),
			$this->resolveTable($join),
			$this->resolveAlias($alias),
			$this->renderCondition($condition)
		);

		return $this;
	}

	#[\Override]
	public function join(
		string|Alias $fromAlias,
		string|Table $join,
		string|Alias $alias,
		string|ExpressionInterface|null $condition = null,
	): self {
		return $this->innerJoin($fromAlias, $join, $alias, $condition);
	}

	#[\Override]
	public function leftJoin(
		string|Alias $fromAlias,
		string|Table $join,
		string|Alias $alias,
		string|ExpressionInterface|null $condition = null,
	): self {
		parent::leftJoin(
			$this->resolveAlias($fromAlias),
			$this->resolveTable($join),
			$this->resolveAlias($alias),
			$this->renderCondition($condition)
		);

		return $this;
	}

	#[\Override]
	public function rightJoin(
		string|Alias $fromAlias,
		string|Table $join,
		string|Alias $alias,
		string|ExpressionInterface|null $condition = null,
	): self {
		parent::rightJoin(
			$this->resolveAlias($fromAlias),
			$this->resolveTable($join),
			$this->resolveAlias($alias),
			$this->renderCondition($condition)
		);

		return $this;
	}

	#[\Override]
	public function where(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::where(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	#[\Override]
	public function andWhere(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::andWhere(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	#[\Override]
	public function orWhere(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::orWhere(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	#[\Override]
	public function having(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::having(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	#[\Override]
	public function andHaving(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::andHaving(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	#[\Override]
	public function orHaving(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): self {
		parent::orHaving(...$this->normalizePredicates($predicate, ...$predicates));

		return $this;
	}

	/**
	 * Add a common table expression.
	 *
	 * @throws UnsupportedFeatureException when the platform does not support CTEs
	 */
	public function withCte(Cte $cte): self
	{
		if (!$this->platform->supportsCte($cte->recursive)) {
			throw UnsupportedFeatureException::feature('common table expressions', $this->platform->getName());
		}

		parent::with(
			$cte->name,
			$this->importRenderedParameters($cte->getQuerySql(), $cte->getParameters()),
			$cte->columns
		);
		if ($cte->recursive) {
			$this->recursiveCtes = true;
		}

		return $this;
	}

	/**
	 * Add an ordered set of common table expressions.
	 *
	 * @throws Exception\CteException on a forward reference
	 */
	public function withCteSet(CteSet $set): self
	{
		$set->validate();
		foreach ($set->all() as $cte) {
			$this->withCte($cte);
		}

		return $this;
	}

	/**
	 * Start a UNION query with an initial part.
	 */
	public function unionPart(UnionQueryPart $part): self
	{
		if (!$this->platform->supportsUnion()) {
			throw UnsupportedFeatureException::feature('UNION', $this->platform->getName());
		}

		parent::union($this->importRenderedParameters($part->getQuerySql(), $part->getParameters()));
		$this->hasUnionPart = true;

		return $this;
	}

	/**
	 * Append a part to a UNION query.
	 *
	 * @throws UnionException when no initial part exists
	 */
	public function addUnionPart(UnionQueryPart $part): self
	{
		if (!$this->platform->supportsUnion()) {
			throw UnsupportedFeatureException::feature('UNION', $this->platform->getName());
		}

		if (!$this->hasUnionPart) {
			throw UnionException::missingInitialPart();
		}

		parent::addUnion(
			$this->importRenderedParameters($part->getQuerySql(), $part->getParameters()),
			$part->type
		);

		return $this;
	}

	#[\Override]
	public function getSQL(): string
	{
		$sql = parent::getSQL();
		if ($this->recursiveCtes && str_starts_with($sql, 'WITH ')) {
			// Doctrine DBAL does not render recursive CTEs, so promote the
			// leading WITH keyword when a recursive CTE was added.
			$sql = 'WITH RECURSIVE '.substr($sql, 5);
		}
		$this->synchronizeParameters($sql);

		return $sql;
	}

	/**
	 * @return array<string,mixed>
	 */
	#[\Override]
	public function getParameters(): array
	{
		$parameters = parent::getParameters();
		foreach ($this->parameterBag->all() as $name => $parameter) {
			$parameters[$name] ??= $parameter->getValue();
		}

		return $parameters;
	}

	public function __clone(): void
	{
		parent::__clone();
		$this->parameterBag = clone $this->parameterBag;
	}

	/**
	 * Bind every parameter that is referenced by the rendered SQL.
	 *
	 * Binding lazily means expressions that were built but never attached to
	 * the query do not leak unused parameters into the statement.
	 */
	private function synchronizeParameters(string $sql): void
	{
		if (0 === preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches)) {
			return;
		}

		foreach (array_unique($matches[1]) as $name) {
			$parameter = $this->parameterBag->get($name);
			if (null !== $parameter) {
				parent::setParameter($name, $parameter->getValue(), $parameter->getType());
			}
		}
	}

	/**
	 * @return list<string|DbalCompositeExpression>
	 */
	private function normalizePredicates(
		string|DbalCompositeExpression|ExpressionInterface $predicate,
		string|DbalCompositeExpression|ExpressionInterface ...$predicates,
	): array {
		$normalized = [];
		foreach ([$predicate, ...$predicates] as $part) {
			if ($part instanceof ExpressionInterface) {
				$this->adopt($part);
				$normalized[] = (string) $part;
			} elseif ($part instanceof DbalCompositeExpression) {
				$this->assertSafe((string) $part);
				$normalized[] = $part;
			} else {
				$this->assertSafe($part);
				$normalized[] = $part;
			}
		}

		return $normalized;
	}

	private function renderSelectable(string|Column|ExpressionInterface $value): string
	{
		if ($value instanceof ExpressionInterface) {
			$this->adopt($value);

			return (string) $value;
		}

		$this->assertSafe($value);

		return $value;
	}

	private function renderCondition(string|ExpressionInterface|null $condition): ?string
	{
		if (null === $condition) {
			return null;
		}

		if ($condition instanceof ExpressionInterface) {
			$this->adopt($condition);

			return (string) $condition;
		}

		$this->assertSafe($condition);

		return $condition;
	}

	private function aliased(Column|ExpressionInterface $expression, ?Alias $alias): string
	{
		$this->adopt($expression);

		return null === $alias ? (string) $expression : $expression.' AS '.$alias;
	}

	private function adopt(ExpressionInterface $expression): void
	{
		$this->parameterBag->merge($expression->getParameters());
	}

	/**
	 * Import parameters referenced by already-rendered SQL, renaming any that
	 * collide and rewriting the SQL text to match.
	 */
	private function importRenderedParameters(string $sql, ParameterBag $bag): string
	{
		foreach ($bag->all() as $parameter) {
			$original = $parameter->getName();
			$imported = $this->parameterBag->import($parameter)->getName();
			if (null !== $original && $original !== $imported) {
				$sql = preg_replace('/:'.preg_quote($original, '/').'\b/', ':'.$imported, $sql) ?? $sql;
			}
		}

		return $sql;
	}

	private function resolveTable(string|Table $table): string
	{
		if ($table instanceof Table) {
			return (string) $table;
		}

		Table::assertValid($table);

		return $table;
	}

	private function resolveAlias(string|Alias $alias): string
	{
		if ($alias instanceof Alias) {
			return (string) $alias;
		}

		Alias::assertValid($alias);

		return $alias;
	}

	private function resolveColumn(string|Column $column): string
	{
		if ($column instanceof Column) {
			return (string) $column;
		}

		Column::assertValid($column);

		return $column;
	}

	/**
	 * Refuse expression strings that contain literal values.
	 *
	 * @throws InlineValueException when a literal is detected
	 */
	private function assertSafe(string $expression): void
	{
		InlineValueScanner::assertSafe($expression, $this->safetyEnabled);
	}
}
