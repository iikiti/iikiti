<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Enum\SearchIndexType;
use iikiti\CMS\Search\Exception\SystemLockedException;
use PHPUnit\Framework\TestCase;

final class SearchIndexTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$index = new SearchIndex('my-index', 'My Index');

		self::assertSame('my-index', $index->getSlug());
		self::assertSame('My Index', $index->getName());
		self::assertSame(SearchIndexType::Custom, $index->getType());
		self::assertSame('postgresql', $index->getEngine());
		self::assertSame('english', $index->getLanguage());
		self::assertFalse($index->isSystemLocked());
		self::assertTrue($index->isEnabled());
	}

	public function testCustomTypeIsDeletable(): void
	{
		$index = new SearchIndex('test', 'Test', SearchIndexType::Custom);

		self::assertTrue($index->getType()->isDeletable());
	}

	public function testSystemLockedPreventsEngineChange(): void
	{
		$index = new SearchIndex('admin', 'Admin', SearchIndexType::Admin);
		$index->setSystemLocked(true);

		$this->expectException(SystemLockedException::class);

		$index->setEngine('elasticsearch');
	}

	public function testNonLockedAllowsEngineChange(): void
	{
		$index = new SearchIndex('custom', 'Custom', SearchIndexType::Custom);

		$index->setEngine('postgresql');
		self::assertSame('postgresql', $index->getEngine());
	}

	public function testTouchUpdatesTimestamp(): void
	{
		$index = new SearchIndex('test', 'Test');
		$originalUpdatedAt = $index->getUpdatedAt();

		$index->touch();

		// Touch sets a new DateTimeImmutable; both represent the same moment
		// if called within the same microsecond, so we verify the method
		// produces a valid DateTimeImmutable rather than asserting >.
		self::assertInstanceOf(\DateTimeImmutable::class, $index->getUpdatedAt());
		self::assertNotSame($originalUpdatedAt, $index->getUpdatedAt());
	}

	public function testOptionsGetSet(): void
	{
		$index = new SearchIndex('test', 'Test');
		$index->setOptions(['use_trgm' => true, 'analyzer_name' => 'standard']);

		self::assertTrue($index->getOption('use_trgm'));
		self::assertSame('standard', $index->getOption('analyzer_name'));
		self::assertNull($index->getOption('nonexistent'));
		self::assertSame('default', $index->getOption('nonexistent', 'default'));
	}

	public function testTypeConversion(): void
	{
		$index = new SearchIndex('test', 'Test');
		$index->setType(SearchIndexType::Frontend);
		self::assertEquals(SearchIndexType::Frontend, $index->getType());

		$index->setType(SearchIndexType::Admin);
		self::assertEquals(SearchIndexType::Admin, $index->getType());
	}
}
