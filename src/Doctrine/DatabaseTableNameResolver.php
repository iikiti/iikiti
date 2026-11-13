<?php

declare(strict_types=1);

namespace iikiti\CMS\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DatabaseTableNameResolver
{
	private const TABLES = [
		'batch_jobs', 'batch_items', 'batch_item_dependencies', 'batch_item_dependency_refs', 'batch_events',
		'batch_schedules', 'batch_schedule_runs', 'maintenance_runner_state', 'site_transfer_identity', 'site_transfer_object_map',
		'site_transfer_credentials', 'object_identity', 'object_lookup', 'objects', 'object_properties', 'object_property_locale_index', 'object_locale_index_collation', 'digital_asset_site_grants',
	];

	private ?string $assetGrantSchema = null;
	private bool $assetGrantSchemaResolved = false;

	public function __construct(
		private readonly Connection $connection,
		#[Autowire('%env(DB_SCHEMA)%')]
		private readonly string $schema = '',
	) {
	}

	public function resolve(string $table): string
	{
		$qualifiedTable = $this->connection->quoteSingleIdentifier($table);
		$schema = 'digital_asset_site_grants' === $table ? ($this->getAssetGrantSchema() ?? $this->schema) : $this->schema;
		if ('' === $schema) {
			return $qualifiedTable;
		}

		return $this->connection->quoteSingleIdentifier($schema).'.'.$qualifiedTable;
	}

	public function getSchema(): string
	{
		return $this->schema;
	}

	public function hasAssetGrantTable(): bool
	{
		return null !== $this->getAssetGrantSchema();
	}

	public function qualifySql(string $sql): string
	{
		if ('' === $this->schema) {
			return $sql;
		}
		foreach (self::TABLES as $table) {
			$sql = preg_replace(
				'/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?=\\s|\\(|,|$)/i',
				$this->resolve($table),
				$sql,
			) ?? $sql;
		}

		return $sql;
	}

	private function getAssetGrantSchema(): ?string
	{
		if ($this->assetGrantSchemaResolved) {
			return $this->assetGrantSchema;
		}
		$this->assetGrantSchemaResolved = true;
		if ('' === $this->schema) {
			$this->assetGrantSchema = $this->tableExists('digital_asset_site_grants') ? '' : null;

			return $this->assetGrantSchema;
		}
		if ($this->tableExists($this->schema.'.digital_asset_site_grants')) {
			$this->assetGrantSchema = $this->schema;
		} elseif ($this->tableExists('public.digital_asset_site_grants')) {
			$this->assetGrantSchema = 'public';
		}

		return $this->assetGrantSchema;
	}

	private function tableExists(string $table): bool
	{
		$result = $this->connection->fetchOne('SELECT to_regclass(:table) IS NOT NULL', ['table' => $table]);

		return in_array($result, [true, 1, '1', 't', 'true'], true);
	}
}
