<?php

namespace iikiti\CMS\ORM;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\DatabasePlatform\PostgreSQL\PostgreSQLPlatformStrategy;
use iikiti\CMS\Query\Exception\UnsupportedFeatureException;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\InlineValueScanner;
use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;

/**
 * An ORM query builder with the same safety contract as the DBAL query builder.
 *
 * It extends Doctrine's ORM QueryBuilder so entity hydration, result caching
 * and every ORM-specific method remain available. On top of that it:
 *
 *  - returns an {@see ExpressionBuilder} from {@see expr()} whose helpers bind
 *    values as parameters instead of inlining them;
 *  - rejects inline literal values in `where`/`andWhere`/`orWhere`/`having`/
 *    `andHaving`/`orHaving`/`set` through {@see InlineValueScanner};
 *  - exposes {@see Parameter()}/`addParameter()` and a parameter bag that is
 *    reconciled with Doctrine's parameter set when the query is built.
 *
 * PostgreSQL-only operators that have no DQL equivalent raise
 * {@see UnsupportedFeatureException} from the expression builder; use the DBAL
 * query builder for those.
 */
class QueryBuilder extends OrmQueryBuilder
{
	private ParameterBag $parameterBag;

	private bool $safetyEnabled;

	private DatabasePlatformStrategyInterface $platform;

	public function __construct(
		EntityManagerInterface $em,
		?DatabasePlatformStrategyInterface $platform = null,
		bool $safetyEnabled = true,
	) {
		parent::__construct($em);
		$this->platform = $platform ?? $this->detectPlatform($em);
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

	public function isSafetyEnabled(): bool
	{
		return $this->safetyEnabled;
	}

	public function enableSafety(): static
	{
		$this->safetyEnabled = true;

		return $this;
	}

	public function disableSafety(): static
	{
		$this->safetyEnabled = false;

		return $this;
	}

	/**
	 * Bind a value and return its placeholder.
	 */
	public function parameter(
		mixed $value,
		ParameterType|ArrayParameterType|string $type = ParameterType::STRING,
	): string {
		return $this->parameterBag->add(new Parameter($value, $type))->getPlaceholder();
	}

	public function addParameter(Parameter $parameter): string
	{
		return $this->parameterBag->add($parameter)->getPlaceholder();
	}

	#[\Override]
	public function expr(): ExpressionBuilder
	{
		return new ExpressionBuilder($this->platform, $this->parameterBag);
	}

	#[\Override]
	public function where(mixed ...$predicates): static
	{
		$this->assertSafeParts($predicates);

		return parent::where(...$this->materializeParts($predicates));
	}

	#[\Override]
	public function andWhere(mixed ...$where): static
	{
		$this->assertSafeParts($where);

		return parent::andWhere(...$this->materializeParts($where));
	}

	#[\Override]
	public function orWhere(mixed ...$where): static
	{
		$this->assertSafeParts($where);

		return parent::orWhere(...$this->materializeParts($where));
	}

	#[\Override]
	public function having(mixed ...$having): static
	{
		$this->assertSafeParts($having);

		return parent::having(...$this->materializeParts($having));
	}

	#[\Override]
	public function andHaving(mixed ...$having): static
	{
		$this->assertSafeParts($having);

		return parent::andHaving(...$this->materializeParts($having));
	}

	#[\Override]
	public function orHaving(mixed ...$having): static
	{
		$this->assertSafeParts($having);

		return parent::orHaving(...$this->materializeParts($having));
	}

	#[\Override]
	public function set(string $key, mixed $value): static
	{
		$this->assertSafePart($value);
		Column::assertValid($key);

		return parent::set($key, $value);
	}

	#[\Override]
	public function getQuery(): Query
	{
		$this->synchronizeParameters($this->getDQL());

		return parent::getQuery();
	}

	/**
	 * @param list<mixed> $parts
	 */
	private function assertSafeParts(array $parts): void
	{
		foreach ($parts as $part) {
			$this->assertSafePart($part);
		}
	}

	/**
	 * Materialise raw DQL fragments to strings so Doctrine's expression
	 * composites accept them; everything else is passed through unchanged.
	 *
	 * @param list<mixed> $parts
	 *
	 * @return list<mixed>
	 */
	private function materializeParts(array $parts): array
	{
		return array_map(
			static fn (mixed $part): mixed => $part instanceof RawDql ? (string) $part : $part,
			$parts
		);
	}

	private function assertSafePart(mixed $part): void
	{
		if ($part instanceof RawDql) {
			return;
		}

		if (is_string($part) || $part instanceof \Stringable) {
			InlineValueScanner::assertSafe((string) $part, $this->safetyEnabled);
		}
	}

	/**
	 * Bind every parameter referenced by the generated DQL into Doctrine's
	 * parameter set.
	 */
	private function synchronizeParameters(string $dql): void
	{
		if (0 === preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $dql, $matches)) {
			return;
		}

		foreach (array_unique($matches[1]) as $name) {
			$parameter = $this->parameterBag->get($name);
			if (null !== $parameter) {
				parent::setParameter($name, $parameter->getValue(), $parameter->getType());
			}
		}
	}

	private function detectPlatform(EntityManagerInterface $em): DatabasePlatformStrategyInterface
	{
		// The platform strategy drives function-name resolution for the typed
		// expression helpers. The project targets PostgreSQL, with the only
		// strategy implemented; inject a strategy explicitly for other
		// databases.
		return new PostgreSQLPlatformStrategy();
	}
}
