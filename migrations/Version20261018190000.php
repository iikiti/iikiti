<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018190000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add shared cooldown state for bounded post-response and CLI maintenance ticks.';
	}

	public function up(Schema $schema): void
	{
		$state = $this->table('maintenance_runner_state');
		$this->addSql(sprintf('CREATE TABLE %s (id SMALLINT NOT NULL, next_allowed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_maintenance_runner_singleton CHECK (id = 1))', $state));
		$this->addSql(sprintf("INSERT INTO %s (id, next_allowed_at) VALUES (1, '1970-01-01 00:00:00')", $state));
	}

	public function down(Schema $schema): void
	{
		$this->addSql(sprintf('DROP TABLE %s', $this->table('maintenance_runner_state')));
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
