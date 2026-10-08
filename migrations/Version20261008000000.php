<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Empties every stored block tree (published and draft) so regions render blank
 * for visual inspection. Region definitions are kept; only block content is
 * cleared. Non-container root nodes are removed by the same pass because the
 * tree is replaced wholesale.
 */
final class Version20261008000000 extends AbstractMigration
{
	private const BLOCK_PROPERTIES = ['blocks', 'blocks_draft'];

	public function getDescription(): string
	{
		return 'Clear stored block trees so all regions are empty.';
	}

	public function up(Schema $schema): void
	{
		$props = $this->qualifiedProps();
		foreach (self::BLOCK_PROPERTIES as $name) {
			$this->addSql(
				sprintf("UPDATE %s SET value = '{}' WHERE name = :name", $props),
				['name' => $name]
			);
		}
	}

	public function down(Schema $schema): void
	{
		// Content is intentionally not restorable; the previous trees were discarded.
	}

	private function qualifiedProps(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.object_properties' : 'object_properties';
	}
}
