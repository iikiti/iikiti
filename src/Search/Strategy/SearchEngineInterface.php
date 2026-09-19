<?php

namespace iikiti\CMS\Search\Strategy;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Service\SearchParams;
use iikiti\CMS\Search\Service\SearchResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for search engine adapters.
 *
 * A search engine adapter is responsible for translating a {@see SearchIndex}
 * configuration into concrete database/Elasticsearch operations: creating
 * index structures, reindexing source data, executing search queries and
 * autocomplete suggestions.
 *
 * Engines are discovered at compile time from services tagged with
 * `iikiti.search_engine`, and may also be registered at runtime via
 * {@see SearchEngineRegistry::register()}. The default implementation targets
 * PostgreSQL using `tsvector` columns and GIN indexes.
 *
 * Keeping all engine-specific SQL behind this interface means search engine
 * selection is a configuration concern rather than application code.
 */
#[AutoconfigureTag('iikiti.search_engine')]
interface SearchEngineInterface
{
	/**
	 * Stable machine name, e.g. `postgresql`.
	 */
	public function getName(): string;

	/**
	 * Human-readable label for administration interfaces.
	 */
	public function getLabel(): string;

	/**
	 * Whether this engine can render SQL for the given Doctrine platform.
	 */
	public function supportsPlatform(AbstractPlatform $platform): bool;

	/**
	 * Default language for text search configuration.
	 */
	public function getDefaultLanguage(): string;

	/**
	 * The fully-qualified name of the index table, derived from the config.
	 */
	public function getIndexTableName(SearchIndex $config): string;

	/**
	 * Create the index structure (tables, columns, indexes) for the given
	 * search configuration.
	 */
	public function createIndex(SearchIndex $config): void;

	/**
	 * Drop the index structure for the given search configuration.
	 */
	public function dropIndex(SearchIndex $config): void;

	/**
	 * Rebuild the entire index from source data.
	 */
	public function rebuildIndex(SearchIndex $config): void;

	/**
	 * Incrementally add or update a single object in the index.
	 */
	public function reindexObject(SearchIndex $config, DbObject $object): void;

	/**
	 * Remove an object from the index.
	 */
	public function removeFromIndex(SearchIndex $config, DbObject $object): void;

	/**
	 * Execute a search query against the index.
	 */
	public function search(SearchIndex $config, SearchParams $params, string $query): SearchResult;

	/**
	 * Execute an autocomplete/suggestion query (prefix matching).
	 *
	 * @return list<array{id:int|string, type:string, label:string}>
	 */
	public function autocomplete(SearchIndex $config, string $query, int $limit): array;

	/**
	 * Build the SQL DDL fragment for this index's search vector column(s).
	 *
	 * @return list<string> SQL fragments (column definitions)
	 */
	public function buildIndexColumns(SearchIndex $config): array;

	/**
	 * Build a QueryBuilder prepared with field definitions and search vector
	 * for incremental reindexing.
	 */
	public function buildReindexQuery(SearchIndex $config): DbalQueryBuilder;
}
