<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the nullable SHA-256 `checksum` column to `digital_asset_variants` so
 * generated variants support strong ETags and immutable versioned caching.
 */
final class Version20261128010000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add checksum column to digital_asset_variants.';
	}

	public function up(Schema $schema): void
	{
		$table = $this->qualifiedTable();
		$this->addSql(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) DEFAULT NULL', $table));
	}

	public function down(Schema $schema): void
	{
		$table = $this->qualifiedTable();
		$this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS checksum', $table));
	}

	private function qualifiedTable(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schema) || '' === $schema) {
			return 'digital_asset_variants';
		}
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
			throw new \RuntimeException('DB_SCHEMA must be a valid PostgreSQL schema identifier.');
		}

		return $this->connection->quoteSingleIdentifier($schema).'.'.$this->connection->quoteSingleIdentifier('digital_asset_variants');
	}
}
