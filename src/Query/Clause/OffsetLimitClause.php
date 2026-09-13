<?php

namespace iikiti\CMS\Query\Clause;

/**
 * A validated LIMIT / OFFSET pair.
 *
 * The values are bound as parameters by the query builder, so this object only
 * carries validated, non-negative integers.
 */
final class OffsetLimitClause
{
	public function __construct(
		private readonly ?int $limit = null,
		private readonly ?int $offset = null,
	) {
		if (null !== $limit && $limit < 0) {
			throw new \InvalidArgumentException('Limit must be null or a non-negative integer.');
		}
		if (null !== $offset && $offset < 0) {
			throw new \InvalidArgumentException('Offset must be null or a non-negative integer.');
		}
	}

	public function getLimit(): ?int
	{
		return $this->limit;
	}

	public function getOffset(): ?int
	{
		return $this->offset;
	}

	public function hasLimit(): bool
	{
		return null !== $this->limit;
	}

	public function hasOffset(): bool
	{
		return null !== $this->offset;
	}
}
