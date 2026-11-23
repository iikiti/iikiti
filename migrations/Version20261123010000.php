<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the nullable JSON `discriminators` column to `plugin_registry` so
 * retained DbObject STI types from unloaded plugins can be tracked safely.
 */
final class Version20261123010000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add discriminators column to plugin_registry.';
	}

	public function up(Schema $schema): void
	{
		$table = $this->qualifiedTable();
		$this->addSql(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS discriminators JSON DEFAULT NULL', $table));
	}

	public function down(Schema $schema): void
	{
		$table = $this->qualifiedTable();
		$this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS discriminators', $table));
	}

	private function qualifiedTable(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schema) || '' === $schema) {
			return 'plugin_registry';
		}
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
			throw new \RuntimeException('DB_SCHEMA must be a valid PostgreSQL schema identifier.');
		}

		return $this->connection->quoteSingleIdentifier($schema).'.'.$this->connection->quoteSingleIdentifier('plugin_registry');
	}
}
