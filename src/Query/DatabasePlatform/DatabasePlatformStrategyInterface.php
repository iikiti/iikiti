<?php

namespace iikiti\CMS\Query\DatabasePlatform;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use iikiti\CMS\Query\Operator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for database-specific SQL generation.
 *
 * A platform strategy supplies the concrete SQL syntax for the abstract
 * operators and function names used by the query builder. The default
 * implementation targets PostgreSQL; other vendors implement this interface
 * and register themselves, either through the
 * `iikiti.query.database_platform` dependency injection tag or at runtime via
 * {@see DatabasePlatformRegistry::register()}.
 *
 * Keeping all vendor syntax behind this interface means the query builder
 * itself contains no database-specific branching.
 */
#[AutoconfigureTag('iikiti.query.database_platform')]
interface DatabasePlatformStrategyInterface
{
	/**
	 * Stable machine name, for example `postgresql`.
	 */
	public function getName(): string;

	/**
	 * Human readable label for administration interfaces.
	 */
	public function getLabel(): string;

	/**
	 * Whether this strategy can render SQL for the given Doctrine platform.
	 */
	public function supportsPlatform(AbstractPlatform $platform): bool;

	/**
	 * Map of abstract operator name to SQL operator/fragment.
	 *
	 * @return array<string,string>
	 */
	public function getOperators(): array;

	/**
	 * Map of abstract function name to SQL function name.
	 *
	 * @return array<string,string>
	 */
	public function getFunctionCatalog(): array;

	/**
	 * Resolve the SQL for an abstract operator.
	 *
	 * @throws \iikiti\CMS\Query\Exception\UnsupportedFeatureException
	 */
	public function resolveOperator(Operator $operator): string;

	/**
	 * Resolve the SQL name of an abstract function.
	 *
	 * @throws \iikiti\CMS\Query\Exception\UnsupportedFeatureException
	 */
	public function resolveFunction(string $name): string;

	/**
	 * Quote a single identifier part for this platform.
	 */
	public function quoteIdentifier(string $identifier): string;

	/**
	 * Render a value type cast, for example `(expr)::numeric` on PostgreSQL
	 * or `CAST(expr AS numeric)` elsewhere.
	 */
	public function renderCast(string $expression, string $type): string;

	/**
	 * Whether common table expressions are supported.
	 */
	public function supportsCte(bool $recursive = false): bool;

	public function supportsUnion(): bool;

	/**
	 * Whether DML statements support a RETURNING clause.
	 */
	public function supportsReturning(): bool;

	public function supportsUpsert(): bool;

	public function getDefaultParameterType(): ParameterType;

	/**
	 * Fully qualified query builder class used for this platform.
	 *
	 * @return class-string<\iikiti\CMS\Query\QueryBuilder>
	 */
	public function getQueryBuilderClass(): string;
}
