<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds grouping, summary and username snapshot columns to audit log entries.
 *
 * Existing rows stay top-level (parent_id NULL) and keep a NULL summary; the
 * read side falls back to a generated description for them.
 */
final class Version20261008130000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add parent_id, summary and username_snapshot to audit_log_entries.';
	}

	public function up(Schema $schema): void
	{
		$table = $this->table('audit_log_entries');

		$this->addSql(sprintf('ALTER TABLE %s ADD parent_id BIGINT DEFAULT NULL', $table));
		$this->addSql(sprintf('ALTER TABLE %s ADD summary TEXT DEFAULT NULL', $table));
		$this->addSql(sprintf('ALTER TABLE %s ADD username_snapshot VARCHAR(180) DEFAULT NULL', $table));
		$this->addSql(sprintf('CREATE INDEX IDX_4D32F51D727ACA70 ON %s (parent_id)', $table));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT FK_audit_parent FOREIGN KEY (parent_id) REFERENCES %s (id) ON DELETE CASCADE',
			$table,
			$table
		));
	}

	public function down(Schema $schema): void
	{
		$table = $this->table('audit_log_entries');

		$this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT FK_audit_parent', $table));
		$this->addSql(sprintf('DROP INDEX IDX_4D32F51D727ACA70', $table));
		$this->addSql(sprintf('ALTER TABLE %s DROP COLUMN parent_id, DROP COLUMN summary, DROP COLUMN username_snapshot', $table));
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
