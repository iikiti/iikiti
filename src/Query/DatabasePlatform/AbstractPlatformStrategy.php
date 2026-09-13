<?php

namespace iikiti\CMS\Query\DatabasePlatform;

use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Operator;

/**
 * Shared behaviour for database platform strategies.
 *
 * Concrete strategies provide the operator and function catalogs; this base
 * class handles catalog lookups, identifier quoting and the default feature
 * flags.
 */
abstract class AbstractPlatformStrategy implements DatabasePlatformStrategyInterface
{
	public function resolveOperator(Operator $operator): string
	{
		$operators = $this->getOperators();
		if (!isset($operators[$operator->value])) {
			throw UnsupportedFeatureException::operator($operator->value, $this->getName());
		}

		return $operators[$operator->value];
	}

	public function resolveFunction(string $name): string
	{
		$catalog = $this->getFunctionCatalog();
		if (!isset($catalog[$name])) {
			throw UnsupportedFeatureException::function($name, $this->getName());
		}

		return $catalog[$name];
	}

	public function quoteIdentifier(string $identifier): string
	{
		return '"'.str_replace('"', '""', $identifier).'"';
	}

	public function renderCast(string $expression, string $type): string
	{
		return sprintf('(%s)::%s', $expression, $type);
	}

	public function supportsCte(bool $recursive = false): bool
	{
		return false;
	}

	public function supportsUnion(): bool
	{
		return false;
	}

	public function supportsReturning(): bool
	{
		return false;
	}

	public function supportsUpsert(): bool
	{
		return false;
	}
}
