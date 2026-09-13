<?php

namespace iikiti\CMS\Query;

/**
 * An ordered collection of named query parameters.
 *
 * Names are generated from a per-instance counter, so two builders for the
 * same logical query produce identical SQL text (and therefore reuse database
 * plan/prepared-statement caches). When parameters from another bag are merged
 * in, any name collision is resolved by renaming the incoming parameter, which
 * keeps bindings correct without a process-wide counter.
 */
final class ParameterBag
{
	private int $counter = 0;

	/** @var array<string,Parameter> */
	private array $parameters = [];

	/**
	 * Add a parameter, assigning it a unique local name when it has none or
	 * when its name is already taken by a different parameter.
	 */
	public function add(Parameter $parameter): Parameter
	{
		$name = $parameter->getName();
		if (null === $name || $this->isNameTakenByOther($name, $parameter)) {
			$parameter->setName($this->nextName());
		}
		$this->parameters[$parameter->getName()] = $parameter;

		return $parameter;
	}

	/**
	 * Import a parameter from another bag. Identical to {@see add()}, but named
	 * for readability at cross-builder call sites.
	 */
	public function import(Parameter $parameter): Parameter
	{
		return $this->add($parameter);
	}

	public function has(string $name): bool
	{
		return isset($this->parameters[$name]);
	}

	public function get(string $name): ?Parameter
	{
		return $this->parameters[$name] ?? null;
	}

	/**
	 * @return array<string,Parameter>
	 */
	public function all(): array
	{
		return $this->parameters;
	}

	public function isEmpty(): bool
	{
		return [] === $this->parameters;
	}

	/**
	 * Merge another bag into this one, renaming any colliding parameters so
	 * every parameter keeps a distinct name.
	 */
	public function merge(self $other): void
	{
		foreach ($other->all() as $parameter) {
			$this->add($parameter);
		}
	}

	private function isNameTakenByOther(string $name, Parameter $parameter): bool
	{
		return isset($this->parameters[$name]) && $this->parameters[$name] !== $parameter;
	}

	private function nextName(): string
	{
		do {
			$name = 'qp'.(++$this->counter);
		} while (isset($this->parameters[$name]));

		return $name;
	}
}
