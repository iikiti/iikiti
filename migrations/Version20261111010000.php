<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111010000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add secret markers to object properties and wrapped per-site secrets keys.';
	}

	public function up(Schema $schema): void
	{
		$this->addSql(sprintf('ALTER TABLE %s ADD secret BOOLEAN NOT NULL DEFAULT FALSE', $this->table('object_properties')));
		$this->addSql(sprintf(
			'CREATE TABLE %s (scope_key VARCHAR(64) NOT NULL, site_id BIGINT DEFAULT NULL, wrapped_master_key TEXT NOT NULL, wrapping_key_id VARCHAR(64) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(scope_key), CONSTRAINT uniq_secret_site_keys_site_id UNIQUE (site_id), CONSTRAINT fk_secret_site_keys_site FOREIGN KEY (site_id) REFERENCES %s (id) ON DELETE CASCADE, CONSTRAINT chk_secret_site_keys_scope CHECK ((scope_key = \'global\' AND site_id IS NULL) OR (scope_key LIKE \'site:%%\' AND site_id IS NOT NULL)))',
			$this->table('secret_site_keys'),
			$this->table('objects'),
		));
	}

	public function down(Schema $schema): void
	{
		$this->addSql(sprintf('DROP TABLE %s', $this->table('secret_site_keys')));
		$this->addSql(sprintf('ALTER TABLE %s DROP secret', $this->table('object_properties')));
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
