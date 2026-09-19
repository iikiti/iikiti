<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds default search index configurations:
 *
 * 1. A "frontend" search index (deletable) — the default front-end search
 *    configuration with common title/body/content property fields.
 * 2. An "admin" search index (system-locked, non-deletable) — covers all
 *    searchable properties and object metadata (type, created_date, etc.)
 *    Fields can be added/modified/removed through the admin UI.
 *
 * Both indexes use the PostgreSQL full-text search engine with a default
 * "standard" analyzer (lowercase tokenizer) and English text search config.
 */
final class Version20260919123000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Seed default frontend and admin search index configurations.';
	}

	public function up(Schema $schema): void
	{
		$prefix = $this->qualifiedPrefix();

		// --- Default "standard" analyzer layers (shared by both indexes) ---

		$this->addSql(sprintf(
			"INSERT INTO %ssearch_analyzer_layers (name, search_index_id, field_id, type, position, options) VALUES ('standard', NULL, NULL, 'lowercase', 1, '{}')",
			$prefix
		));
		$this->addSql(sprintf(
			"INSERT INTO %ssearch_analyzer_layers (name, search_index_id, field_id, type, position, options) VALUES ('standard', NULL, NULL, 'stop', 2, '{\"words\": [\"the\",\"a\",\"an\",\"of\",\"to\",\"in\",\"on\",\"at\",\"for\",\"with\",\"by\",\"from\",\"is\",\"was\",\"are\",\"were\",\"been\",\"has\",\"have\",\"had\",\"do\",\"does\",\"did\",\"will\",\"would\",\"could\",\"should\",\"may\",\"might\",\"must\",\"shall\",\"can\",\"need\"]}')",
			$prefix
		));
		$this->addSql(sprintf(
			"INSERT INTO %ssearch_analyzer_layers (name, search_index_id, field_id, type, position, options) VALUES ('standard', NULL, NULL, 'stemmer', 3, '{\"algorithm\": \"english\"}')",
			$prefix
		));

		// --- Frontend search index (deletable) ---

		$frontendId = (int) $this->connection->fetchOne(sprintf(
			"INSERT INTO %ssearch_indexes (slug, name, type, engine, language, description, system_locked, is_enabled, site_id, config_group_id, options, created_at, updated_at) VALUES ('frontend', 'Front-end Search', 'frontend', 'postgresql', 'english', 'Default front-end search index for public site search.', false, true, NULL, NULL, '[]', NOW(), NOW()) RETURNING id",
			$prefix
		));

		// Frontend fields: common content properties
		$frontendFields = [
			['title', 'property', 'title', 4],
			['content', 'property', 'content', 3],
			['body', 'property', 'body', 3],
			['excerpt', 'property', 'excerpt', 2],
			['tags', 'property', 'tags', 2],
			['slug', 'property', 'slug', 1],
			['type', 'column', 'type', 1],
		];

		$position = 1;
		foreach ($frontendFields as [$name, $sourceType, $source, $weight]) {
			$this->addSql(sprintf(
				"INSERT INTO %ssearch_index_fields (search_index_id, name, source_type, source, data_type, weight, position) VALUES (%d, '%s', '%s', '%s', 'text', %d, %d)",
				$prefix, $frontendId, $name, $sourceType, $source, $weight, $position
			));
			$position++;
		}

		// --- Admin search index (system-locked, non-deletable) ---

		$adminId = (int) $this->connection->fetchOne(sprintf(
			"INSERT INTO %ssearch_indexes (slug, name, type, engine, language, description, system_locked, is_enabled, site_id, config_group_id, options, created_at, updated_at) VALUES ('admin', 'Administration Search', 'admin', 'postgresql', 'english', 'System-level administration search index. This index is system-locked and cannot be deleted, but its fields can be managed through the admin UI.', true, true, NULL, NULL, '[]', NOW(), NOW()) RETURNING id",
			$prefix
		));

		// Admin fields: comprehensive coverage including metadata
		$adminFields = [
			['title', 'property', 'title', 4],
			['content', 'property', 'content', 3],
			['body', 'property', 'body', 3],
			['excerpt', 'property', 'excerpt', 2],
			['tags', 'property', 'tags', 2],
			['slug', 'property', 'slug', 1],
			['type', 'column', 'type', 2],
			['id', 'column', 'id', 1],
			['created_date', 'column', 'created_date', 1],
			['keywords', 'property', 'keywords', 2],
			['meta_description', 'property', 'meta_description', 2],
		];

		$position = 1;
		foreach ($adminFields as [$name, $sourceType, $source, $weight]) {
			$this->addSql(sprintf(
				"INSERT INTO %ssearch_index_fields (search_index_id, name, source_type, source, data_type, weight, position) VALUES (%d, '%s', '%s', '%s', 'text', %d, %d)",
				$prefix, $adminId, $name, $sourceType, $source, $weight, $position
			));
			$position++;
		}

		// Admin filter: a default role-restricted filter for searching by type
		$this->addSql(sprintf(
			"INSERT INTO %ssearch_filters (search_index_id, name, label, mode, visibility, required_roles, hook, options, is_enabled) VALUES (%d, 'by_object_type', 'Object Type', 'query_time', 'role_restricted', '[\"ROLE_ADMIN\"]', 'iikiti.cms.search.filter:objectType', '{}', true)",
			$prefix, $adminId
		));

		// Default config group containing both indexes
		$this->addSql(sprintf(
			"INSERT INTO %ssearch_config_groups (name, label, description, is_system, site_id, search_index_ids, site_assignments, site_group_assignments) VALUES ('default', 'Default Search Configuration', 'Default search configuration group containing frontend and admin search indexes.', true, NULL, '[\"%d\",\"%d\"]', '[]', '[]')",
			$prefix, $frontendId, $adminId
		));

		// Default site group
		$this->addSql(sprintf(
			"INSERT INTO %ssearch_site_groups (name, label, description, is_system, site_ids) VALUES ('default', 'All Sites', 'Default site group containing all sites.', true, '[]')",
			$prefix
		));
	}

	public function down(Schema $schema): void
	{
		$prefix = $this->qualifiedPrefix();

		$this->addSql(sprintf('DELETE FROM %ssearch_filters WHERE search_index_id IN (SELECT id FROM %ssearch_indexes WHERE slug IN (\'frontend\', \'admin\'))', $prefix, $prefix));
		$this->addSql(sprintf('DELETE FROM %ssearch_index_fields WHERE search_index_id IN (SELECT id FROM %ssearch_indexes WHERE slug IN (\'frontend\', \'admin\'))', $prefix, $prefix));
		$this->addSql(sprintf('DELETE FROM %ssearch_analyzer_layers WHERE name = \'standard\' AND search_index_id IS NULL', $prefix));
		$this->addSql(sprintf('DELETE FROM %ssearch_config_groups WHERE name = \'default\'', $prefix));
		$this->addSql(sprintf('DELETE FROM %ssearch_site_groups WHERE name = \'default\'', $prefix));
		$this->addSql(sprintf('DELETE FROM %ssearch_indexes WHERE slug IN (\'frontend\', \'admin\')', $prefix));
	}

	private function qualifiedPrefix(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.' : '';
	}
}
