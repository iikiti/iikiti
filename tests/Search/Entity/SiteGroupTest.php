<?php

namespace iikiti\CMS\Tests\Search\Entity;

use iikiti\CMS\Search\Entity\SiteGroup;
use PHPUnit\Framework\TestCase;

final class SiteGroupTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$group = new SiteGroup('my_group', 'My Group');

		self::assertSame('my_group', $group->getName());
		self::assertSame('My Group', $group->getLabel());
		self::assertNull($group->getDescription());
		self::assertFalse($group->isSystem());
		self::assertSame([], $group->getSiteIds());
	}

	public function testAddSiteId(): void
	{
		$group = new SiteGroup('default', 'Default');
		$group->addSiteId(1);
		$group->addSiteId(2);

		self::assertSame(['1', '2'], $group->getSiteIds());
	}

	public function testAddDuplicateSiteId(): void
	{
		$group = new SiteGroup('default', 'Default');
		$group->addSiteId(1);
		$group->addSiteId(1);

		self::assertSame(['1'], $group->getSiteIds());
	}

	public function testRemoveSiteId(): void
	{
		$group = new SiteGroup('default', 'Default');
		$group->addSiteId(1);
		$group->addSiteId(2);
		$group->removeSiteId(1);

		self::assertSame(['2'], $group->getSiteIds());
	}

	public function testSetSiteIds(): void
	{
		$group = new SiteGroup('default', 'Default');
		$group->setSiteIds([1, 2, 3]);

		self::assertSame([1, 2, 3], $group->getSiteIds());
	}

	public function testSystemFlag(): void
	{
		$group = new SiteGroup('default', 'Default');
		$group->setSystem(true);

		self::assertTrue($group->isSystem());
	}
}
