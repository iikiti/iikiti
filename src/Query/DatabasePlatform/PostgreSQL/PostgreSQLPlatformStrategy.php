<?php

namespace iikiti\CMS\Query\DatabasePlatform\PostgreSQL;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use iikiti\CMS\Query\DatabasePlatform\AbstractPlatformStrategy;
use iikiti\CMS\Query\PostgreSQLQueryBuilder;

/**
 * The default database platform strategy, targeting PostgreSQL.
 *
 * It exposes the PostgreSQL operator syntax (comparison, regular expression,
 * JSON, full-text search and array operators), a small function catalog, and
 * declares support for common table expressions, unions, RETURNING and
 * ON CONFLICT upserts.
 */
class PostgreSQLPlatformStrategy extends AbstractPlatformStrategy
{
	public const NAME = 'postgresql';

	public function getName(): string
	{
		return self::NAME;
	}

	public function getLabel(): string
	{
		return 'PostgreSQL';
	}

	public function supportsPlatform(AbstractPlatform $platform): bool
	{
		return $platform instanceof PostgreSQLPlatform;
	}

	public function getOperators(): array
	{
		return [
			'eq' => '=',
			'neq' => '<>',
			'lt' => '<',
			'lte' => '<=',
			'gt' => '>',
			'gte' => '>=',

			'isNull' => 'IS NULL',
			'isNotNull' => 'IS NOT NULL',

			'like' => 'LIKE',
			'notLike' => 'NOT LIKE',

			'regex' => '~',
			'notRegex' => '!~',
			'iregex' => '~*',
			'notIregex' => '!~*',

			'jsonExtract' => '->',
			'jsonGetText' => '->>',
			'jsonContains' => '@>',

			'ftsMatch' => '@@',

			'arrayContains' => '@>',
			'arrayOverlaps' => '&&',
		];
	}

	public function getFunctionCatalog(): array
	{
		return [
			'count' => 'COUNT',
			'sum' => 'SUM',
			'avg' => 'AVG',
			'min' => 'MIN',
			'max' => 'MAX',
			'coalesce' => 'COALESCE',
			'nullif' => 'NULLIF',
			'lower' => 'LOWER',
			'upper' => 'UPPER',
			'length' => 'LENGTH',
			'trim' => 'TRIM',
			'dateTrunc' => 'DATE_TRUNC',
			'now' => 'NOW',
			'jsonBuildObject' => 'JSONB_BUILD_OBJECT',
			'jsonbContains' => 'JSONB_CONTAINS',
			'plaintoTsquery' => 'PLAINTO_TSQUERY',
			'toTsquery' => 'TO_TSQUERY',
			'websearchToTsquery' => 'WEBSEARCH_TO_TSQUERY',
			'to_tsvector' => 'TO_TSVECTOR',
			'ts_rank' => 'TS_RANK',
			'ts_rank_cd' => 'TS_RANK_CD',
			'ts_headline' => 'TS_HEADLINE',
			'setweight' => 'SETWEIGHT',
		];
	}

	public function supportsCte(bool $recursive = false): bool
	{
		return true;
	}

	public function supportsUnion(): bool
	{
		return true;
	}

	public function supportsReturning(): bool
	{
		return true;
	}

	public function supportsUpsert(): bool
	{
		return true;
	}

	public function getDefaultParameterType(): ParameterType
	{
		return ParameterType::STRING;
	}

	public function getQueryBuilderClass(): string
	{
		return PostgreSQLQueryBuilder::class;
	}
}
