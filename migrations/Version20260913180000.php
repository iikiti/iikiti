<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allow object properties to be created without an owning user, which the
 * plugin subsystem needs when enabling plugins per-site from the CLI/admin API.
 *
 * The statement is qualified with the DB_SCHEMA configured for application
 * entities (see the SchemaListener), defaulting to the search path.
 */
final class Version20260913180000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Make object_properties.creator_id nullable.';
	}

	public function up(Schema $schema): void
	{
		$this->addSql(sprintf('ALTER TABLE %s ALTER creator_id DROP NOT NULL', $this->qualifiedTable()));
	}

	public function down(Schema $schema): void
	{
		$this->addSql(sprintf('ALTER TABLE %s ALTER creator_id SET NOT NULL', $this->qualifiedTable()));
	}

	private function qualifiedTable(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.object_properties' : 'object_properties';
	}
}
