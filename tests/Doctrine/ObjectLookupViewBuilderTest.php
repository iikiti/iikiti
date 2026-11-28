<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use iikiti\CMS\Doctrine\ObjectLookupViewBuilder;
use PHPUnit\Framework\TestCase;

final class ObjectLookupViewBuilderTest extends TestCase
{
	public function testBuildRefreshSqlGeneratesDropViewFunctionsAndTriggersMatchingSchema(): void
	{
		$connection = $this->createStub(Connection::class);
		$connection->method('quoteSingleIdentifier')->willReturnCallback(
			static fn (string $identifier): string => '"'.str_replace('"', '""', $identifier).'"',
		);
		$connection->method('quote')->willReturnCallback(
			static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
		);

		$columns = [
			'id',
			'site_id',
			'created_date',
			'type',
			'token',
			'slug',
			'asset_public_token',
			'icon_set_id',
			'icon_name',
			'api_token_user_id',
			'asset_title',
			'custom_new_column',
		];

		$builder = new ObjectLookupViewBuilder();
		$statements = $builder->buildRefreshSql($connection, 'cms_prod', $columns);

		self::assertCount(11, $statements);
		self::assertSame('DROP TRIGGER IF EXISTS object_lookup_insert ON "cms_prod"."object_lookup"', $statements[0]);
		self::assertSame('DROP TRIGGER IF EXISTS object_lookup_update ON "cms_prod"."object_lookup"', $statements[1]);
		self::assertSame('DROP TRIGGER IF EXISTS object_lookup_delete ON "cms_prod"."object_lookup"', $statements[2]);
		self::assertSame('DROP VIEW IF EXISTS "cms_prod"."object_lookup"', $statements[3]);

		// View definition projects id and IDENTITY_COLUMNS from identity, and other columns from row.
		self::assertStringStartsWith('CREATE VIEW "cms_prod"."object_lookup" AS SELECT ', $statements[4]);
		self::assertStringContainsString('identity."id" AS "id"', $statements[4]);
		self::assertStringContainsString('identity."token" AS "token"', $statements[4]);
		self::assertStringContainsString('identity."slug" AS "slug"', $statements[4]);
		self::assertStringContainsString('identity."asset_public_token" AS "asset_public_token"', $statements[4]);
		self::assertStringContainsString('identity."icon_set_id" AS "icon_set_id"', $statements[4]);
		self::assertStringContainsString('identity."icon_name" AS "icon_name"', $statements[4]);
		self::assertStringContainsString('identity."api_token_user_id" AS "api_token_user_id"', $statements[4]);
		self::assertStringContainsString('row."site_id" AS "site_id"', $statements[4]);
		self::assertStringContainsString('row."created_date" AS "created_date"', $statements[4]);
		self::assertStringContainsString('row."asset_title" AS "asset_title"', $statements[4]);
		self::assertStringContainsString('row."custom_new_column" AS "custom_new_column"', $statements[4]);
		self::assertStringContainsString('FROM "cms_prod"."object_identity" identity INNER JOIN "cms_prod"."objects" row ON row.id = identity.id AND row.site_id = identity.site_id AND row.created_date = identity.created_date', $statements[4]);

		// Insert trigger function
		self::assertStringStartsWith('CREATE OR REPLACE FUNCTION "cms_prod"."iikiti_object_lookup_insert"()', $statements[5]);
		self::assertStringContainsString("pg_get_serial_sequence('cms_prod.object_identity', 'id')::REGCLASS", $statements[5]);
		self::assertStringContainsString('"custom_new_column"', $statements[5]);
		self::assertStringContainsString('NEW."custom_new_column"', $statements[5]);

		// Update trigger function
		self::assertStringStartsWith('CREATE OR REPLACE FUNCTION "cms_prod"."iikiti_object_lookup_update"()', $statements[6]);
		self::assertStringContainsString('object IDs are immutable', $statements[6]);
		self::assertStringContainsString('"custom_new_column" = NEW."custom_new_column"', $statements[6]);

		// Delete trigger function
		self::assertSame(
			'CREATE OR REPLACE FUNCTION "cms_prod"."iikiti_object_lookup_delete"() RETURNS TRIGGER LANGUAGE plpgsql AS $iikiti$ BEGIN DELETE FROM "cms_prod"."object_identity" WHERE id = OLD.id; RETURN OLD; END $iikiti$',
			$statements[7],
		);

		// INSTEAD OF triggers
		self::assertSame('CREATE TRIGGER object_lookup_insert INSTEAD OF INSERT ON "cms_prod"."object_lookup" FOR EACH ROW EXECUTE FUNCTION "cms_prod"."iikiti_object_lookup_insert"()', $statements[8]);
		self::assertSame('CREATE TRIGGER object_lookup_update INSTEAD OF UPDATE ON "cms_prod"."object_lookup" FOR EACH ROW EXECUTE FUNCTION "cms_prod"."iikiti_object_lookup_update"()', $statements[9]);
		self::assertSame('CREATE TRIGGER object_lookup_delete INSTEAD OF DELETE ON "cms_prod"."object_lookup" FOR EACH ROW EXECUTE FUNCTION "cms_prod"."iikiti_object_lookup_delete"()', $statements[10]);
	}

	public function testRejectsInvalidSchemaIdentifier(): void
	{
		$connection = $this->createStub(Connection::class);
		$builder = new ObjectLookupViewBuilder();

		$this->expectException(\InvalidArgumentException::class);
		$builder->buildRefreshSql($connection, 'public; DROP TABLE objects', ['id', 'site_id', 'created_date', 'type']);
	}

	public function testRejectsMissingRequiredColumns(): void
	{
		$connection = $this->createStub(Connection::class);
		$builder = new ObjectLookupViewBuilder();

		$this->expectException(\InvalidArgumentException::class);
		$builder->buildRefreshSql($connection, 'public', ['id', 'type']);
	}
}
