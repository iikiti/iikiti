<?php

namespace iikiti\CMS\Tests\Search;

use iikiti\CMS\Search\Enum\SearchIndexType;
use PHPUnit\Framework\TestCase;

final class SearchIndexTypeTest extends TestCase
{
	public function testLabels(): void
	{
		self::assertSame('Front-end Search', SearchIndexType::Frontend->getLabel());
		self::assertSame('Administration Search', SearchIndexType::Admin->getLabel());
		self::assertSame('Custom Index', SearchIndexType::Custom->getLabel());
	}

	public function testDeletable(): void
	{
		self::assertFalse(SearchIndexType::Admin->isDeletable());
		self::assertTrue(SearchIndexType::Custom->isDeletable());
	}

	public function testFrontendIsDeletable(): void
	{
		self::assertTrue(SearchIndexType::Frontend->isDeletable());
	}
}
