<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\SiteGroup;
use PHPUnit\Framework\TestCase;

final class SiteGroupTest extends TestCase
{
	private function createSiteGroup(): SiteGroup
	{
		$group = new SiteGroup();
		$group->setProperties(new ArrayCollection());

		return $group;
	}

	public function testDefaults(): void
	{
		$group = $this->createSiteGroup();

		self::assertNull($group->getName());
		self::assertNull($group->getLabel());
		self::assertNull($group->getDescription());
		self::assertFalse($group->isSystem());
		self::assertSame([], $group->getSiteIds());
	}

	public function testName(): void
	{
		$group = $this->createSiteGroup();
		$group->setName('english-sites');

		self::assertSame('english-sites', $group->getName());
	}

	public function testLabel(): void
	{
		$group = $this->createSiteGroup();
		$group->setLabel('English Sites');

		self::assertSame('English Sites', $group->getLabel());
	}

	public function testIsSystem(): void
	{
		$group = $this->createSiteGroup();
		$group->setSystem(true);

		self::assertTrue($group->isSystem());
	}

	public function testAddSiteId(): void
	{
		$group = $this->createSiteGroup();
		$group->addSiteId(1);
		$group->addSiteId(2);

		self::assertSame(['1', '2'], $group->getSiteIds());
	}

	public function testAddDuplicateSiteId(): void
	{
		$group = $this->createSiteGroup();
		$group->addSiteId(1);
		$group->addSiteId(1);

		self::assertSame(['1'], $group->getSiteIds());
	}

	public function testRemoveSiteId(): void
	{
		$group = $this->createSiteGroup();
		$group->addSiteId(1);
		$group->addSiteId(2);

		$group->removeSiteId(1);

		self::assertSame(['2'], $group->getSiteIds());
	}

	public function testSetSiteIds(): void
	{
		$group = $this->createSiteGroup();
		$group->setSiteIds([1, 2, 3]);

		self::assertSame([1, 2, 3], $group->getSiteIds());
	}
}
