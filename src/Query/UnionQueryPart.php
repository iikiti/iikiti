<?php

namespace iikiti\CMS\Query;

use Doctrine\DBAL\Query\UnionType;

/**
 * A single part of a UNION query.
 *
 * The part is either a raw SQL string or another query builder, combined with
 * the requested UNION semantics (DISTINCT or ALL).
 */
final class UnionQueryPart
{
	public function __construct(
		public readonly string|QueryBuilder $query,
		public readonly UnionType $type = UnionType::DISTINCT,
	) {
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
