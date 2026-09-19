<?php

namespace iikiti\CMS\Search\Strategy\PostgreSQL;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Query\Identifier\Table;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchIndexField;
use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use iikiti\CMS\Search\Exception\SystemLockedException;
use iikiti\CMS\Search\Service\SearchHit;
use iikiti\CMS\Search\Service\SearchParams;
use iikiti\CMS\Search\Service\SearchResult;
use iikiti\CMS\Search\Strategy\SearchEngineInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * PostgreSQL full-text search engine adapter.
 *
 * Builds per-index tables with a `TSVECTOR` column and a `GIN` index,
 * leveraging the PostgreSQL text search parser, dictionaries (stop words,
 * snowball stemmers), and the `pg_trgm` extension for trigram-based
 * autocomplete.
 *
 * The tsvector column is populated from the index's field definitions:
 * `column` fields read directly from the `objects` table, `property` fields
 * are joined from the `object_properties` table, `virtual` fields use a
 * user-defined SQL expression, and `alias` fields delegate to their target.
 */
final class PostgreSQLSearchEngine implements SearchEngineInterface
{
	private const WEIGHT_MAP = ['A', 'B', 'C', 'D'];

	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		#[Autowire('%env(DB_SCHEMA)%')]
		private readonly string $schema = '',
	) {
	}

	public function getName(): string
	{
		return 'postgresql';
	}

	public function getLabel(): string
	{
		return 'PostgreSQL Full-Text Search';
	}

	public function supportsPlatform(AbstractPlatform $platform): bool
	{
		return $platform instanceof PostgreSQLPlatform;
	}

	public function getDefaultLanguage(): string
	{
		return 'english';
	}

	public function getIndexTableName(SearchIndex $config): string
	{
		$slug = $this->sanitizeIdentifier($config->getSlug());

		return ($this->schema ? $this->schema.'.' : '').'search_'.$slug;
	}

	public function buildIndexColumns(SearchIndex $config): array
	{
		return [
			'object_type VARCHAR(64) NOT NULL',
			'object_id BIGINT NOT NULL',
			'search_vector TSVECTOR',
			'data JSONB DEFAULT \'{}\'',
			'PRIMARY KEY (object_type, object_id)',
		];
	}

	public function createIndex(SearchIndex $config): void
	{
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);
		$slug = $this->sanitizeIdentifier($config->getSlug());

		$connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

		$columns = implode(', ', $this->buildIndexColumns($config));

		$connection->executeStatement(sprintf(
			'CREATE TABLE IF NOT EXISTS %s (%s, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(), updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW())',
			$tableName,
			$columns
		));

		$connection->executeStatement(sprintf(
			'CREATE INDEX IF NOT EXISTS idx_%s_gin ON %s USING GIN (search_vector)',
			$slug,
			$tableName
		));

		// Enable trigram index for autocomplete if any layer uses ngram/edgengram
		if ($this->hasTrigramLayer($config)) {
			$connection->executeStatement(sprintf(
				'CREATE INDEX IF NOT EXISTS idx_%s_trgm ON %s USING GIN (data text_pattern_ops)',
				$slug,
				$tableName
			));
		}
	}

	public function dropIndex(SearchIndex $config): void
	{
		if ($config->isSystemLocked()) {
			throw SystemLockedException::cannotDelete($config->getSlug());
		}

		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);

		$connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tableName));
	}

	public function rebuildIndex(SearchIndex $config): void
	{
		$this->createIndex($config);
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);

		$connection->executeStatement(sprintf('TRUNCATE TABLE %s', $tableName));

		$qb = $this->buildReindexQuery($config);
		$results = $qb->executeQuery()->fetchAllAssociative();

		if ([] === $results) {
			return;
		}

		// Batch upserts for performance
		$batchSize = 500;
		$batches = array_chunk($results, $batchSize);

		foreach ($batches as $batch) {
			$values = [];
			$params = [];
			foreach ($batch as $i => $row) {
				$values[] = sprintf('(:type_%d, :id_%d, :vector_%d, :data_%d)', $i, $i, $i, $i);
				$params['type_'.$i] = $row['object_type'];
				$params['id_'.$i] = $row['object_id'];
				$params['vector_'.$i] = $row['search_vector'];
				$params['data_'.$i] = $row['data'];
			}

			$connection->executeStatement(sprintf(
				'INSERT INTO %s (object_type, object_id, search_vector, data) VALUES %s ON CONFLICT (object_type, object_id) DO UPDATE SET search_vector = EXCLUDED.search_vector, data = EXCLUDED.data',
				$tableName,
				implode(', ', $values)
			), $params);
		}
	}

	public function reindexObject(SearchIndex $config, DbObject $object): void
	{
		$this->createIndex($config);
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);

		$qb = $this->buildReindexQuery($config);
		$qb->andWhere('o.id = :object_id');
		$qb->setParameter('object_id', $object->getId());

		$row = $qb->executeQuery()->fetchAssociative();

		if (!$row) {
			return;
		}

		$connection->executeStatement(sprintf(
			'INSERT INTO %s (object_type, object_id, search_vector, data) VALUES (:type, :id, :vector, :data) ON CONFLICT (object_type, object_id) DO UPDATE SET search_vector = EXCLUDED.search_vector, data = EXCLUDED.data',
			$tableName
		), [
			'type' => $row['object_type'],
			'id' => $row['object_id'],
			'vector' => $row['search_vector'],
			'data' => $row['data'],
		]);
	}

	public function removeFromIndex(SearchIndex $config, DbObject $object): void
	{
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);

		$connection->executeStatement(sprintf(
			'DELETE FROM %s WHERE object_type = :type AND object_id = :id',
			$tableName
		), [
			'type' => $object->getType(),
			'id' => $object->getId(),
		]);
	}

	public function search(SearchIndex $config, SearchParams $params, string $query): SearchResult
	{
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);
		$language = $config->getLanguage() ?: $this->getDefaultLanguage();

		$func = 'websearch_to_tsquery' === $params->queryType ? 'WEBSEARCH_TO_TSQUERY' : 'PLAINTO_TSQUERY';
		$tsQueryExpr = sprintf('%s(\'%s\', :query)', $func, $language);

		$sql = sprintf(
			'SELECT object_type, object_id, data, ts_rank(search_vector, %s) AS rank '.
			'FROM %s WHERE search_vector @@ %s '.
			'ORDER BY rank DESC LIMIT :limit OFFSET :offset',
			$tsQueryExpr,
			$tableName,
			$tsQueryExpr
		);

		$boundParams = [
			'query' => $query,
			'limit' => $params->limit ?? 20,
			'offset' => $params->offset ?? 0,
		];

		foreach ($params->filters as $filterName => $filterValue) {
			$sql .= sprintf(' AND data ->> :fkey_%s = :fval_%s', $filterName, $filterName);
			$boundParams['fkey_'.$filterName] = $filterName;
			$boundParams['fval_'.$filterName] = $filterValue;
		}

		$rows = $connection->executeQuery($sql, $boundParams)->fetchAllAssociative();

		$hits = [];
		foreach ($rows as $row) {
			$hit = new SearchHit(
				id: $row['object_id'],
				type: $row['object_type'],
				rank: (float) $row['rank'],
				data: $this->parseJsonb($row['data']),
			);

			if ($params->highlight) {
				$hit = $hit;
			}

			$hits[] = $hit;
		}

		return new SearchResult(
			hits: $hits,
			total: count($hits),
			limit: $params->limit ?? 20,
			offset: $params->offset ?? 0,
		);
	}

	public function autocomplete(SearchIndex $config, string $query, int $limit): array
	{
		$connection = $this->entityManager->getConnection();
		$tableName = $this->getIndexTableName($config);

		$sql = sprintf(
			'SELECT object_type, object_id, data->>\'name\' AS label '.
			'FROM %s WHERE data ? \'name\' AND data->>\'name\' ILIKE :pattern '.
			'ORDER BY similarity(data->>\'name\', :query) DESC LIMIT :limit',
			$tableName
		);

		$rows = $connection->executeQuery($sql, [
			'pattern' => $query.'%',
			'query' => $query,
			'limit' => $limit,
		])->fetchAllAssociative();

		$suggestions = [];
		foreach ($rows as $row) {
			$suggestions[] = [
				'id' => $row['object_id'],
				'type' => $row['object_type'],
				'label' => $row['label'],
			];
		}

		return $suggestions;
	}

	public function buildReindexQuery(SearchIndex $config): DbalQueryBuilder
	{
		$connection = $this->entityManager->getConnection();
		$qb = $connection->createQueryBuilder();

		$tsVectorExpr = $this->buildTsVectorExpression($config);
		$dataExpr = $this->buildDataExpression($config);
		$joins = $this->buildPropertyJoins($config);
		$prefix = $this->schema ?: 'iikiti_iikiti';

		$fromClause = sprintf('%s.objects o', $prefix);

		$qb->select(sprintf(
			'o.type AS object_type, o.id AS object_id, %s AS search_vector, %s AS data',
			$tsVectorExpr,
			$dataExpr
		))->from($fromClause);

		// Soft-delete guard
		$qb->andWhere('o.deleted_at IS NULL');

		foreach ($joins as $alias => $join) {
			$qb->leftJoin('o', $join['table'], $alias, $join['condition']);
			foreach ($join['params'] as $key => $value) {
				$qb->setParameter($key, $value);
			}
		}

		return $qb;
	}

	/**
	 * Build the tsvector SQL expression from field definitions.
	 */
	private function buildTsVectorExpression(SearchIndex $config): string
	{
		$language = $config->getLanguage() ?: $this->getDefaultLanguage();
		$parts = [];

		foreach ($config->getFields() as $field) {
			$fieldExpr = $this->buildFieldExpression($field);
			$weight = self::WEIGHT_MAP[min($field->getWeight() - 1, 3)];
			$fieldLang = $field->getLanguage() ?: $language;

			$parts[] = sprintf(
				"setweight(to_tsvector('%s', COALESCE(%s, '')), '%s')",
				$fieldLang,
				$fieldExpr,
				$weight
			);
		}

		if ([] === $parts) {
			return "to_tsvector('english', '')";
		}

		return implode(' || ', $parts);
	}

	/**
	 * Build the SQL expression for a single field's data.
	 */
	private function buildFieldExpression(SearchIndexField $field): string
	{
		return match ($field->getSourceType()) {
			SearchFieldSourceType::Column => sprintf('o.%s', $field->getSource()),
			SearchFieldSourceType::Property => sprintf(
				'COALESCE(%s.value::text, \'\')',
				$this->getPropertyAlias($field->getSource())
			),
			SearchFieldSourceType::Virtual => $field->getSource(),
			SearchFieldSourceType::Alias => sprintf('o.%s', $field->getSource()),
		};
	}

	/**
	 * @return array<string,array{table:string, condition:string, params:array<string,mixed>}>
	 */
	private function buildPropertyJoins(SearchIndex $config): array
	{
		$prefix = $this->schema ?: 'iikiti_iikiti';
		$joins = [];

		foreach ($config->getFields() as $field) {
			if (SearchFieldSourceType::Property !== $field->getSourceType()) {
				continue;
			}

			$alias = $this->getPropertyAlias($field->getSource());
			$propName = $field->getSource();
			$paramKey = 'prop_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $propName);

			$joins[$alias] = [
				'table' => sprintf('%s.object_properties', $prefix),
				'condition' => sprintf('%s.object_id = o.id AND %s.name = :'.$paramKey, $alias, $alias),
				'params' => [$paramKey => $propName],
			];
		}

		return $joins;
	}

	private function getPropertyAlias(string $propertyName): string
	{
		return 'p_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $propertyName);
	}

	/**
	 * Build a JSONB object from field expressions for stored/display data.
	 */
	private function buildDataExpression(SearchIndex $config): string
	{
		$fieldExpressions = [];

		foreach ($config->getFields() as $field) {
			$fieldExpr = $this->buildFieldExpression($field);
			$fieldExpressions[] = sprintf("'%s', COALESCE(%s, '')::text", $field->getName(), $fieldExpr);
		}

		if ([] === $fieldExpressions) {
			return '{}';
		}

		return 'jsonb_build_object('.implode(', ', $fieldExpressions).')';
	}

	private function hasTrigramLayer(SearchIndex $config): bool
	{
		return true === $config->getOption('use_trgm', false);
	}

	private function sanitizeIdentifier(string $value): string
	{
		return preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($value));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function parseJsonb(?string $json): array
	{
		if (null === $json || '' === $json) {
			return [];
		}

		try {
			$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

			return is_array($decoded) ? $decoded : [];
		} catch (\JsonException) {
			return [];
		}
	}
}
