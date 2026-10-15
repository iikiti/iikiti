<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigrationException;

final class Version20261015003000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Remove the superseded asset-specific workflow tables after generic workflow backfill and cutover.';
	}

	public function up(Schema $schema): void
	{
		$this->addSql(sprintf('DROP TABLE %s', $this->table('digital_asset_workflow_runs')));
		$this->addSql(sprintf('DROP TABLE %s', $this->table('digital_asset_workflows')));
	}

	public function down(Schema $schema): void
	{
		throw new IrreversibleMigrationException('Generic workflow instances may have received transitions after the asset cutover and cannot be safely reconstructed as legacy asset workflow runs.');
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
