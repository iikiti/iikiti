<?php

declare(strict_types=1);

namespace iikiti\CMS\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Builds PostgreSQL DDL statements to create or refresh the `object_lookup` view
 * and its INSTEAD OF triggers whenever columns are added to or removed from `objects`.
 */
final class ObjectLookupViewBuilder
{
	/** @var list<string> */
	public const IDENTITY_COLUMNS = [
		'token',
		'slug',
		'asset_public_token',
		'icon_set_id',
		'icon_name',
		'api_token_user_id',
	];

	private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * Builds SQL statements to drop and recreate the `object_lookup` view along with
	 * `iikiti_object_lookup_insert`, `iikiti_object_lookup_update`, and
	 * `iikiti_object_lookup_delete` trigger functions and triggers.
	 *
	 * @param list<string> $objectColumns
	 * @param list<string> $identityColumns
	 *
	 * @return list<string>
	 */
	public function buildRefreshSql(
		Connection $connection,
		string $schemaName,
		array $objectColumns,
		array $identityColumns = self::IDENTITY_COLUMNS,
	): array {
		$this->assertValidIdentifier($schemaName, 'schema');
		$lookup = $this->qualifiedName($connection, $schemaName, 'object_lookup');

		return [
			sprintf('DROP TRIGGER IF EXISTS object_lookup_insert ON %s', $lookup),
			sprintf('DROP TRIGGER IF EXISTS object_lookup_update ON %s', $lookup),
			sprintf('DROP TRIGGER IF EXISTS object_lookup_delete ON %s', $lookup),
			sprintf('DROP VIEW IF EXISTS %s', $lookup),
			...$this->buildCreateSql($connection, $schemaName, $objectColumns, $identityColumns, replaceFunctions: true),
		];
	}

	/**
	 * Builds SQL statements to create the `object_lookup` view and its INSTEAD OF
	 * trigger functions and triggers, matching the partitioned schema layout.
	 *
	 * @param list<string> $objectColumns
	 * @param list<string> $identityColumns
	 *
	 * @return list<string>
	 */
	public function buildCreateSql(
		Connection $connection,
		string $schemaName,
		array $objectColumns,
		array $identityColumns = self::IDENTITY_COLUMNS,
		bool $replaceFunctions = false,
	): array {
		$this->assertValidIdentifier($schemaName, 'schema');
		$this->assertValidColumns($objectColumns, $identityColumns);

		$identity = $this->qualifiedName($connection, $schemaName, 'object_identity');
		$objects = $this->qualifiedName($connection, $schemaName, 'objects');
		$lookup = $this->qualifiedName($connection, $schemaName, 'object_lookup');

		$select = [];
		foreach ($objectColumns as $column) {
			$quoted = $this->quoteIdentifier($connection, $column);
			if ('id' === $column || in_array($column, $identityColumns, true)) {
				$select[] = sprintf('identity.%s AS %s', $quoted, $quoted);
			} else {
				$select[] = sprintf('row.%s AS %s', $quoted, $quoted);
			}
		}

		$insertFunction = $this->qualifiedName($connection, $schemaName, 'iikiti_object_lookup_insert');
		$updateFunction = $this->qualifiedName($connection, $schemaName, 'iikiti_object_lookup_update');
		$deleteFunction = $this->qualifiedName($connection, $schemaName, 'iikiti_object_lookup_delete');

		$allIdentityColumns = ['site_id', 'created_date', ...$identityColumns];
		$identityValues = array_map(
			fn (string $column): string => 'NEW.'.$this->quoteIdentifier($connection, $column),
			$allIdentityColumns,
		);
		$insertColumns = $this->quotedColumns($connection, $objectColumns);
		$insertValues = implode(', ', array_map(
			fn (string $column): string => 'NEW.'.$this->quoteIdentifier($connection, $column),
			$objectColumns,
		));

		$identityAssignments = [];
		foreach ($allIdentityColumns as $column) {
			$quoted = $this->quoteIdentifier($connection, $column);
			$identityAssignments[] = sprintf('%s = NEW.%s', $quoted, $quoted);
		}

		$rowAssignments = [];
		foreach ($objectColumns as $column) {
			if (in_array($column, ['id', 'site_id', 'created_date'], true)) {
				continue;
			}
			$quoted = $this->quoteIdentifier($connection, $column);
			$rowAssignments[] = sprintf('%s = NEW.%s', $quoted, $quoted);
		}

		$sequence = $connection->quote($schemaName.'.object_identity');
		$functionKeyword = $replaceFunctions ? 'CREATE OR REPLACE FUNCTION' : 'CREATE FUNCTION';

		return [
			sprintf(
				'CREATE VIEW %s AS SELECT %s FROM %s identity INNER JOIN %s row ON row.id = identity.id AND row.site_id = identity.site_id AND row.created_date = identity.created_date',
				$lookup,
				implode(', ', $select),
				$identity,
				$objects,
			),
			sprintf(
				'%s %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ DECLARE sequence_name REGCLASS; BEGIN IF NEW.id IS NULL THEN INSERT INTO %s (%s) VALUES (%s) RETURNING id INTO NEW.id; ELSE INSERT INTO %s (id, %s) VALUES (NEW.id, %s); sequence_name := pg_get_serial_sequence(%s, \'id\')::REGCLASS; PERFORM setval(sequence_name, GREATEST(NEW.id, COALESCE(pg_sequence_last_value(sequence_name), 0)), true); END IF; INSERT INTO %s (%s) VALUES (%s); RETURN NEW; END $iikiti$',
				$functionKeyword,
				$insertFunction,
				$identity,
				$this->quotedColumns($connection, $allIdentityColumns),
				implode(', ', $identityValues),
				$identity,
				$this->quotedColumns($connection, $allIdentityColumns),
				implode(', ', $identityValues),
				$sequence,
				$objects,
				$insertColumns,
				$insertValues,
			),
			sprintf(
				'%s %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN IF NEW.id <> OLD.id THEN RAISE EXCEPTION \'object IDs are immutable\'; END IF; UPDATE %s SET %s WHERE id = OLD.id; UPDATE %s SET %s WHERE id = NEW.id AND site_id = NEW.site_id AND created_date = NEW.created_date; RETURN NEW; END $iikiti$',
				$functionKeyword,
				$updateFunction,
				$identity,
				implode(', ', $identityAssignments),
				$objects,
				implode(', ', $rowAssignments),
			),
			sprintf(
				'%s %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN DELETE FROM %s WHERE id = OLD.id; RETURN OLD; END $iikiti$',
				$functionKeyword,
				$deleteFunction,
				$identity,
			),
			sprintf('CREATE TRIGGER object_lookup_insert INSTEAD OF INSERT ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $insertFunction),
			sprintf('CREATE TRIGGER object_lookup_update INSTEAD OF UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $updateFunction),
			sprintf('CREATE TRIGGER object_lookup_delete INSTEAD OF DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $deleteFunction),
		];
	}

	/**
	 * Resolves the target schema and fetches current `objects` columns from the database,
	 * then returns the complete list of refresh SQL statements.
	 *
	 * @param list<string>|null $objectColumns
	 * @param list<string>      $identityColumns
	 *
	 * @return list<string>
	 */
	public function buildRefreshSqlForConnection(
		Connection $connection,
		?string $schemaName = null,
		?array $objectColumns = null,
		array $identityColumns = self::IDENTITY_COLUMNS,
	): array {
		$resolvedSchema = $schemaName ?? $this->resolveSchemaName($connection);
		$resolvedColumns = $objectColumns ?? $this->fetchObjectColumns($connection, $resolvedSchema);

		return $this->buildRefreshSql($connection, $resolvedSchema, $resolvedColumns, $identityColumns);
	}

	/**
	 * Fetches ordered column names for `objects` in the given schema.
	 *
	 * @return list<string>
	 */
	public function fetchObjectColumns(Connection $connection, string $schemaName): array
	{
		$this->assertValidIdentifier($schemaName, 'schema');

		$columns = $connection->fetchFirstColumn(
			'SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = \'objects\' ORDER BY ordinal_position',
			['schema' => $schemaName],
		);

		$result = [];
		foreach ($columns as $column) {
			if (!is_string($column) || '' === $column) {
				continue;
			}
			$result[] = $column;
		}

		return $result;
	}

	public function resolveSchemaName(Connection $connection): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schema) || '' === $schema) {
			$current = $connection->fetchOne('SELECT current_schema()');
			$schema = is_string($current) ? $current : '';
		}
		$this->assertValidIdentifier($schema, 'schema');

		return $schema;
	}

	/**
	 * @param list<string> $objectColumns
	 * @param list<string> $identityColumns
	 */
	private function assertValidColumns(array $objectColumns, array $identityColumns): void
	{
		if ([] === $objectColumns) {
			throw new \InvalidArgumentException('Object column list cannot be empty.');
		}
		foreach ($objectColumns as $column) {
			$this->assertValidIdentifier($column, 'column');
		}
		foreach ($identityColumns as $column) {
			$this->assertValidIdentifier($column, 'identity column');
		}
		foreach (['id', 'site_id', 'created_date'] as $required) {
			if (!in_array($required, $objectColumns, true)) {
				throw new \InvalidArgumentException(sprintf('Object column list must include "%s".', $required));
			}
		}
		$rowColumns = array_diff($objectColumns, ['id', 'site_id', 'created_date']);
		if ([] === $rowColumns) {
			throw new \InvalidArgumentException('Object column list must include at least one non-key column.');
		}
	}

	private function assertValidIdentifier(string $identifier, string $label): void
	{
		if (1 !== preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
			throw new \InvalidArgumentException(sprintf('Invalid PostgreSQL %s identifier "%s".', $label, $identifier));
		}
	}

	/**
	 * @param list<string> $columns
	 */
	private function quotedColumns(Connection $connection, array $columns): string
	{
		return implode(', ', array_map(
			fn (string $column): string => $this->quoteIdentifier($connection, $column),
			$columns,
		));
	}

	private function quoteIdentifier(Connection $connection, string $identifier): string
	{
		return $connection->quoteSingleIdentifier($identifier);
	}

	private function qualifiedName(Connection $connection, string $schema, string $name): string
	{
		return $this->quoteIdentifier($connection, $schema).'.'.$this->quoteIdentifier($connection, $name);
	}
}
