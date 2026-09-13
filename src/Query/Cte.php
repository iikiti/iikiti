<?php

namespace iikiti\CMS\Query;

use iikiti\CMS\Query\Exception\CteException;

/**
 * An immutable common table expression.
 *
 * A CTE may be defined by a raw SQL string or by another query builder. When a
 * builder is used, its SQL and parameters are captured when the CTE is added
 * to an outer query through {@see QueryBuilder::withCte()}, so the inner
 * builder must be fully built before it is attached.
 */
final class Cte
{
	/**
	 * @param list<string>|null $columns
	 */
	public function __construct(
		public readonly string $name,
		public readonly string|QueryBuilder $query,
		public readonly ?array $columns = null,
		public readonly bool $recursive = false,
	) {
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
			throw CteException::invalidName($name);
		}
		if (null !== $columns) {
			foreach ($columns as $column) {
				if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
					throw CteException::invalidName($column);
				}
			}
		}
	}

	public function getQuerySql(): string
	{
		return $this->query instanceof QueryBuilder ? $this->query->getSQL() : $this->query;
	}

	public function getParameters(): ParameterBag
	{
		return $this->query instanceof QueryBuilder ? $this->query->getParameterBag() : new ParameterBag();
	}
}
