<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Value\LocaleTag;

/** Creates and verifies the ICU collation/index pair for a BCP 47 locale. */
final readonly class LocaleCollationManager
{
	private string $schema;

	public function __construct(
		private Connection $connection,
		private DatabaseTableNameResolver $tableNames,
	) {
		$this->schema = $tableNames->getSchema();
		if ('' !== $this->schema && 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->schema)) {
			throw new \InvalidArgumentException('The database schema must be a simple PostgreSQL identifier.');
		}
	}

	public function ensure(LocaleTag $locale): string
	{
		$lockKey = 'iikiti.locale.index.'.hash('sha256', $locale->getTag());
		$this->connection->fetchOne('SELECT pg_advisory_lock(hashtext(:lock_key))', ['lock_key' => $lockKey]);
		try {
			return $this->ensureLocked($locale);
		} finally {
			$this->connection->fetchOne('SELECT pg_advisory_unlock(hashtext(:lock_key))', ['lock_key' => $lockKey]);
		}
	}

	private function ensureLocked(LocaleTag $locale): string
	{
		$tag = $locale->getTag();
		[$collationName, $indexName] = self::names($locale);
		$collation = $this->qualifiedIdentifier($collationName);
		$index = $this->qualifiedIdentifier($indexName);
		$registry = $this->tableNames->resolve('object_locale_index_collation');
		$existing = $this->connection->fetchAssociative(
			sprintf('SELECT collation_name, btree_index_name, collation_version FROM %s WHERE locale_tag = :locale', $registry),
			['locale' => $tag],
		);

		if (is_array($existing)) {
			$this->assertNamesMatch($existing, $collationName, $indexName, $tag);
			$actualVersion = $this->actualVersion($collationName);
			$this->ensureBtreeIndex($indexName, $collation);
			if ($actualVersion !== $existing['collation_version']) {
				$this->connection->executeStatement(sprintf('REINDEX INDEX %s', $index));
				$this->connection->executeStatement(sprintf('ALTER COLLATION %s REFRESH VERSION', $collation));
				$this->connection->executeStatement(
					sprintf('UPDATE %s SET collation_version = :version, updated_at = CURRENT_TIMESTAMP WHERE locale_tag = :locale', $registry),
					['version' => $this->actualVersion($collationName), 'locale' => $tag],
				);
			}

			return $collation;
		}

		$this->connection->executeStatement(sprintf(
			'CREATE COLLATION IF NOT EXISTS %s (provider = icu, locale = %s, deterministic = true)',
			$collation,
			$this->connection->quote($tag),
		));
		$this->assertCollationLocale($collationName, $tag);
		$this->connection->executeStatement(sprintf(
			'CREATE INDEX IF NOT EXISTS %s ON %s (site_id, object_type, property_name, query_locale, value COLLATE %s) INCLUDE (object_id)',
			$this->connection->quoteIdentifier($indexName),
			$this->tableNames->resolve('object_property_locale_index'),
			$collation,
		));
		$this->connection->executeStatement(
			sprintf('INSERT INTO %s (locale_tag, collation_name, btree_index_name, collation_version) VALUES (:locale, :collation, :index, :version)', $registry),
			[
				'locale' => $tag,
				'collation' => $collationName,
				'index' => $indexName,
				'version' => $this->actualVersion($collationName),
			],
		);

		return $collation;
	}

	public function get(LocaleTag $locale): string
	{
		[$collationName, $indexName] = self::names($locale);
		$collation = $this->qualifiedIdentifier($collationName);
		$registry = $this->tableNames->resolve('object_locale_index_collation');
		$existing = $this->connection->fetchAssociative(
			sprintf('SELECT collation_name, btree_index_name, collation_version FROM %s WHERE locale_tag = :locale', $registry),
			['locale' => $locale->getTag()],
		);
		if (!is_array($existing)) {
			throw new \RuntimeException(sprintf('No localized property index exists for locale "%s"; run iikiti:locale-index:rebuild.', $locale->getTag()));
		}
		$this->assertNamesMatch($existing, $collationName, $indexName, $locale->getTag());
		if (!$this->indexExists($indexName)) {
			throw new \RuntimeException(sprintf('Locale index for "%s" is missing; run iikiti:locale-index:rebuild.', $locale->getTag()));
		}

		$actualVersion = $this->actualVersion($collationName);
		if ($actualVersion !== $existing['collation_version']) {
			throw new \RuntimeException(sprintf('ICU collation indexes for "%s" need reindexing; run iikiti:locale-index:rebuild.', $locale->getTag()));
		}

		return $collation;
	}

	/** @return array{string,string} */
	public static function names(LocaleTag $locale): array
	{
		if ('und' === $locale->getTag()) {
			return ['iikiti_locale_und', 'idx_localized_property_sort_und'];
		}

		$suffix = substr(hash('sha256', $locale->getTag()), 0, 16);

		return ['iikiti_locale_'.$suffix, 'idx_localized_property_sort_'.$suffix];
	}

	private function actualVersion(string $collationName): string
	{
		$version = $this->connection->fetchOne('SELECT pg_collation_actual_version(to_regcollation(:collation))', [
			'collation' => ('' === $this->schema ? '' : $this->schema.'.').$collationName,
		]);
		if (!is_string($version) || '' === $version) {
			throw new \RuntimeException(sprintf('ICU provider is unavailable for collation %s.', $collationName));
		}

		return $version;
	}

	private function assertCollationLocale(string $collationName, string $locale): void
	{
		$actual = $this->connection->fetchOne(
			'SELECT colllocale FROM pg_collation WHERE oid = to_regcollation(:collation)',
			['collation' => ('' === $this->schema ? '' : $this->schema.'.').$collationName],
		);
		if (!is_string($actual) || 0 !== strcasecmp(str_replace('_', '-', $actual), $locale)) {
			throw new \RuntimeException(sprintf('Database collation %s does not match requested locale "%s".', $collationName, $locale));
		}
	}

	private function ensureBtreeIndex(string $indexName, string $collation): void
	{
		if ($this->indexExists($indexName)) {
			return;
		}

		$this->connection->executeStatement(sprintf(
			'CREATE INDEX %s ON %s (site_id, object_type, property_name, query_locale, value COLLATE %s) INCLUDE (object_id)',
			$this->connection->quoteIdentifier($indexName),
			$this->tableNames->resolve('object_property_locale_index'),
			$collation,
		));
	}

	private function indexExists(string $indexName): bool
	{
		$name = ('' === $this->schema ? '' : $this->schema.'.').$indexName;
		$result = $this->connection->fetchOne('SELECT to_regclass(:index_name)::text', ['index_name' => $name]);

		return is_string($result) && '' !== $result;
	}

	/** @param array<string,mixed> $existing */
	private function assertNamesMatch(array $existing, string $collationName, string $indexName, string $locale): void
	{
		if ($existing['collation_name'] !== $collationName || $existing['btree_index_name'] !== $indexName) {
			throw new \RuntimeException(sprintf('Localized index metadata for "%s" does not match the expected identifiers.', $locale));
		}
	}

	private function qualifiedIdentifier(string $name): string
	{
		return '' === $this->schema ?
			$this->connection->quoteIdentifier($name) :
			$this->connection->quoteIdentifier($this->schema).'.'.$this->connection->quoteIdentifier($name);
	}
}
