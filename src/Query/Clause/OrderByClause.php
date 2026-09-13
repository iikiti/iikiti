<?php

namespace iikiti\CMS\Query\Clause;

use iikiti\CMS\Query\Identifier\Column;

/**
 * A single ORDER BY term with an optional NULLS FIRST / NULLS LAST modifier.
 *
 * The direction and nulls placement are validated so that only the fixed,
 * non-injectable keywords are accepted.
 */
final class OrderByClause
{
	public const ASC = 'ASC';
	public const DESC = 'DESC';

	public const NULLS_FIRST = 'FIRST';
	public const NULLS_LAST = 'LAST';

	private readonly string $direction;

	public function __construct(
		private readonly Column $column,
		string $direction = self::ASC,
		private readonly ?string $nulls = null,
	) {
		$direction = strtoupper($direction);
		if (!in_array($direction, [self::ASC, self::DESC], true)) {
			throw new \InvalidArgumentException(sprintf('Invalid order direction "%s".', $direction));
		}
		if (null !== $nulls && !in_array(strtoupper($nulls), [self::NULLS_FIRST, self::NULLS_LAST], true)) {
			throw new \InvalidArgumentException(sprintf('Invalid nulls placement "%s".', $nulls));
		}
		$this->direction = $direction;
	}

	public function getColumn(): Column
	{
		return $this->column;
	}

	public function getDirection(): string
	{
		return $this->direction;
	}

	public function getNulls(): ?string
	{
		return $this->nulls;
	}

	public function __toString(): string
	{
		$sql = $this->column.' '.$this->direction;
		if (null !== $this->nulls) {
			$sql .= ' NULLS '.strtoupper($this->nulls);
		}

		return $sql;
	}
}
