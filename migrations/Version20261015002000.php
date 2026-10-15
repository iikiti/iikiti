<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigrationException;

final class Version20261015002000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Backfill digital asset workflow definitions and runs into the generic workflow catalog and instance store.';
	}

	public function up(Schema $schema): void
	{
		$legacyDefinitions = $this->table('digital_asset_workflows');
		$legacyRuns = $this->table('digital_asset_workflow_runs');
		$definitions = $this->table('workflow_definitions');
		$versions = $this->table('workflow_definition_versions');
		$instances = $this->table('workflow_instances');

		$this->addSql(sprintf(
			"INSERT INTO %s (workflow_key, name, owner_type, owner_key, current_version, is_enabled, is_archived, lock_version, created_at, updated_at) SELECT workflow_key, name, 'core', 'core', 1, is_enabled, false, 1, created_at, updated_at FROM %s ORDER BY id ASC ON CONFLICT (owner_type, owner_key, workflow_key) DO NOTHING",
			$definitions,
			$legacyDefinitions,
		));

		$this->addSql(sprintf(
			"INSERT INTO %s (definition_id, version, graph, policy_key, source_hash, created_by_user_id, created_at) SELECT d.id, 1, json_build_object('type', 'workflow', 'places', json_build_array('pending', 'running', 'complete', 'failed', 'skipped'), 'transitions', json_build_array(json_build_object('name', 'start', 'from', json_build_array('pending', 'running', 'failed', 'skipped'), 'to', json_build_array('running'), 'metadata', json_build_object('navigation', 'forward', 'incrementAttempts', true, 'clearLastError', true)), json_build_object('name', 'complete', 'from', json_build_array('running'), 'to', json_build_array('complete'), 'metadata', json_build_object('clearLastError', true)), json_build_object('name', 'fail', 'from', json_build_array('running'), 'to', json_build_array('failed'), 'metadata', json_build_object('setLastError', true)), json_build_object('name', 'skip', 'from', json_build_array('running'), 'to', json_build_array('skipped'), 'metadata', json_build_object('clearLastError', true))), 'initialPlaces', json_build_array('pending'), 'finalPlaces', json_build_array('complete'), 'metadata', json_build_object('policyKey', 'core.asset_processor', 'assetProcessor', json_build_object('processorKey', old.processor_key, 'mimePattern', old.mime_pattern, 'conditions', old.conditions, 'options', old.options, 'allSites', true))), 'core.asset_processor', NULL, NULL, old.created_at FROM %s old JOIN %s d ON d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = old.workflow_key ON CONFLICT (definition_id, version) DO NOTHING",
			$versions,
			$legacyDefinitions,
			$definitions,
		));

		$this->addSql(sprintf(
			"INSERT INTO %s (definition_version_id, site_id, subject_type, subject_id, marking, context, execution_state, pause_reason, pause_type, idempotency_key, attempts, last_error, lock_version, created_at, updated_at) SELECT v.id, NULL, 'digital_asset', old_run.asset_id::text, json_build_object(old_run.status, 1), '{}'::json, 'active', NULL, 'manual', concat('asset:', old_run.asset_id::text, ':', d.id::text), old_run.attempts, old_run.last_error, 1, old_run.updated_at, old_run.updated_at FROM %s old_run JOIN %s old_definition ON old_definition.id = old_run.workflow_id JOIN %s d ON d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = old_definition.workflow_key JOIN %s v ON v.definition_id = d.id AND v.version = 1 ON CONFLICT (idempotency_key) DO NOTHING",
			$instances,
			$legacyRuns,
			$legacyDefinitions,
			$definitions,
			$versions,
		));
	}

	public function down(Schema $schema): void
	{
		throw new IrreversibleMigrationException('Backfilled workflow instances and definitions are durable runtime state and cannot be removed by an automatic migration rollback.');
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
