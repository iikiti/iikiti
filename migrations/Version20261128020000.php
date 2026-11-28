<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use iikiti\CMS\Doctrine\ObjectLookupViewBuilder;

/**
 * Adds rich image/media metadata columns to `objects`, refreshes the `object_lookup`
 * view and triggers, adds format/quality/focal_crop columns to `digital_asset_variants`,
 * seeds the default `image-metadata` asset workflow, and upgrades the default
 * `image-thumbnails` workflow options for responsive AVIF/WebP variants.
 */
final class Version20261128020000 extends AbstractMigration
{
	/** @var list<string> */
	private const NEW_OBJECT_COLUMNS = [
		'asset_width',
		'asset_height',
		'asset_focal_x',
		'asset_focal_y',
		'asset_dominant_color',
		'asset_blurhash',
		'asset_duration_ms',
		'asset_media_metadata',
	];

	private const LEGACY_THUMBNAIL_OPTIONS = '{"variants":[{"name":"thumbnail","width":320,"height":320},{"name":"preview","width":1280,"height":1280}]}';

	private const UPGRADED_THUMBNAIL_OPTIONS = '{"variants":[{"name":"thumbnail","width":320,"height":320},{"name":"preview","width":1280,"height":1280}],"responsive_widths":[320,640,960,1280,1920],"formats":["avif","webp","original"]}';

	public function getDescription(): string
	{
		return 'Add image media metadata, focal point, BlurHash, and variant format columns; refresh object_lookup; seed image-metadata workflow.';
	}

	public function up(Schema $schema): void
	{
		$viewBuilder = new ObjectLookupViewBuilder();
		$schemaName = $viewBuilder->resolveSchemaName($this->connection);

		$objects = $this->qualifiedTable($schemaName, 'objects');
		$variants = $this->qualifiedTable($schemaName, 'digital_asset_variants');
		$definitions = $this->qualifiedTable($schemaName, 'workflow_definitions');
		$versions = $this->qualifiedTable($schemaName, 'workflow_definition_versions');

		$this->addSql(sprintf(
			'ALTER TABLE %s ADD COLUMN IF NOT EXISTS asset_width INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_height INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_focal_x DOUBLE PRECISION DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_focal_y DOUBLE PRECISION DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_dominant_color VARCHAR(9) DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_blurhash VARCHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_duration_ms INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS asset_media_metadata JSONB DEFAULT NULL',
			$objects,
		));

		$columns = $viewBuilder->fetchObjectColumns($this->connection, $schemaName);
		if ([] !== $columns) {
			foreach (self::NEW_OBJECT_COLUMNS as $column) {
				if (!in_array($column, $columns, true)) {
					$columns[] = $column;
				}
			}
			foreach ($viewBuilder->buildRefreshSql($this->connection, $schemaName, $columns) as $statement) {
				$this->addSql($statement);
			}
		}

		$this->addSql(sprintf(
			'ALTER TABLE %s ADD COLUMN IF NOT EXISTS format VARCHAR(16) DEFAULT NULL, ADD COLUMN IF NOT EXISTS quality SMALLINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS focal_crop BOOLEAN DEFAULT false NOT NULL',
			$variants,
		));

		$this->addSql(sprintf(
			"INSERT INTO %s (workflow_key, name, owner_type, owner_key, current_version, is_enabled, is_archived, lock_version, created_at, updated_at) VALUES ('image-metadata', 'Image metadata extraction', 'core', 'core', 1, true, false, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON CONFLICT (owner_type, owner_key, workflow_key) DO NOTHING",
			$definitions,
		));

		$metadataGraph = json_encode([
			'type' => 'workflow',
			'places' => ['pending', 'running', 'complete', 'failed', 'skipped'],
			'transitions' => [
				[
					'name' => 'start',
					'from' => ['pending', 'running', 'failed', 'skipped'],
					'to' => ['running'],
					'metadata' => [
						'navigation' => 'forward',
						'assetOperation' => 'start',
						'incrementAttempts' => true,
						'clearLastError' => true,
					],
				],
				[
					'name' => 'complete',
					'from' => ['running'],
					'to' => ['complete'],
					'metadata' => [
						'assetOperation' => 'complete',
						'clearLastError' => true,
					],
				],
				[
					'name' => 'fail',
					'from' => ['running'],
					'to' => ['failed'],
					'metadata' => [
						'assetOperation' => 'fail',
						'setLastError' => true,
					],
				],
				[
					'name' => 'skip',
					'from' => ['running'],
					'to' => ['skipped'],
					'metadata' => [
						'assetOperation' => 'skip',
						'clearLastError' => true,
					],
				],
			],
			'initialPlaces' => ['pending'],
			'finalPlaces' => ['complete'],
			'metadata' => [
				'policyKey' => 'core.asset_processor',
				'assetProcessor' => [
					'processorKey' => 'image_metadata',
					'mimePattern' => 'image/*',
					'conditions' => new \stdClass(),
					'options' => ['preserve_gps' => false],
					'allSites' => true,
				],
			],
		], JSON_THROW_ON_ERROR);

		$this->addSql(sprintf(
			"INSERT INTO %s (definition_id, version, graph, policy_key, source_hash, created_by_user_id, created_at) SELECT d.id, 1, CAST(:graph AS JSON), 'core.asset_processor', NULL, NULL, CURRENT_TIMESTAMP FROM %s d WHERE d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = 'image-metadata' ON CONFLICT (definition_id, version) DO NOTHING",
			$versions,
			$definitions,
		), [
			'graph' => $metadataGraph,
		]);

		$this->addSql(sprintf(
			"UPDATE %s v SET graph = jsonb_set(v.graph::jsonb, '{metadata,assetProcessor,options}', CAST(:upgradedOptions AS JSONB))::json FROM %s d WHERE v.definition_id = d.id AND v.version = d.current_version AND d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = 'image-thumbnails' AND v.graph::jsonb #> '{metadata,assetProcessor,options}' = CAST(:legacyOptions AS JSONB)",
			$versions,
			$definitions,
		), [
			'upgradedOptions' => self::UPGRADED_THUMBNAIL_OPTIONS,
			'legacyOptions' => self::LEGACY_THUMBNAIL_OPTIONS,
		]);
	}

	public function down(Schema $schema): void
	{
		$viewBuilder = new ObjectLookupViewBuilder();
		$schemaName = $viewBuilder->resolveSchemaName($this->connection);

		$objects = $this->qualifiedTable($schemaName, 'objects');
		$lookup = $this->qualifiedTable($schemaName, 'object_lookup');
		$variants = $this->qualifiedTable($schemaName, 'digital_asset_variants');
		$definitions = $this->qualifiedTable($schemaName, 'workflow_definitions');
		$versions = $this->qualifiedTable($schemaName, 'workflow_definition_versions');
		$instances = $this->qualifiedTable($schemaName, 'workflow_instances');

		$this->addSql(sprintf(
			"UPDATE %s v SET graph = jsonb_set(v.graph::jsonb, '{metadata,assetProcessor,options}', CAST(:legacyOptions AS JSONB))::json FROM %s d WHERE v.definition_id = d.id AND v.version = d.current_version AND d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = 'image-thumbnails' AND v.graph::jsonb #> '{metadata,assetProcessor,options}' = CAST(:upgradedOptions AS JSONB)",
			$versions,
			$definitions,
		), [
			'legacyOptions' => self::LEGACY_THUMBNAIL_OPTIONS,
			'upgradedOptions' => self::UPGRADED_THUMBNAIL_OPTIONS,
		]);

		$this->addSql(sprintf(
			"DELETE FROM %s d WHERE d.owner_type = 'core' AND d.owner_key = 'core' AND d.workflow_key = 'image-metadata' AND NOT EXISTS (SELECT 1 FROM %s i INNER JOIN %s v ON v.id = i.definition_version_id WHERE v.definition_id = d.id)",
			$definitions,
			$instances,
			$versions,
		));

		$this->addSql(sprintf(
			'ALTER TABLE %s DROP COLUMN IF EXISTS format, DROP COLUMN IF EXISTS quality, DROP COLUMN IF EXISTS focal_crop',
			$variants,
		));

		$existingColumns = $viewBuilder->fetchObjectColumns($this->connection, $schemaName);
		$remainingColumns = array_values(array_diff($existingColumns, self::NEW_OBJECT_COLUMNS));

		if ([] !== $remainingColumns) {
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_insert ON %s', $lookup));
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_update ON %s', $lookup));
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_delete ON %s', $lookup));
			$this->addSql(sprintf('DROP VIEW IF EXISTS %s', $lookup));
		}

		$this->addSql(sprintf(
			'ALTER TABLE %s DROP COLUMN IF EXISTS asset_width, DROP COLUMN IF EXISTS asset_height, DROP COLUMN IF EXISTS asset_focal_x, DROP COLUMN IF EXISTS asset_focal_y, DROP COLUMN IF EXISTS asset_dominant_color, DROP COLUMN IF EXISTS asset_blurhash, DROP COLUMN IF EXISTS asset_duration_ms, DROP COLUMN IF EXISTS asset_media_metadata',
			$objects,
		));

		if ([] !== $remainingColumns) {
			foreach ($viewBuilder->buildCreateSql($this->connection, $schemaName, $remainingColumns, replaceFunctions: true) as $statement) {
				$this->addSql($statement);
			}
		}
	}

	private function qualifiedTable(string $schemaName, string $tableName): string
	{
		return $this->connection->quoteSingleIdentifier($schemaName).'.'.$this->connection->quoteSingleIdentifier($tableName);
	}
}
