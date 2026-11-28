<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use iikiti\CMS\Doctrine\ObjectLookupViewBuilder;

/**
 * Adds DAM organization columns (folders, collections, tags) to `objects`,
 * refreshes the `object_lookup` view and triggers, and creates join tables
 * for folder site grants, collection items, and asset tags.
 */
final class Version20261128030000 extends AbstractMigration
{
	/** @var list<string> */
	private const NEW_OBJECT_COLUMNS = [
		'asset_folder_id',
		'folder_name',
		'folder_slug',
		'folder_path',
		'folder_parent_id',
		'collection_name',
		'collection_slug',
		'collection_description',
		'tag_name',
		'tag_slug',
		'tag_namespace',
	];

	public function getDescription(): string
	{
		return 'Add digital asset folders, collections, and tags columns on objects; refresh object_lookup; create organization join tables.';
	}

	public function up(Schema $schema): void
	{
		$viewBuilder = new ObjectLookupViewBuilder();
		$schemaName = $viewBuilder->resolveSchemaName($this->connection);

		$objects = $this->qualifiedTable($schemaName, 'objects');
		$identity = $this->qualifiedTable($schemaName, 'object_identity');
		$folderGrants = $this->qualifiedTable($schemaName, 'digital_asset_folder_site_grants');
		$collectionItems = $this->qualifiedTable($schemaName, 'digital_asset_collection_items');
		$assetTags = $this->qualifiedTable($schemaName, 'digital_asset_tags');

		$this->addSql(sprintf(
			'ALTER TABLE %s ADD COLUMN IF NOT EXISTS asset_folder_id BIGINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS folder_name VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS folder_slug VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS folder_path TEXT DEFAULT NULL, ADD COLUMN IF NOT EXISTS folder_parent_id BIGINT DEFAULT NULL, ADD COLUMN IF NOT EXISTS collection_name VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS collection_slug VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS collection_description TEXT DEFAULT NULL, ADD COLUMN IF NOT EXISTS tag_name VARCHAR(128) DEFAULT NULL, ADD COLUMN IF NOT EXISTS tag_slug VARCHAR(128) DEFAULT NULL, ADD COLUMN IF NOT EXISTS tag_namespace VARCHAR(64) DEFAULT NULL',
			$objects,
		));

		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_objects_asset_folder FOREIGN KEY (asset_folder_id) REFERENCES %s (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
			$objects,
			$identity,
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_objects_folder_parent FOREIGN KEY (folder_parent_id) REFERENCES %s (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
			$objects,
			$identity,
		));

		$this->addSql(sprintf('CREATE INDEX IF NOT EXISTS idx_objects_asset_folder_id ON %s (asset_folder_id)', $objects));
		$this->addSql(sprintf('CREATE INDEX IF NOT EXISTS idx_objects_folder_parent_id ON %s (folder_parent_id)', $objects));
		$this->addSql(sprintf('CREATE INDEX IF NOT EXISTS idx_objects_tag_namespace_slug ON %s (tag_namespace, tag_slug)', $objects));

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
			'CREATE TABLE %s (folder_id BIGINT NOT NULL, site_id BIGINT NOT NULL, PRIMARY KEY (folder_id, site_id))',
			$folderGrants,
		));
		$this->addSql(sprintf('CREATE INDEX idx_digital_asset_folder_site_grant_site ON %s (site_id)', $folderGrants));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_folder_site_grant_folder FOREIGN KEY (folder_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$folderGrants,
			$identity,
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_folder_site_grant_site FOREIGN KEY (site_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$folderGrants,
			$identity,
		));

		$this->addSql(sprintf(
			'CREATE TABLE %s (collection_id BIGINT NOT NULL, asset_id BIGINT NOT NULL, position INT DEFAULT 0 NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (collection_id, asset_id))',
			$collectionItems,
		));
		$this->addSql(sprintf('CREATE INDEX idx_digital_asset_collection_items_asset ON %s (asset_id)', $collectionItems));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_collection_items_collection FOREIGN KEY (collection_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$collectionItems,
			$identity,
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_collection_items_asset FOREIGN KEY (asset_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$collectionItems,
			$identity,
		));

		$this->addSql(sprintf(
			'CREATE TABLE %s (asset_id BIGINT NOT NULL, tag_id BIGINT NOT NULL, PRIMARY KEY (asset_id, tag_id))',
			$assetTags,
		));
		$this->addSql(sprintf('CREATE INDEX idx_digital_asset_tags_tag ON %s (tag_id)', $assetTags));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_tags_asset FOREIGN KEY (asset_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$assetTags,
			$identity,
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_digital_asset_tags_tag FOREIGN KEY (tag_id) REFERENCES %s (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
			$assetTags,
			$identity,
		));
	}

	public function down(Schema $schema): void
	{
		$viewBuilder = new ObjectLookupViewBuilder();
		$schemaName = $viewBuilder->resolveSchemaName($this->connection);

		$objects = $this->qualifiedTable($schemaName, 'objects');
		$lookup = $this->qualifiedTable($schemaName, 'object_lookup');
		$folderGrants = $this->qualifiedTable($schemaName, 'digital_asset_folder_site_grants');
		$collectionItems = $this->qualifiedTable($schemaName, 'digital_asset_collection_items');
		$assetTags = $this->qualifiedTable($schemaName, 'digital_asset_tags');

		$this->addSql(sprintf('DROP TABLE IF EXISTS %s', $assetTags));
		$this->addSql(sprintf('DROP TABLE IF EXISTS %s', $collectionItems));
		$this->addSql(sprintf('DROP TABLE IF EXISTS %s', $folderGrants));

		$existingColumns = $viewBuilder->fetchObjectColumns($this->connection, $schemaName);
		$remainingColumns = array_values(array_diff($existingColumns, self::NEW_OBJECT_COLUMNS));

		if ([] !== $remainingColumns) {
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_insert ON %s', $lookup));
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_update ON %s', $lookup));
			$this->addSql(sprintf('DROP TRIGGER IF EXISTS object_lookup_delete ON %s', $lookup));
			$this->addSql(sprintf('DROP VIEW IF EXISTS %s', $lookup));
		}

		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->qualifiedTable($schemaName, 'idx_objects_tag_namespace_slug')));
		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->qualifiedTable($schemaName, 'idx_objects_folder_parent_id')));
		$this->addSql(sprintf('DROP INDEX IF EXISTS %s', $this->qualifiedTable($schemaName, 'idx_objects_asset_folder_id')));
		$this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS fk_objects_folder_parent', $objects));
		$this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS fk_objects_asset_folder', $objects));
		$this->addSql(sprintf(
			'ALTER TABLE %s DROP COLUMN IF EXISTS asset_folder_id, DROP COLUMN IF EXISTS folder_name, DROP COLUMN IF EXISTS folder_slug, DROP COLUMN IF EXISTS folder_path, DROP COLUMN IF EXISTS folder_parent_id, DROP COLUMN IF EXISTS collection_name, DROP COLUMN IF EXISTS collection_slug, DROP COLUMN IF EXISTS collection_description, DROP COLUMN IF EXISTS tag_name, DROP COLUMN IF EXISTS tag_slug, DROP COLUMN IF EXISTS tag_namespace',
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
