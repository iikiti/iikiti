<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Selects Forma-2026 for templates still using the original generic layout. */
final class Version20261113010000 extends AbstractMigration
{
	private const string LEGACY_LAYOUT = 'base/default-block-editor-content.twig';
	private const string FORMA_LAYOUT = '@Forma2026/default-block-editor-content.twig';

	public function getDescription(): string
	{
		return 'Switch templates still using the generic default layout to Forma-2026.';
	}

	public function up(Schema $schema): void
	{
		$this->updateTemplateLayout(self::LEGACY_LAYOUT, self::FORMA_LAYOUT);
	}

	public function down(Schema $schema): void
	{
		$this->updateTemplateLayout(self::FORMA_LAYOUT, self::LEGACY_LAYOUT);
	}

	private function updateTemplateLayout(string $from, string $to): void
	{
		$schemaName = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schemaName) || '' === $schemaName || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schemaName)) {
			throw new \RuntimeException('DB_SCHEMA must be configured as a simple PostgreSQL schema identifier.');
		}
		$schemaIdentifier = $this->connection->quoteSingleIdentifier($schemaName);
		$properties = $schemaIdentifier.'.'.$this->connection->quoteSingleIdentifier('object_properties');
		$objects = $schemaIdentifier.'.'.$this->connection->quoteSingleIdentifier('objects');

		$this->addSql(sprintf(
			'UPDATE %s AS property SET value = CAST(:new_layout AS JSON) WHERE property.name = :property_name AND CAST(property.value AS JSONB) = CAST(:old_layout AS JSONB) AND EXISTS (SELECT 1 FROM %s AS object WHERE object.id = property.object_id AND object.type = :object_type)',
			$properties,
			$objects,
		), [
			'new_layout' => json_encode($to, JSON_THROW_ON_ERROR),
			'old_layout' => json_encode($from, JSON_THROW_ON_ERROR),
			'property_name' => 'layout',
			'object_type' => 'template',
		]);
	}
}
