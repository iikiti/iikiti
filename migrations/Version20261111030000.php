<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use iikiti\CMS\Doctrine\ObjectPartitionManager;

/**
 * Replaces the single object heap with site/quarter partitions while preserving
 * the current single-column ORM identity through object_identity/object_lookup.
 */
final class Version20261111030000 extends AbstractMigration
{
	private const IDENTITY_COLUMNS = [
		'token',
		'slug',
		'asset_public_token',
		'icon_set_id',
		'icon_name',
		'api_token_user_id',
	];

	private string $schemaName;
	/** @var list<string> */
	private array $objectColumns;
	private int $oldSequenceValue = 0;
	private bool $oldSequenceCalled = false;
	private int $oldObjectMaximumId = 0;
	private int $legacyOrphanPropertyCount = 0;

	public function getDescription(): string
	{
		return 'Partition objects by site and quarter with a global identity registry and Doctrine lookup view.';
	}

	public function up(Schema $schema): void
	{
		if (!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			throw new \RuntimeException('Object partition migration requires PostgreSQL.');
		}

		$this->schemaName = $this->schemaName();
		$this->objectColumns = $this->objectColumns();
		$this->assertRequiredColumns();
		$this->assertSupportedUniqueIndexes();
		$this->assertNoCutoverObjects();
		$this->captureIdentitySequence();
		$this->oldObjectMaximumId = (int) $this->connection->fetchOne(sprintf('SELECT COALESCE(MAX(id), 0) FROM %s', $this->table('objects')));

		$oldObjects = $this->table('objects');
		$identity = $this->table('object_identity');
		$partitionRoot = $this->table('objects_partitioned');
		$columns = $this->quotedColumns($this->objectColumns);
		$hasPropertyObjectForeignKey = $this->hasReferenceFromTable('object_properties');
		$this->legacyOrphanPropertyCount = (int) $this->connection->fetchOne(sprintf(
			'SELECT COUNT(*) FROM %s property LEFT JOIN %s object ON object.id = property.object_id WHERE property.object_id IS NOT NULL AND object.id IS NULL',
			$this->table('object_properties'),
			$oldObjects,
		));

		$this->addSql($this->createIdentityTable());
		$this->addSql(sprintf(
			'CREATE TABLE %s (LIKE %s INCLUDING DEFAULTS INCLUDING GENERATED INCLUDING CONSTRAINTS INCLUDING STORAGE INCLUDING COMMENTS) PARTITION BY LIST (%s)',
			$partitionRoot,
			$oldObjects,
			$this->quoteIdentifier('site_id'),
		));
		$this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $partitionRoot, $this->quoteIdentifier('id')));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT objects_partitioned_pkey PRIMARY KEY (%s, %s, %s)',
			$partitionRoot,
			$this->quoteIdentifier('site_id'),
			$this->quoteIdentifier('created_date'),
			$this->quoteIdentifier('id'),
		));
		$this->addSql(sprintf(
			'ALTER TABLE %s ADD CONSTRAINT fk_objects_partition_identity FOREIGN KEY (%s, %s, %s) REFERENCES %s (%s, %s, %s) ON UPDATE CASCADE ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED',
			$partitionRoot,
			$this->quoteIdentifier('id'),
			$this->quoteIdentifier('site_id'),
			$this->quoteIdentifier('created_date'),
			$identity,
			$this->quoteIdentifier('id'),
			$this->quoteIdentifier('site_id'),
			$this->quoteIdentifier('created_date'),
		));
		$this->addSql(sprintf('CREATE INDEX idx_objects_partition_site_id ON %s (%s, %s)', $partitionRoot, $this->quoteIdentifier('site_id'), $this->quoteIdentifier('id')));
		$this->addSql(sprintf('CREATE INDEX idx_objects_partition_type_site_id ON %s (%s, %s, %s)', $partitionRoot, $this->quoteIdentifier('site_id'), $this->quoteIdentifier('type'), $this->quoteIdentifier('id')));
		$this->addSql(sprintf('CREATE INDEX idx_objects_partition_api_user ON %s (%s)', $partitionRoot, $this->quoteIdentifier('api_token_user_id')));
		$this->addSql(sprintf('CREATE INDEX idx_objects_partition_icon_set ON %s (%s)', $partitionRoot, $this->quoteIdentifier('icon_set_id')));

		$this->createInitialPartitions($partitionRoot);
		$this->copyIdentityRows($oldObjects, $identity);
		$this->copySelfForeignKeys($identity);
		$this->copyObjectRows($oldObjects, $partitionRoot, $columns);
		$this->setIdentitySequence();
		$this->validateCopiedRows($oldObjects, $identity, $partitionRoot);
		$this->retargetInboundForeignKeys($oldObjects, $identity);
		$this->addObjectPropertyForeignKey($identity, $hasPropertyObjectForeignKey, 0 < $this->legacyOrphanPropertyCount);

		$this->addSql(sprintf('ALTER TABLE %s RENAME TO %s', $oldObjects, $this->quoteIdentifier('objects_legacy')));
		$this->addSql(sprintf('ALTER TABLE %s RENAME TO %s', $partitionRoot, $this->quoteIdentifier('objects')));
		$this->createIdentityConsistencyTriggers();
		$this->createLookupViewAndTriggers();
		$this->addSql(sprintf('DROP TABLE %s', $this->table('objects_legacy')));
		$this->addSql(sprintf(
			'ALTER INDEX %s RENAME TO %s',
			$this->qualifiedName($this->schemaName, 'objects_partitioned_pkey'),
			$this->quoteIdentifier('objects_pkey'),
		));
	}

	public function postUp(Schema $schema): void
	{
		$identity = $this->table('object_identity');
		$objects = $this->table('objects');
		$lookup = $this->table('object_lookup');
		$counts = $this->connection->fetchAssociative(sprintf(
			'SELECT (SELECT COUNT(*) FROM %s) AS identities, (SELECT COUNT(*) FROM %s) AS objects',
			$identity,
			$objects,
		));
		if ((int) ($counts['identities'] ?? -1) !== (int) ($counts['objects'] ?? -2)) {
			throw new \RuntimeException('Object identity and partition row counts differ after cutover.');
		}
		if (!$this->relationExists('object_lookup')) {
			throw new \RuntimeException('Doctrine object lookup view is missing after cutover.');
		}
		$orphanPropertyCount = (int) $this->connection->fetchOne(sprintf(
			'SELECT COUNT(*) FROM %s property LEFT JOIN %s identity ON identity.id = property.object_id WHERE property.object_id IS NOT NULL AND identity.id IS NULL',
			$this->table('object_properties'),
			$identity,
		));
		if ($orphanPropertyCount !== $this->legacyOrphanPropertyCount) {
			throw new \RuntimeException('Object property orphan count changed during the partition cutover.');
		}
	}

	public function down(Schema $schema): void
	{
		$this->throwIrreversibleMigrationException('Restore a database backup to roll back the object partition cutover.');
	}

	private function createIdentityTable(): string
	{
		$identity = $this->table('object_identity');

		return sprintf(
			'CREATE TABLE %s (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, site_id BIGINT NOT NULL, created_date TIMESTAMP WITHOUT TIME ZONE NOT NULL, token VARCHAR(255) DEFAULT NULL, slug VARCHAR(64) DEFAULT NULL, asset_public_token VARCHAR(64) DEFAULT NULL, icon_set_id BIGINT DEFAULT NULL, icon_name VARCHAR(64) DEFAULT NULL, api_token_user_id BIGINT DEFAULT NULL, CONSTRAINT object_identity_pkey PRIMARY KEY (id), CONSTRAINT uniq_identity_partition_key UNIQUE (id, site_id, created_date), CONSTRAINT uniq_identity_token UNIQUE (token), CONSTRAINT uniq_identity_slug UNIQUE (slug), CONSTRAINT uniq_identity_asset_public_token UNIQUE (asset_public_token), CONSTRAINT uniq_identity_icon_name UNIQUE (icon_set_id, icon_name))',
			$identity,
		);
	}

	private function createInitialPartitions(string $partitionRoot): void
	{
		$siteIds = $this->connection->fetchFirstColumn(sprintf(
			"SELECT site_id::text FROM %s UNION SELECT id::text FROM %s WHERE lower(type) = 'site' UNION SELECT '0' ORDER BY 1",
			$this->table('objects'),
			$this->table('objects'),
		));
		$quartersBySite = [];
		foreach ($this->connection->fetchAllAssociative(sprintf(
			"SELECT DISTINCT site_id::text AS site_id, to_char(date_trunc('quarter', created_date), 'YYYY-MM-DD') AS quarter_start FROM %s",
			$this->table('objects'),
		)) as $row) {
			$siteId = ObjectPartitionManager::normalizeSiteId((string) $row['site_id']);
			$quartersBySite[$siteId][$row['quarter_start']] = true;
		}

		$currentQuarter = self::quarterStart(new \DateTimeImmutable());
		foreach ($siteIds as $rawSiteId) {
			$siteId = ObjectPartitionManager::normalizeSiteId((string) $rawSiteId);
			$this->addSql(sprintf(
				'CREATE TABLE %s PARTITION OF %s FOR VALUES IN (%s) PARTITION BY RANGE (%s)',
				$this->table(sprintf('objects_site_%s', $siteId)),
				$partitionRoot,
				$siteId,
				$this->quoteIdentifier('created_date'),
			));

			for ($offset = 0; $offset < 4; ++$offset) {
				$quartersBySite[$siteId][self::quarterStart($currentQuarter->modify(sprintf('+%d months', 3 * $offset)))->format('Y-m-d')] = true;
			}

			foreach (array_keys($quartersBySite[$siteId] ?? []) as $quarterStart) {
				$start = new \DateTimeImmutable($quarterStart);
				$end = $start->modify('+3 months');
				$quarter = intdiv((int) $start->format('n') - 1, 3) + 1;
				$child = $this->table(sprintf('objects_site_%s_%04d_q%d', $siteId, $start->format('Y'), $quarter));
				$this->addSql(sprintf(
					'CREATE TABLE %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
					$child,
					$this->table(sprintf('objects_site_%s', $siteId)),
					$this->connection->quote($start->format('Y-m-d')),
					$this->connection->quote($end->format('Y-m-d')),
				));
			}
		}
	}

	private function copyIdentityRows(string $oldObjects, string $identity): void
	{
		$columns = ['id', 'site_id', 'created_date', ...self::IDENTITY_COLUMNS];
		$this->addSql(sprintf(
			'INSERT INTO %s (%s) SELECT %s FROM %s',
			$identity,
			$this->quotedColumns($columns),
			$this->quotedColumns($columns),
			$oldObjects,
		));
	}

	private function copyObjectRows(string $oldObjects, string $partitionRoot, string $columns): void
	{
		$this->addSql(sprintf('INSERT INTO %s (%s) SELECT %s FROM %s', $partitionRoot, $columns, $columns, $oldObjects));
	}

	private function setIdentitySequence(): void
	{
		$tableName = $this->connection->quote($this->schemaName.'.object_identity');
		$sequenceValue = max($this->oldObjectMaximumId, $this->oldSequenceValue);
		$sequenceCalled = $this->oldSequenceCalled || $this->oldObjectMaximumId > 0;
		if (!$sequenceCalled) {
			$sequenceValue = max(1, $sequenceValue);
		}
		$this->addSql(sprintf(
			'SELECT setval(pg_get_serial_sequence(%s, %s), %d, %s)',
			$tableName,
			$this->connection->quote('id'),
			$sequenceValue,
			$sequenceCalled ? 'TRUE' : 'FALSE',
		));
	}

	private function captureIdentitySequence(): void
	{
		$sequence = $this->connection->fetchOne(
			'SELECT pg_get_serial_sequence(:table, :column)',
			['table' => $this->schemaName.'.objects', 'column' => 'id'],
		);
		if (!is_string($sequence) || '' === $sequence) {
			throw new \RuntimeException('The objects ID sequence could not be resolved.');
		}

		$state = $this->connection->fetchAssociative(sprintf('SELECT last_value, is_called FROM %s', $sequence));
		if (!is_array($state)) {
			throw new \RuntimeException('The objects ID sequence state could not be read.');
		}
		$this->oldSequenceValue = (int) $state['last_value'];
		$this->oldSequenceCalled = $this->isPostgreSqlTrue($state['is_called']);
	}

	private function validateCopiedRows(string $oldObjects, string $identity, string $partitionRoot): void
	{
		$this->addSql(sprintf(
			'DO $iikiti$ BEGIN IF (SELECT COUNT(*) FROM %s) <> (SELECT COUNT(*) FROM %s) OR (SELECT COUNT(*) FROM %s) <> (SELECT COUNT(*) FROM %s) THEN RAISE EXCEPTION \'object partition copy row counts do not match\'; END IF; IF EXISTS (SELECT id FROM %s EXCEPT SELECT id FROM %s) OR EXISTS (SELECT id FROM %s EXCEPT SELECT id FROM %s) THEN RAISE EXCEPTION \'object partition copy lost an ID\'; END IF; END $iikiti$',
			$oldObjects,
			$identity,
			$identity,
			$partitionRoot,
			$oldObjects,
			$partitionRoot,
			$oldObjects,
			$identity,
		));
	}

	private function retargetInboundForeignKeys(string $oldObjects, string $identity): void
	{
		$references = $this->connection->fetchAllAssociative(
			' SELECT child_ns.nspname AS child_schema, child.relname AS child_table, constraint_row.conname, pg_get_constraintdef(constraint_row.oid, true) AS definition'.
			' FROM pg_constraint constraint_row'.
			' JOIN pg_class child ON child.oid = constraint_row.conrelid'.
			' JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace'.
			' WHERE constraint_row.contype = \'f\' AND constraint_row.confrelid = to_regclass(:table) AND constraint_row.conrelid <> constraint_row.confrelid',
			['table' => $this->schemaName.'.objects'],
		);

		foreach ($references as $reference) {
			$definition = (string) $reference['definition'];
			$this->assertReferencesOnlyId($definition, (string) $reference['conname']);
			$definition = $this->retargetConstraintDefinition($definition, $identity, (string) $reference['conname']);
			if ('' === $definition) {
				throw new \RuntimeException(sprintf('Unable to retarget foreign key %s.%s.', $reference['child_table'], $reference['conname']));
			}

			$childTable = $this->qualifiedName((string) $reference['child_schema'], (string) $reference['child_table']);
			$constraint = $this->quoteIdentifier((string) $reference['conname']);
			$this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $childTable, $constraint));
			$this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $childTable, $constraint, $definition));
		}
	}

	private function copySelfForeignKeys(string $identity): void
	{
		$constraints = $this->connection->fetchAllAssociative(
			' SELECT conname, pg_get_constraintdef(oid, true) AS definition FROM pg_constraint'.
			' WHERE contype = \'f\' AND conrelid = to_regclass(:table) AND confrelid = conrelid',
			['table' => $this->schemaName.'.objects'],
		);
		$identityColumns = ['id', 'site_id', 'created_date', ...self::IDENTITY_COLUMNS];
		foreach ($constraints as $constraint) {
			$definition = (string) $constraint['definition'];
			$this->assertReferencesOnlyId($definition, (string) $constraint['conname']);
			preg_match('~FOREIGN KEY\s*\(([^)]+)\)~i', $definition, $sourceMatch);
			$sourceColumns = isset($sourceMatch[1]) ? self::parseSqlColumnList($sourceMatch[1]) : [];
			if ([] === $sourceColumns || [] !== array_diff($sourceColumns, $identityColumns)) {
				throw new \RuntimeException(sprintf('Self-reference %s uses columns not mirrored in object_identity.', $constraint['conname']));
			}
			$definition = $this->retargetConstraintDefinition($definition, $identity, (string) $constraint['conname']);
			$this->addSql(sprintf(
				'ALTER TABLE %s ADD CONSTRAINT %s %s',
				$identity,
				$this->quoteIdentifier((string) $constraint['conname']),
				$definition,
			));
		}
	}

	private function retargetConstraintDefinition(string $definition, string $identity, string $constraintName): string
	{
		$rewritten = preg_replace_callback(
			'~REFERENCES\s+(?:(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_$]*)\s*\.\s*)?"?objects"?\s*\(~i',
			static fn (array $match): string => 'REFERENCES '.$identity.' (',
			$definition,
			1,
			$replacements,
		);
		if (1 !== $replacements) {
			throw new \RuntimeException(sprintf('Unable to retarget foreign key %s.', $constraintName));
		}

		return (string) $rewritten;
	}

	private function assertReferencesOnlyId(string $definition, string $constraintName): void
	{
		preg_match('~REFERENCES\s+(?:(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_$]*)\s*\.\s*)?"?objects"?\s*\(([^)]+)\)~i', $definition, $targetMatch);
		$targetColumns = isset($targetMatch[1]) ? self::parseSqlColumnList($targetMatch[1]) : [];
		if (['id'] !== $targetColumns) {
			throw new \RuntimeException(sprintf('Foreign key %s references object columns other than the global ID.', $constraintName));
		}
	}

	/** @return list<string> */
	private static function parseSqlColumnList(string $columns): array
	{
		return array_map(
			static fn (string $column): string => strtolower(trim(str_replace('"', '', $column))),
			explode(',', $columns),
		);
	}

	private function createIdentityConsistencyTriggers(): void
	{
		$identity = $this->table('object_identity');
		$objects = $this->table('objects');
		$checks = $this->identityComparison('NEW', 'row');
		$rowChecks = $this->identityComparison('identity', 'NEW');
		$identityFunction = $this->qualifiedName($this->schemaName, 'iikiti_check_identity_row');
		$rowFunction = $this->qualifiedName($this->schemaName, 'iikiti_check_row_identity');
		$deleteFunction = $this->qualifiedName($this->schemaName, 'iikiti_check_row_delete');

		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN IF NOT EXISTS (SELECT 1 FROM %s row WHERE row.id = NEW.id AND row.site_id = NEW.site_id AND row.created_date = NEW.created_date AND %s) THEN RAISE EXCEPTION \'object identity %% has no matching partition row\', NEW.id; END IF; RETURN NULL; END $iikiti$',
			$identityFunction,
			$objects,
			$checks,
		));
		$this->addSql(sprintf(
			'CREATE CONSTRAINT TRIGGER iikiti_identity_requires_row AFTER INSERT OR UPDATE ON %s DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s()',
			$identity,
			$identityFunction,
		));
		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN IF NOT EXISTS (SELECT 1 FROM %s identity WHERE identity.id = NEW.id AND identity.site_id = NEW.site_id AND identity.created_date = NEW.created_date AND %s) THEN RAISE EXCEPTION \'partition row %% has no matching object identity\', NEW.id; END IF; RETURN NULL; END $iikiti$',
			$rowFunction,
			$identity,
			$rowChecks,
		));
		$this->addSql(sprintf(
			'CREATE CONSTRAINT TRIGGER iikiti_row_requires_identity AFTER INSERT OR UPDATE ON %s DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s()',
			$objects,
			$rowFunction,
		));
		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN IF EXISTS (SELECT 1 FROM %s identity WHERE identity.id = OLD.id AND identity.site_id = OLD.site_id AND identity.created_date = OLD.created_date) THEN RAISE EXCEPTION \'partition row %% cannot be deleted without removing its old identity key\', OLD.id; END IF; RETURN NULL; END $iikiti$',
			$deleteFunction,
			$identity,
		));
		$this->addSql(sprintf(
			'CREATE CONSTRAINT TRIGGER iikiti_row_delete_requires_identity_delete AFTER DELETE ON %s DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION %s()',
			$objects,
			$deleteFunction,
		));
	}

	private function createLookupViewAndTriggers(): void
	{
		$identity = $this->table('object_identity');
		$objects = $this->table('objects');
		$lookup = $this->table('object_lookup');
		$select = [];
		foreach ($this->objectColumns as $column) {
			$quoted = $this->quoteIdentifier($column);
			if ('id' === $column) {
				$select[] = sprintf('identity.%s AS %s', $quoted, $quoted);
			} elseif (in_array($column, self::IDENTITY_COLUMNS, true)) {
				$select[] = sprintf('identity.%s AS %s', $quoted, $quoted);
			} else {
				$select[] = sprintf('row.%s AS %s', $quoted, $quoted);
			}
		}
		$this->addSql(sprintf(
			'CREATE VIEW %s AS SELECT %s FROM %s identity INNER JOIN %s row ON row.id = identity.id AND row.site_id = identity.site_id AND row.created_date = identity.created_date',
			$lookup,
			implode(', ', $select),
			$identity,
			$objects,
		));

		$insertFunction = $this->qualifiedName($this->schemaName, 'iikiti_object_lookup_insert');
		$updateFunction = $this->qualifiedName($this->schemaName, 'iikiti_object_lookup_update');
		$deleteFunction = $this->qualifiedName($this->schemaName, 'iikiti_object_lookup_delete');
		$identityColumns = ['site_id', 'created_date', ...self::IDENTITY_COLUMNS];
		$identityValues = array_map(fn (string $column): string => 'NEW.'.$this->quoteIdentifier($column), $identityColumns);
		$insertColumns = $this->quotedColumns($this->objectColumns);
		$insertValues = implode(', ', array_map(fn (string $column): string => 'NEW.'.$this->quoteIdentifier($column), $this->objectColumns));
		$identityAssignments = [];
		foreach (['site_id', 'created_date', ...self::IDENTITY_COLUMNS] as $column) {
			$quoted = $this->quoteIdentifier($column);
			$identityAssignments[] = sprintf('%s = NEW.%s', $quoted, $quoted);
		}
		$rowAssignments = [];
		foreach ($this->objectColumns as $column) {
			if (in_array($column, ['id', 'site_id', 'created_date'], true)) {
				continue;
			}
			$quoted = $this->quoteIdentifier($column);
			$rowAssignments[] = sprintf('%s = NEW.%s', $quoted, $quoted);
		}
		$sequence = $this->connection->quote($this->schemaName.'.object_identity');

		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ DECLARE sequence_name REGCLASS; BEGIN IF NEW.id IS NULL THEN INSERT INTO %s (%s) VALUES (%s) RETURNING id INTO NEW.id; ELSE INSERT INTO %s (id, %s) VALUES (NEW.id, %s); sequence_name := pg_get_serial_sequence(%s, \'id\')::REGCLASS; PERFORM setval(sequence_name, GREATEST(NEW.id, COALESCE(pg_sequence_last_value(sequence_name), 0)), true); END IF; INSERT INTO %s (%s) VALUES (%s); RETURN NEW; END $iikiti$',
			$insertFunction,
			$identity,
			$this->quotedColumns($identityColumns),
			implode(', ', $identityValues),
			$identity,
			$this->quotedColumns($identityColumns),
			implode(', ', $identityValues),
			$sequence,
			$objects,
			$insertColumns,
			$insertValues,
		));
		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN IF NEW.id <> OLD.id THEN RAISE EXCEPTION \'object IDs are immutable\'; END IF; UPDATE %s SET %s WHERE id = OLD.id; UPDATE %s SET %s WHERE id = NEW.id AND site_id = NEW.site_id AND created_date = NEW.created_date; RETURN NEW; END $iikiti$',
			$updateFunction,
			$identity,
			implode(', ', $identityAssignments),
			$objects,
			implode(', ', $rowAssignments),
		));
		$this->addSql(sprintf(
			'CREATE FUNCTION %s() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN DELETE FROM %s WHERE id = OLD.id; RETURN OLD; END $iikiti$',
			$deleteFunction,
			$identity,
		));
		$this->addSql(sprintf('CREATE TRIGGER object_lookup_insert INSTEAD OF INSERT ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $insertFunction));
		$this->addSql(sprintf('CREATE TRIGGER object_lookup_update INSTEAD OF UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $updateFunction));
		$this->addSql(sprintf('CREATE TRIGGER object_lookup_delete INSTEAD OF DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $lookup, $deleteFunction));
	}

	private function addObjectPropertyForeignKey(string $identity, bool $hasExistingReference, bool $hasLegacyOrphans): void
	{
		if (!$hasExistingReference) {
			$this->addSql(sprintf(
				'ALTER TABLE %s ADD CONSTRAINT fk_object_properties_identity FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE CASCADE%s',
				$this->table('object_properties'),
				$this->quoteIdentifier('object_id'),
				$identity,
				$this->quoteIdentifier('id'),
				$hasLegacyOrphans ? ' NOT VALID' : '',
			));
		}
	}

	private function hasReferenceFromTable(string $childTable): bool
	{
		return $this->isPostgreSqlTrue($this->connection->fetchOne(
			"SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass(:child) AND confrelid = to_regclass(:parent) AND contype = 'f')",
			['child' => $this->schemaName.'.'.$childTable, 'parent' => $this->schemaName.'.objects'],
		));
	}

	private function isPostgreSqlTrue(mixed $value): bool
	{
		return in_array($value, [true, 1, '1', 't', 'true'], true);
	}

	private function identityComparison(string $identityAlias, string $rowAlias): string
	{
		$comparisons = [];
		foreach (self::IDENTITY_COLUMNS as $column) {
			$quoted = $this->quoteIdentifier($column);
			$comparisons[] = sprintf('%s.%s IS NOT DISTINCT FROM %s.%s', $identityAlias, $quoted, $rowAlias, $quoted);
		}

		return implode(' AND ', $comparisons);
	}

	private function assertRequiredColumns(): void
	{
		$required = ['id', 'site_id', 'created_date', 'type', ...self::IDENTITY_COLUMNS];
		$missing = array_values(array_diff($required, $this->objectColumns));
		if ([] !== $missing) {
			throw new \RuntimeException(sprintf('Object partition migration is missing required columns: %s.', implode(', ', $missing)));
		}
		$types = $this->connection->fetchAllKeyValue(
			' SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = :schema AND table_name = \'objects\' AND column_name IN (\'id\', \'site_id\', \'created_date\')',
			['schema' => $this->schemaName],
		);
		if ('bigint' !== ($types['id'] ?? null) || 'bigint' !== ($types['site_id'] ?? null) || 'timestamp without time zone' !== ($types['created_date'] ?? null)) {
			throw new \RuntimeException('Object ID, site ID, or creation date has an unsupported PostgreSQL type.');
		}
	}

	private function assertSupportedUniqueIndexes(): void
	{
		$definitions = $this->connection->fetchFirstColumn(
			' SELECT indexdef FROM pg_indexes WHERE schemaname = :schema AND tablename = \'objects\' AND indexdef ILIKE \'CREATE UNIQUE INDEX%\'',
			['schema' => $this->schemaName],
		);
		$known = [
			'id',
			'token',
			'slug',
			'asset_public_token',
			'icon_set_id,icon_name',
		];
		foreach ($definitions as $definition) {
			if (1 !== preg_match('/\(([^()]*)\)(?:\s+WHERE\s+.*)?$/i', (string) $definition, $matches)) {
				throw new \RuntimeException(sprintf('Object unique index cannot be migrated safely: %s.', $definition));
			}
			$columns = strtolower(preg_replace('/["\s]/', '', $matches[1]) ?? '');
			if (!in_array($columns, $known, true)) {
				throw new \RuntimeException(sprintf('Object unique index on unsupported columns must be moved to object_identity: %s.', $definition));
			}
		}
	}

	private function assertNoCutoverObjects(): void
	{
		foreach (['object_identity', 'objects_partitioned', 'objects_legacy', 'object_lookup'] as $table) {
			if ($this->relationExists($table)) {
				throw new \RuntimeException(sprintf('Cannot partition objects because %s already exists.', $table));
			}
		}
	}

	private function relationExists(string $table): bool
	{
		$relation = $this->connection->fetchOne('SELECT to_regclass(:table)::text', ['table' => $this->schemaName.'.'.$table]);

		return is_string($relation) && '' !== $relation;
	}

	/** @return list<string> */
	private function objectColumns(): array
	{
		return $this->connection->fetchFirstColumn(
			' SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = \'objects\' ORDER BY ordinal_position',
			['schema' => $this->schemaName()],
		);
	}

	private function schemaName(): string
	{
		$schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');
		if (!is_string($schema) || '' === $schema) {
			$schema = (string) $this->connection->fetchOne('SELECT current_schema()');
		}
		if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
			throw new \RuntimeException('DB_SCHEMA must be a simple PostgreSQL schema identifier for object partitioning.');
		}

		return $schema;
	}

	private static function quarterStart(\DateTimeImmutable $date): \DateTimeImmutable
	{
		$month = intdiv((int) $date->format('n') - 1, 3) * 3 + 1;

		return $date->setDate((int) $date->format('Y'), $month, 1)->setTime(0, 0);
	}

	private function quotedColumns(array $columns): string
	{
		return implode(', ', array_map($this->quoteIdentifier(...), $columns));
	}

	private function quoteIdentifier(string $identifier): string
	{
		return $this->connection->quoteIdentifier($identifier);
	}

	private function table(string $name): string
	{
		return $this->qualifiedName($this->schemaName, $name);
	}

	private function qualifiedName(string $schema, string $name): string
	{
		return $this->quoteIdentifier($schema).'.'.$this->quoteIdentifier($name);
	}
}
