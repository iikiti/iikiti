<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015004000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Align workflow join-table indexes and optimistic-lock defaults with the ORM mappings.';
	}

	public function up(Schema $schema): void
	{
		$sites = $this->table('workflow_definition_sites');
		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->table('idx_workflow_definition_site')));
		$this->addSql(sprintf('CREATE INDEX IF NOT EXISTS IDX_52C3C1BCD11EA911 ON %s (definition_id)', $sites));
		$this->addSql(sprintf('CREATE INDEX IF NOT EXISTS IDX_52C3C1BCF6BD1646 ON %s (site_id)', $sites));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version SET DEFAULT 1', $this->table('workflow_definitions')));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version SET DEFAULT 1', $this->table('workflow_instances')));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version SET DEFAULT 1', $this->table('workflow_tasks')));
	}

	public function down(Schema $schema): void
	{
		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->table('IDX_52C3C1BCD11EA911')));
		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->table('IDX_52C3C1BCF6BD1646')));
		$this->addSql(sprintf('CREATE INDEX idx_workflow_definition_site ON %s (site_id)', $this->table('workflow_definition_sites')));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version DROP DEFAULT', $this->table('workflow_definitions')));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version DROP DEFAULT', $this->table('workflow_instances')));
		$this->addSql(sprintf('ALTER TABLE %s ALTER lock_version DROP DEFAULT', $this->table('workflow_tasks')));
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
