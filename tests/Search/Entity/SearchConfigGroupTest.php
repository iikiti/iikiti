<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SearchConfigGroup;
use PHPUnit\Framework\TestCase;

final class SearchConfigGroupTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$group = new SearchConfigGroup('my_group', 'My Group');

		self::assertSame('my_group', $group->getName());
		self::assertSame('My Group', $group->getLabel());
		self::assertNull($group->getDescription());
		self::assertFalse($group->isSystem());
		self::assertNull($group->getSiteId());
		self::assertSame([], $group->getSearchIndexIds());
		self::assertSame([], $group->getSiteAssignments());
		self::assertSame([], $group->getSiteGroupAssignments());
	}

	public function testSystemFlag(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setSystem(true);

		self::assertTrue($group->isSystem());
	}

	public function testSiteId(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setSiteId(5);

		self::assertSame(5, $group->getSiteId());
	}

	public function testSearchIndexIds(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setSearchIndexIds([1, 2, 3]);

		self::assertSame([1, 2, 3], $group->getSearchIndexIds());
	}

	public function testSiteAssignments(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setSiteAssignments(['site1', 'site2']);

		self::assertSame(['site1', 'site2'], $group->getSiteAssignments());
	}

	public function testSiteGroupAssignments(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setSiteGroupAssignments([10, 20]);

		self::assertSame([10, 20], $group->getSiteGroupAssignments());
	}

	public function testDescription(): void
	{
		$group = new SearchConfigGroup('default', 'Default');
		$group->setDescription('A test group');

		self::assertSame('A test group', $group->getDescription());
	}
}
