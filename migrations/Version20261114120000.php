<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Creates the csp_reports table for storing Content-Security-Policy violation reports. */
final class Version20261114120000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Create the csp_reports table for CSP violation reports.';
	}

	public function up(Schema $schema): void
	{
		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || '' === $schemaName || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
			throw new \RuntimeException('DB_SCHEMA must be configured as a simple PostgreSQL schema identifier.');
		}
		$table = $schemaName.'.'.$this->connection->quoteSingleIdentifier('csp_reports');

		$this->addSql(sprintf(
			'CREATE TABLE %s (
				id BIGINT NOT NULL,
				site_id BIGINT NOT NULL DEFAULT 0,
				user_agent VARCHAR(255) DEFAULT NULL,
				client_ip VARCHAR(64) DEFAULT NULL,
				document_uri VARCHAR(2048) DEFAULT NULL,
				referrer VARCHAR(2048) DEFAULT NULL,
				blocked_uri VARCHAR(2048) DEFAULT NULL,
				violated_directive VARCHAR(255) DEFAULT NULL,
				effective_directive VARCHAR(255) DEFAULT NULL,
				original_policy VARCHAR(1024) DEFAULT NULL,
				status_code SMALLINT DEFAULT NULL,
				report_body JSON NOT NULL,
				created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
				PRIMARY KEY (id)
			)',
			$table,
		));
		$this->addSql(sprintf(
			'CREATE SEQUENCE %s_id_seq INCREMENT BY 1 MINVALUE 1 START 1',
			$schemaName.'.'.$this->connection->quoteSingleIdentifier('csp_reports_id_seq'),
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ALTER COLUMN id SET DEFAULT nextval(%s)',
			$table,
			$this->connection->quoteSingleIdentifier($schemaName.'.csp_reports_id_seq'),
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_csp_reports_created ON %s (created_at)',
			$table,
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_csp_reports_site ON %s (site_id)',
			$table,
		));
		$this->addSql(sprintf(
			'CREATE INDEX idx_csp_reports_directive ON %s (violated_directive)',
			$table,
		));
	}

	public function down(Schema $schema): void
	{
		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || '' === $schemaName || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
			throw new \RuntimeException('DB_SCHEMA must be configured as a simple PostgreSQL schema identifier.');
		}
		$table = $schemaName.'.'.$this->connection->quoteSingleIdentifier('csp_reports');

		$this->addSql(sprintf('DROP TABLE %s', $table));
		$this->addSql(sprintf(
			'DROP SEQUENCE %s',
			$schemaName.'.'.$this->connection->quoteSingleIdentifier('csp_reports_id_seq'),
		));
	}
}
