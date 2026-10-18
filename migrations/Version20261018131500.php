<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018131500 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Add encrypted temporary source credentials for resumable site-transfer batches.';
	}

	public function up(Schema $schema): void
	{
		$credentials = $this->table('site_transfer_credentials');
		$jobs = $this->table('batch_jobs');
		$this->addSql(sprintf('CREATE TABLE %s (batch_job_id BIGINT NOT NULL, encrypted_token TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(batch_job_id))', $credentials));
		$this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT fk_site_transfer_credential_job FOREIGN KEY (batch_job_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE', $credentials, $jobs));
	}

	public function down(Schema $schema): void
	{
		$this->addSql(sprintf('DROP TABLE %s', $this->table('site_transfer_credentials')));
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
