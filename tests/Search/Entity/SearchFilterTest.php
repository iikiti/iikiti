<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SearchFilter;
use iikiti\CMS\Search\Enum\SearchFilterMode;
use iikiti\CMS\Search\Enum\SearchFilterVisibility;
use PHPUnit\Framework\TestCase;

final class SearchFilterTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$filter = new SearchFilter('category', 'Category');

		self::assertSame('category', $filter->getName());
		self::assertSame('Category', $filter->getLabel());
		self::assertSame(SearchFilterMode::QueryTime, $filter->getMode());
		self::assertSame(SearchFilterVisibility::PublicFrontend, $filter->getVisibility());
		self::assertTrue($filter->isEnabled());
		self::assertSame([], $filter->getRequiredRoles());
		self::assertSame([], $filter->getOptions());
	}

	public function testModeConversion(): void
	{
		$filter = new SearchFilter('test', 'Test');

		$filter->setMode(SearchFilterMode::IndexTime);
		self::assertSame(SearchFilterMode::IndexTime, $filter->getMode());

		$filter->setMode(SearchFilterMode::QueryTime);
		self::assertSame(SearchFilterMode::QueryTime, $filter->getMode());
	}

	public function testVisibilityConversion(): void
	{
		$filter = new SearchFilter('test', 'Test');

		$filter->setVisibility(SearchFilterVisibility::AdminOnly);
		self::assertSame(SearchFilterVisibility::AdminOnly, $filter->getVisibility());

		$filter->setVisibility(SearchFilterVisibility::RoleRestricted);
		self::assertSame(SearchFilterVisibility::RoleRestricted, $filter->getVisibility());
	}

	public function testRequiredRoles(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setRequiredRoles(['ROLE_ADMIN', 'ROLE_EDITOR']);

		self::assertSame(['ROLE_ADMIN', 'ROLE_EDITOR'], $filter->getRequiredRoles());
	}

	public function testRequiredRolesDefaultsToEmptyArray(): void
	{
		$filter = new SearchFilter('test', 'Test');

		self::assertSame([], $filter->getRequiredRoles());
	}

	public function testIsEnabled(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setEnabled(false);

		self::assertFalse($filter->isEnabled());
	}

	public function testOptionsGetSet(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setOptions(['property' => 'category']);

		self::assertSame('category', $filter->getOption('property'));
		self::assertNull($filter->getOption('nonexistent'));
		self::assertSame('default', $filter->getOption('nonexistent', 'default'));
	}

	public function testHookGetSet(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setHook('App\\Search\\CategoryFilter::apply');

		self::assertSame('App\\Search\\CategoryFilter::apply', $filter->getHook());
	}

	public function testIsVisibleToFrontend(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setVisibility(SearchFilterVisibility::PublicFrontend);

		self::assertTrue($filter->isVisibleToFrontend());
	}

	public function testIsNotVisibleToFrontendForAdminOnly(): void
	{
		$filter = new SearchFilter('test', 'Test');
		$filter->setVisibility(SearchFilterVisibility::AdminOnly);

		self::assertFalse($filter->isVisibleToFrontend());
	}
}
