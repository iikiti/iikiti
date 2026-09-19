<?php

namespace iikiti\CMS\Tests\Search;

use iikiti\CMS\Search\Enum\SearchFilterVisibility;
use PHPUnit\Framework\TestCase;

final class SearchFilterVisibilityTest extends TestCase
{
	public function testLabels(): void
	{
		self::assertSame('Public (front-end)', SearchFilterVisibility::PublicFrontend->getLabel());
		self::assertSame('Administration only', SearchFilterVisibility::AdminOnly->getLabel());
		self::assertSame('Role-restricted', SearchFilterVisibility::RoleRestricted->getLabel());
	}

	public function testFrontendVisibility(): void
	{
		self::assertTrue(SearchFilterVisibility::PublicFrontend->isVisibleToFrontend());
		self::assertFalse(SearchFilterVisibility::AdminOnly->isVisibleToFrontend());
		self::assertFalse(SearchFilterVisibility::RoleRestricted->isVisibleToFrontend());
	}

	public function testAdminVisibility(): void
	{
		self::assertTrue(SearchFilterVisibility::PublicFrontend->isVisibleToAdmin());
		self::assertTrue(SearchFilterVisibility::AdminOnly->isVisibleToAdmin());
		self::assertTrue(SearchFilterVisibility::RoleRestricted->isVisibleToAdmin());
	}
}
