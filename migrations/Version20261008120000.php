<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use iikiti\CMS\Web\Template\ShellFromRegionMapper;

/**
 * Converts existing non-main region content into global shells.
 *
 * Header, footer, sidebar and dialog regions are now shells. For each
 * template's non-main region that still stores blocks (`blocks`), a Shell row
 * is created with that region's blocks and a site-wide display rule for the
 * template's site, so the content keeps rendering. The source region data is
 * left in place; nothing is dropped.
 */
final class Version20261008120000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Convert non-main template regions with blocks into global shells.';
	}

	public function up(Schema $schema): void
	{
		$objects = $this->table('objects');
		$props = $this->table('object_properties');

		// Each template's regions and blocks. Only non-main regions become shells.
		// objects.site_id and objects.creator_id are NOT NULL (creator_id is a FK to
		// objects). Shells inherit both from their source template; a template without
		// them cannot be converted and is skipped instead of inserting invalid rows.
		$templates = $this->connection->fetchAllAssociative(
			sprintf(
				"SELECT o.id, o.site_id, o.creator_id FROM %s o WHERE o.type = 'Template' AND o.site_id IS NOT NULL AND o.creator_id IS NOT NULL",
				$objects
			)
		);

		foreach ($templates as $template) {
			$regions = $this->propertyValue((int) $template['id'], 'regions', $props);
			$blocks = $this->propertyValue((int) $template['id'], 'blocks', $props);
			if (!is_array($regions) || !is_array($blocks)) {
				continue;
			}

			foreach (ShellFromRegionMapper::shellsFromRegions($regions, $blocks) as $shell) {
				$this->insertShell(
					siteId: (int) $template['site_id'],
					creatorId: (int) $template['creator_id'],
					role: $shell['role'],
					name: $shell['name'],
					priority: $shell['priority'],
					blocks: $shell['blocks'],
				);
			}
		}
	}

	public function down(Schema $schema): void
	{
		// Converted shells are removed; the original region data was kept intact.
		$this->addSql(sprintf("DELETE FROM %s WHERE type = 'Shell'", $this->table('objects')));
	}

	/**
	 * Inserts one shell row plus its properties. Runs immediately (not queued as
	 * SQL) because the new id is needed for the property rows.
	 *
	 * @param list<array<string,mixed>> $blocks
	 */
	private function insertShell(int $siteId, int $creatorId, string $role, string $name, int $priority, array $blocks): void
	{
		$objects = $this->table('objects');
		$props = $this->table('object_properties');

		$shellId = (int) $this->connection->fetchOne(
			sprintf(
				"INSERT INTO %s (type, site_id, creator_id, created_date) VALUES ('Shell', ?, ?, NOW()) RETURNING id",
				$objects
			),
			[$siteId, $creatorId]
		);

		$shellProps = [
			'role' => $role,
			'name' => $name,
			'priority' => $priority,
			'enabled' => true,
			'display_rules' => [['rule' => 'site', 'config' => ['site_id' => $siteId]]],
			'blocks' => $blocks,
		];
		foreach ($shellProps as $key => $value) {
			$this->connection->executeStatement(
				sprintf('INSERT INTO %s (object_id, name, value) VALUES (?, ?, ?)', $props),
				[$shellId, $key, json_encode($value, JSON_THROW_ON_ERROR)]
			);
		}
	}

	private function propertyValue(int $objectId, string $name, string $props): mixed
	{
		$raw = $this->connection->fetchOne(
			sprintf('SELECT value FROM %s WHERE object_id = ? AND name = ?', $props),
			[$objectId, $name]
		);

		return false === $raw || null === $raw ? null : json_decode((string) $raw, true);
	}

	private function table(string $name): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.'.$name : $name;
	}
}
