<?php

namespace iikiti\CMS\Tests\Search\Strategy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Service\SearchParams;
use iikiti\CMS\Search\Strategy\PostgreSQL\PostgreSQLSearchEngine;
use PHPUnit\Framework\TestCase;

final class PostgreSQLSearchEngineTest extends TestCase
{
	public function testGetName(): void
	{
		$engine = $this->createEngine('');

		self::assertSame('postgresql', $engine->getName());
	}

	public function testGetLabel(): void
	{
		$engine = $this->createEngine('');

		self::assertSame('PostgreSQL Full-Text Search', $engine->getLabel());
	}

	public function testGetDefaultLanguage(): void
	{
		$engine = $this->createEngine('');

		self::assertSame('english', $engine->getDefaultLanguage());
	}

	public function testGetIndexTableNameWithoutSchema(): void
	{
		$engine = $this->createEngine('');
		$index = new SearchIndex('frontend', 'Front-end Search');

		self::assertSame('search_frontend', $engine->getIndexTableName($index));
	}

	public function testGetIndexTableNameWithSchema(): void
	{
		$engine = $this->createEngine('my_schema');
		$index = new SearchIndex('frontend', 'Front-end Search');

		self::assertSame('my_schema.search_frontend', $engine->getIndexTableName($index));
	}

	public function testGetIndexTableNameSlugSanitization(): void
	{
		$engine = $this->createEngine('');
		$index = new SearchIndex('my-complex/index', 'Test');

		self::assertSame('search_my_complex_index', $engine->getIndexTableName($index));
	}

	public function testBuildIndexColumns(): void
	{
		$engine = $this->createEngine('');
		$index = new SearchIndex('test', 'Test');

		$columns = $engine->buildIndexColumns($index);

		self::assertCount(5, $columns);
		self::assertStringContainsString('object_type', $columns[0]);
		self::assertStringContainsString('object_id', $columns[1]);
		self::assertStringContainsString('search_vector', $columns[2]);
		self::assertStringContainsString('data', $columns[3]);
	}

	public function testDropIndexRejectsSystemLocked(): void
	{
		$engine = $this->createEngine('');
		$index = new SearchIndex('admin', 'Admin', SearchIndexType::Admin);
		$index->setSystemLocked(true);

		$this->expectException(\iikiti\CMS\Search\Exception\SystemLockedException::class);

		$engine->dropIndex($index);
	}

	public function testExtractedContentIsNotReturnedInSearchHitData(): void
	{
		$engine = $this->createEngine('');
		$index = new SearchIndex('frontend', 'Front-end Search');
		$method = new \ReflectionMethod($engine, 'buildDataExpression');

		self::assertSame('{}', $method->invoke($engine, $index));
	}

	public function testSearchRestrictsAssetsToGrantedSiteAndVisibility(): void
	{
		$result = $this->createStub(Result::class);
		$result->method('fetchAllAssociative')->willReturn([]);
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())->
			method('executeQuery')->
			with(
				self::callback(static fn (string $sql): bool => str_contains($sql, 'digital_asset_site_grants') && str_contains($sql, "asset_object.asset_visibility = 'public'") && strrpos($sql, 'ORDER BY') > strrpos($sql, 'AND indexed.data')),
				self::callback(static fn (array $parameters): bool => 42 === $parameters['asset_site_id'] && false === $parameters['include_private_assets'] && 'category' === $parameters['fkey_category'] && 'pdf' === $parameters['fval_category']),
			)->
			willReturn($result);
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);
		$engine = new PostgreSQLSearchEngine($entityManager, 'cms_schema');

		$engine->search(
			new SearchIndex('frontend', 'Front-end Search'),
			(new SearchParams(filters: ['category' => 'pdf']))->withAssetAccess(42, false),
			'document',
		);
	}

	private function createEngine(string $schema): PostgreSQLSearchEngine
	{
		$connection = $this->createStub(Connection::class);
		// A real platform instance avoids generating a doubled PostgreSQLPlatform
		// class, which exhausted memory when the suite ran in full.
		$connection->method('getDatabasePlatform')->willReturn(new \Doctrine\DBAL\Platforms\PostgreSQLPlatform());
		$connection->method('executeStatement')->willReturn(0);
		$connection->method('fetchOne')->willReturn(1);

		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);

		return new PostgreSQLSearchEngine($entityManager, $schema);
	}
}
