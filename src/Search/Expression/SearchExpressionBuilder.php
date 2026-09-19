<?php

namespace iikiti\CMS\Search\Expression;

use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformStrategyInterface;
use iikiti\CMS\Query\Expression\ExpressionInterface;
use iikiti\CMS\Query\Expression\FunctionExpression;
use iikiti\CMS\Query\Expression\RawExpression;
use iikiti\CMS\Query\ExpressionBuilder;
use iikiti\CMS\Query\ParameterBag;

/**
 * FTS-specific expression helpers built on the query builder's expression API.
 *
 * Generates PostgreSQL full-text search constructs (to_tsvector, setweight,
 * ts_rank, ts_headline) using the platform strategy's function catalog.
 */
final class SearchExpressionBuilder
{
	private const WEIGHT_MAP = ['A', 'B', 'C', 'D'];

	public function __construct(
		private readonly ExpressionBuilder $expr,
		private readonly DatabasePlatformStrategyInterface $platform,
	) {
	}

	/**
	 * Build a weighted tsvector from multiple column expressions.
	 *
	 * @param list<array{weight:int, column:string|ExpressionInterface}> $fieldWeights
	 * @param string                                                     $config       PostgreSQL text search configuration (e.g. 'english')
	 */
	public function toTsVector(array $fieldWeights, string $config = 'english'): RawExpression
	{
		$parts = [];
		$bag = new ParameterBag();

		foreach ($fieldWeights as $item) {
			$weight = self::WEIGHT_MAP[min($item['weight'], 4) - 1];
			$column = $item['column'];
			$columnSql = $column instanceof ExpressionInterface ? (string) $column : $column;

			$parts[] = sprintf(
				"setweight(to_tsvector('%s', COALESCE(%s::text, '')), '%s')",
				$config,
				$columnSql,
				$weight
			);
		}

		$sql = [] === $parts ? sprintf("to_tsvector('%s', '')", $config) : implode(' || ', $parts);

		return $this->expr->raw($sql, $bag);
	}

	/**
	 * Build a ts_rank SQL expression for ordering by relevance.
	 */
	public function tsRank(string $vectorColumn, string $tsQueryExpr): string
	{
		$func = $this->platform->resolveFunction('ts_rank');

		return sprintf('%s(%s, %s)', $func, $vectorColumn, $tsQueryExpr);
	}

	/**
	 * Build a ts_headline expression for highlighting search matches.
	 *
	 * @param array<string,mixed> $options
	 */
	public function tsHeadline(string $column, string $tsQueryExpr, string $config = 'english', array $options = []): string
	{
		$func = $this->platform->resolveFunction('ts_headline');

		$opts = sprintf("Config='%s'", $config);
		foreach ($options as $key => $value) {
			if (is_string($value)) {
				$value = "'$value'";
			}
			$opts .= sprintf(', %s=%s', $key, $value);
		}

		return sprintf("%s(%s, %s, '%s')", $func, $column, $tsQueryExpr, $opts);
	}

	/**
	 * Build a to_tsquery function call expression.
	 */
	public function tsQuery(string $query, string $config = 'english', string $parser = 'plain'): FunctionExpression
	{
		$funcName = match ($parser) {
			'websearch' => 'websearchToTsquery',
			'raw' => 'toTsquery',
			default => 'plaintoTsquery',
		};

		return $this->expr->func($funcName, $this->expr->param($config), $this->expr->param($query));
	}

	/**
	 * Build a concatenation of tsvector expressions.
	 */
	public function concatTsVectors(string ...$vectors): string
	{
		return implode(' || ', $vectors);
	}

	public function getExpressionBuilder(): ExpressionBuilder
	{
		return $this->expr;
	}
}
