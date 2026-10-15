<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015005000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Align explicit workflow definition-site assignment index names with the mapped assignment entity.';
	}

	public function up(Schema $schema): void
	{
		$schemaName = $this->schema();
		$this->addSql(sprintf('ALTER INDEX %s RENAME TO idx_a11e4eebd11ea911', $schemaName.'.idx_52c3c1bcd11ea911'));
		$this->addSql(sprintf('ALTER INDEX %s RENAME TO idx_a11e4eebf6bd1646', $schemaName.'.idx_52c3c1bcf6bd1646'));
	}

	public function down(Schema $schema): void
	{
		$schemaName = $this->schema();
		$this->addSql(sprintf('ALTER INDEX %s RENAME TO idx_52c3c1bcd11ea911', $schemaName.'.idx_a11e4eebd11ea911'));
		$this->addSql(sprintf('ALTER INDEX %s RENAME TO idx_52c3c1bcf6bd1646', $schemaName.'.idx_a11e4eebf6bd1646'));
	}

	private function schema(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema : 'public';
	}
}
