<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seeds a default front-end block-editor template for the default site so the
 * live page and the `?edit` editor resolve against a real, saveable template.
 *
 * Template row uses the short discriminator `template` (see objects.type for
 * `site`/`user`). Site + creator are resolved by subquery against the seeded
 * default site / first user, so it is robust to seed ordering.
 */
final class Version20260923000000 extends AbstractMigration
{
	public function getDescription(): string
	{
		return 'Seed the default front-end block-editor template for the default site.';
	}

	public function up(Schema $schema): void
	{
		$prefix = $this->qualifiedPrefix();
		$props = $this->qualifiedProps();

		$exists = (bool) $this->connection->fetchOne(
			sprintf('SELECT 1 FROM %s WHERE type = :type LIMIT 1', $prefix),
			['type' => 'template']
		);
		if ($exists) {
			return;
		}

		$templateId = (int) $this->connection->fetchOne(
			sprintf('INSERT INTO %s (type, site_id, creator_id, created_date) VALUES (:type, (SELECT id FROM %s WHERE type = \'site\' ORDER BY id ASC LIMIT 1), (SELECT id FROM %s WHERE type = \'user\' ORDER BY id ASC LIMIT 1), NOW()) RETURNING id', $prefix, $prefix, $prefix),
			['type' => 'template']
		);

		$propertyRows = [
			['name' => 'title', 'value' => '"Default"'],
			['name' => 'layout', 'value' => '"base/default-block-editor-content.twig"'],
			['name' => 'regions', 'value' => $this->json([
				['id' => 'header', 'name' => 'Header', 'role' => 'header', 'allowed_types' => []],
				['id' => 'main', 'name' => 'Main content', 'role' => 'main', 'allowed_types' => []],
				['id' => 'aside-left', 'name' => 'Left sidebar', 'role' => 'sidebar', 'allowed_types' => []],
				['id' => 'aside-right', 'name' => 'Right sidebar', 'role' => 'sidebar', 'allowed_types' => []],
				['id' => 'footer', 'name' => 'Footer', 'role' => 'footer', 'allowed_types' => []],
			])],
			['name' => 'assignments', 'value' => $this->json([
				['rule' => 'site', 'config' => ['site_id' => '(default)'], 'priority' => 10],
				['rule' => 'object_type', 'config' => ['type' => 'page'], 'priority' => 5],
			])],
			['name' => 'blocks', 'value' => $this->json([
				'header' => [['id' => 'h-1', 'type' => 'heading', 'content' => ['level' => '2', 'text' => 'Welcome'], 'style' => ['base' => []]]],
				'main' => [['id' => 't-1', 'type' => 'text', 'content' => ['content' => '<p>Edit this page with <code>?edit</code>.</p>'], 'style' => ['base' => []]]],
			])],
			['name' => 'draft_version', 'value' => '0'],
		];

		foreach ($propertyRows as $row) {
			$this->addSql(
				sprintf('INSERT INTO %s (object_id, name, value, created) VALUES (:object, :name, :value, NOW())', $props),
				['object' => $templateId, 'name' => $row['name'], 'value' => $row['value']]
			);
		}
	}

	public function down(Schema $schema): void
	{
		$this->addSql(
			sprintf('DELETE FROM %s WHERE object_id IN (SELECT id FROM %s WHERE type = :type)', $this->qualifiedProps(), $this->qualifiedPrefix()),
			['type' => 'template']
		);
	}

	private function qualifiedPrefix(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.objects' : 'objects';
	}

	private function qualifiedProps(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

		return is_string($schema) && '' !== $schema ? $schema.'.object_properties' : 'object_properties';
	}

	/**
	 * @param mixed $value
	 */
	private function json($value): string
	{
		return "'" . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "'";
	}
}
