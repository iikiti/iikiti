<?php

namespace iikiti\CMS\Query;

use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Identifier\Column;

/**
 * PostgreSQL-specific query builder.
 *
 * The generic builder already renders PostgreSQL SQL through
 * {@see DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy}. This class
 * adds conveniences for PostgreSQL-only statement features.
 */
final class PostgreSQLQueryBuilder extends QueryBuilder
{
	/** @var list<string> */
	private array $returningColumns = [];

	/**
	 * Add a recursive common table expression.
	 */
	public function withRecursiveCte(Cte $cte): self
	{
		$this->withCte(new Cte($cte->name, $cte->query, $cte->columns, true));

		return $this;
	}

	/**
	 * Add a RETURNING clause to an INSERT, UPDATE or DELETE statement.
	 */
	public function returning(string|Column ...$columns): self
	{
		if (!$this->getPlatformStrategy()->supportsReturning()) {
			throw UnsupportedFeatureException::feature('RETURNING', $this->getPlatformStrategy()->getName());
		}

		$this->returningColumns = array_map(
			static function (string|Column $column): string {
				if ($column instanceof Column) {
					return (string) $column;
				}
				Column::assertValid($column);

				return $column;
			},
			$columns
		);

		return $this;
	}

	#[\Override]
	public function getSQL(): string
	{
		$sql = parent::getSQL();
		if ([] !== $this->returningColumns) {
			$sql .= ' RETURNING '.implode(', ', $this->returningColumns);
		}

		return $sql;
	}
}
