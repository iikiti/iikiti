<?php

namespace iikiti\CMS\Query;

use iikiti\CMS\Query\Exception\CteException;

/**
 * An ordered, validated collection of common table expressions.
 *
 * The set rejects duplicate names and, through {@see validate()}, detects CTEs
 * that reference another CTE declared later in the set (which the database
 * would reject).
 */
final class CteSet
{
	/** @var array<string,Cte> */
	private array $ctes = [];

	/**
	 * @param iterable<Cte> $ctes
	 */
	public function __construct(iterable $ctes = [])
	{
		foreach ($ctes as $cte) {
			$this->add($cte);
		}
	}

	public function add(Cte $cte): self
	{
		if (isset($this->ctes[$cte->name])) {
			throw CteException::duplicate($cte->name);
		}
		$this->ctes[$cte->name] = $cte;

		return $this;
	}

	/**
	 * @return list<Cte>
	 */
	public function all(): array
	{
		return array_values($this->ctes);
	}

	public function isEmpty(): bool
	{
		return [] === $this->ctes;
	}

	public function isRecursive(): bool
	{
		foreach ($this->ctes as $cte) {
			if ($cte->recursive) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws CteException when a CTE references a later declaration
	 */
	public function validate(): void
	{
		$names = array_keys($this->ctes);
		foreach ($names as $index => $name) {
			$sql = $this->ctes[$name]->getQuerySql();
			foreach (array_slice($names, $index + 1) as $later) {
				// Only treat the name as a reference when it appears as a
				// relation after FROM or JOIN. A plain word match would also
				// catch columns, aliases and string literals and reject valid
				// CTE sets.
				if (1 === preg_match('/(?:\bFROM|\bJOIN)\s+'.preg_quote($later, '/').'\b/i', $sql)) {
					throw CteException::forwardReference($name, $later);
				}
			}
		}
	}

	public function getParameters(): ParameterBag
	{
		$bag = new ParameterBag();
		foreach ($this->ctes as $cte) {
			$bag->merge($cte->getParameters());
		}

		return $bag;
	}
}
