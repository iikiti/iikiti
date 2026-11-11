<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Stores resolved localized values and creates the indexed und fallback. */
final class Version20261111040000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add the localized property query projection and UTF-8 und ICU indexes.';
	}

	public function up(Schema $schema): void
	{
		if (!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			throw new \RuntimeException('Localized property indexes require PostgreSQL.');
		}
		if ('UTF8' !== $this->connection->fetchOne(
			'SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = current_database()',
		)) {
			throw new \RuntimeException('Localized property indexes require a UTF-8 encoded PostgreSQL database.');
		}

		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || '' === $schemaName || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
			throw new \RuntimeException('DB_SCHEMA must be configured as a simple PostgreSQL schema identifier.');
		}
		$schemaIdentifier = $this->connection->quoteIdentifier($schemaName);
		$projection = $schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_property_locale_index');
		$collations = $schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_locale_index_collation');
		$undCollation = $schemaIdentifier.'.'.$this->connection->quoteIdentifier('iikiti_locale_und');

		$this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
		$this->addSql(sprintf(
			'CREATE COLLATION IF NOT EXISTS %s (provider = icu, locale = \'und\', deterministic = true)',
			$undCollation,
		));
		$this->addSql(sprintf(
			'CREATE TABLE %s (object_property_id BIGINT NOT NULL, object_id BIGINT NOT NULL, object_site_id BIGINT NOT NULL, site_id BIGINT NOT NULL, object_type VARCHAR(255) NOT NULL, property_name VARCHAR(255) NOT NULL, query_locale VARCHAR(255) NOT NULL, source_locale VARCHAR(255) NOT NULL, value TEXT NOT NULL, is_searchable BOOLEAN NOT NULL, is_sortable BOOLEAN NOT NULL, search_vector TSVECTOR NOT NULL, PRIMARY KEY (object_property_id, site_id, query_locale), CONSTRAINT uniq_localized_index_object_field_locale UNIQUE (object_id, site_id, property_name, query_locale), CONSTRAINT fk_localized_index_property FOREIGN KEY (object_property_id) REFERENCES %s (id) ON DELETE CASCADE, CONSTRAINT fk_localized_index_object FOREIGN KEY (object_id) REFERENCES %s (id) ON DELETE CASCADE)',
			$projection,
			$schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_properties'),
			$schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_identity'),
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_localized_property_lookup ON %s (site_id, object_type, property_name, query_locale, object_id)',
			$projection,
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_localized_property_value_trgm ON %s USING GIN (value public.gin_trgm_ops) WHERE is_searchable = TRUE',
			$projection,
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_localized_property_search_vector ON %s USING GIN (search_vector) WHERE is_searchable = TRUE',
			$projection,
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_localized_property_sort_und ON %s (site_id, object_type, property_name, query_locale, value COLLATE %s) INCLUDE (object_id)',
			$projection,
			$undCollation,
		));
		$this->addSql(sprintf(
			'CREATE TABLE %s (locale_tag VARCHAR(255) PRIMARY KEY, collation_name NAME NOT NULL UNIQUE, btree_index_name NAME NOT NULL UNIQUE, collation_version TEXT DEFAULT NULL, updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP)',
			$collations,
		));
		$this->addSql(sprintf(
			'INSERT INTO %s (locale_tag, collation_name, btree_index_name, collation_version) VALUES (\'und\', \'iikiti_locale_und\', \'idx_localized_property_sort_und\', pg_collation_actual_version(to_regcollation(%s)))',
			$collations,
			$this->connection->quote($schemaName.'.iikiti_locale_und'),
		));
	}

	public function down(Schema $schema): void
	{
		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || '' === $schemaName) {
			throw new \RuntimeException('DB_SCHEMA must be configured to remove localized property indexes.');
		}
		$schemaIdentifier = $this->connection->quoteIdentifier($schemaName);
		$this->addSql(sprintf('DROP TABLE %s', $schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_property_locale_index')));
		$this->addSql(sprintf('DROP TABLE %s', $schemaIdentifier.'.'.$this->connection->quoteIdentifier('object_locale_index_collation')));
		$this->addSql(sprintf('DROP COLLATION %s', $schemaIdentifier.'.'.$this->connection->quoteIdentifier('iikiti_locale_und')));
	}
}
