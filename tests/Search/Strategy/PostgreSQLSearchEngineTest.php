<?php

namespace iikiti\CMS\Tests\Search\Strategy;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Enum\SearchIndexType;
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

	private function createEngine(string $schema): PostgreSQLSearchEngine
	{
		$connection = $this->createStub(\Doctrine\DBAL\Connection::class);
		$connection->method('getDatabasePlatform')->willReturn(
			$this->createStub(\Doctrine\DBAL\Platforms\PostgreSQLPlatform::class)
		);
		$connection->method('executeStatement')->willReturn(0);
		$connection->method('fetchOne')->willReturn(1);

		$entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
		$entityManager->method('getConnection')->willReturn($connection);

		return new PostgreSQLSearchEngine($entityManager, $schema);
	}
}
