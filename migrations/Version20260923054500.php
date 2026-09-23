<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fixes duplicate IDs in the object_properties table and adds the missing
 * primary key constraint.
 *
 * Without a primary key on object_properties.id, the IDENTITY column allowed
 * explicit inserts that produced duplicate rows — the same id value pointing
 * to properties that belong to different DbObject owners (e.g. id=8 appeared
 * for both a Site "domain" property and a Template "assignments" property).
 *
 * Doctrine uses object_properties.id as the entity identifier, so duplicate
 * IDs caused the identity map to return a stale or wrong ObjectProperty when
 * collections were hydrated, producing mismatched key/name/object_id triples
 * in Template.getProperties().
 *
 * This migration advances the identity sequence past the current maximum,
 * reassigns duplicate rows to fresh ids, then adds the primary key constraint.
 *
 * The table is in the schema configured by DB_SCHEMA (applied to application
 * entities by the SchemaListener), defaulting to "public".
 */
final class Version20260923054500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix duplicate IDs and add primary key on object_properties.';
    }

    public function up(Schema $schema): void
    {
        $table = $this->qualifiedTable();
        $seq   = $this->qualifiedSequence();
        $pk    = $this->constraintName('pkey');

        // Advance the identity sequence well past the current maximum so that
        // nextval does not collide with existing rows during reassignment.
        $this->addSql(sprintf(
            'SELECT setval(%s, (SELECT COALESCE(MAX(id), 1) + 100 FROM %s))',
            $this->quoteLiteral($seq),
            $table
        ));

        // Reassign duplicate ids: keep the row with the highest object_id per id
        // group (each object "owns" its properties); duplicates from other
        // owners are reassigned via nextval.
        $this->addSql(sprintf(
            'UPDATE %s SET id = nextval(%s) WHERE ctid IN (SELECT ctid FROM (SELECT ctid, ROW_NUMBER() OVER (PARTITION BY id ORDER BY object_id DESC, created DESC) AS rn FROM %s) ranked WHERE rn > 1)',
            $table,
            $this->quoteLiteral($seq),
            $table
        ));

        // Add the primary key constraint (rejects future duplicate inserts).
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (id)',
            $table,
            $pk
        ));

        // Restore foreign keys that were dropped alongside the old constraints.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (object_id) REFERENCES %s (id)',
            $table,
            $this->constraintName('fk_object'),
            $this->qualifiedObjects()
        ));
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (creator_id) REFERENCES %s (id)',
            $table,
            $this->constraintName('fk_creator'),
            $this->qualifiedObjects()
        ));

        // Advance the sequence one more time after the PK is in place.
        $this->addSql(sprintf(
            'SELECT setval(%s, (SELECT MAX(id) FROM %s))',
            $this->quoteLiteral($seq),
            $table
        ));
    }

    public function down(Schema $schema): void
    {
        $table = $this->qualifiedTable();

        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $this->constraintName('pkey')));
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $this->constraintName('fk_object')));
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $this->constraintName('fk_creator')));
    }

    private function qualifiedTable(): string
    {
        $schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

        return is_string($schema) && '' !== $schema
            ? $schema.'.object_properties'
            : 'object_properties';
    }

    private function qualifiedObjects(): string
    {
        $schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

        return is_string($schema) && '' !== $schema
            ? $schema.'.objects'
            : 'objects';
    }

    private function qualifiedSequence(): string
    {
        $schema = $_ENV['DB_SCHEMA'] ?? $_SERVER['DB_SCHEMA'] ?? getenv('DB_SCHEMA');

        return is_string($schema) && '' !== $schema
            ? $schema.'.object_properties_id_seq'
            : 'object_properties_id_seq';
    }

    private function constraintName(string $suffix): string
    {
        return 'object_properties_'.$suffix;
    }

    private function quoteIdent(string $ident): string
    {
        return '"'.str_replace('"', '""', $ident).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
