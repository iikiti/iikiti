<?php

namespace iikiti\CMS\Tests\Search;

use iikiti\CMS\Search\Enum\SearchFieldSourceType;
use PHPUnit\Framework\TestCase;

final class SearchFieldSourceTypeTest extends TestCase
{
	public function testLabels(): void
	{
		self::assertSame('Database Column', SearchFieldSourceType::Column->getLabel());
		self::assertSame('Object Property', SearchFieldSourceType::Property->getLabel());
		self::assertSame('Virtual Column', SearchFieldSourceType::Virtual->getLabel());
		self::assertSame('Alias', SearchFieldSourceType::Alias->getLabel());
	}

	public function testIsResolvable(): void
	{
		self::assertTrue(SearchFieldSourceType::Column->isResolvable());
		self::assertTrue(SearchFieldSourceType::Property->isResolvable());
		self::assertTrue(SearchFieldSourceType::Virtual->isResolvable());
		self::assertFalse(SearchFieldSourceType::Alias->isResolvable());
	}
}
