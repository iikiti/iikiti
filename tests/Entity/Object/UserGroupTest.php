<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Object;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\UserGroup;
use PHPUnit\Framework\TestCase;

final class UserGroupTest extends TestCase
{
	private function createUserGroup(): UserGroup
	{
		$group = new UserGroup();
		$group->setProperties(new ArrayCollection());

		return $group;
	}

	public function testDefaults(): void
	{
		$group = $this->createUserGroup();

		self::assertNull($group->getName());
		self::assertNull($group->getLabel());
		self::assertNull($group->getDescription());
		self::assertFalse($group->isSystem());
		self::assertFalse($group->isHidden());
		self::assertSame([], $group->getPermissions());
	}

	public function testName(): void
	{
		$group = $this->createUserGroup();
		$group->setName('content-editors');

		self::assertSame('content-editors', $group->getName());
	}

	public function testLabel(): void
	{
		$group = $this->createUserGroup();
		$group->setLabel('Content Editors');

		self::assertSame('Content Editors', $group->getLabel());
	}

	public function testIsSystem(): void
	{
		$group = $this->createUserGroup();
		$group->setSystem(true);

		self::assertTrue($group->isSystem());
	}

	public function testIsHidden(): void
	{
		$group = $this->createUserGroup();
		$group->setHidden(true);

		self::assertTrue($group->isHidden());
	}

	public function testPermissions(): void
	{
		$group = $this->createUserGroup();
		$perms = ['page' => ['*' => ['read', 'write']]];
		$group->setPermissions($perms);

		self::assertSame($perms, $group->getPermissions());
	}

	public function testCanWithWildcard(): void
	{
		$group = $this->createUserGroup();
		$group->setPermissions([
			'*' => ['*' => ['*']],
		]);

		self::assertTrue($group->can('Page', 'read'));
		self::assertTrue($group->can('User', 'delete'));
	}

	public function testCanWithSpecificObjectType(): void
	{
		$group = $this->createUserGroup();
		$group->setPermissions([
			'page' => ['*' => ['read', 'write']],
		]);

		self::assertTrue($group->can('page', 'read'));
		self::assertFalse($group->can('user', 'read'));
	}

	public function testCanWithSpecificObjectId(): void
	{
		$group = $this->createUserGroup();
		$group->setPermissions([
			'page' => [
				'1' => ['read'],
				'2' => ['write'],
			],
		]);

		self::assertTrue($group->can('page', 'read', '1'));
		self::assertFalse($group->can('page', 'write', '1'));
		self::assertTrue($group->can('page', 'write', '2'));
		self::assertFalse($group->can('page', 'read', '2'));
	}

	public function testCanWithActionOnWildcardObject(): void
	{
		$group = $this->createUserGroup();
		$group->setPermissions([
			'page' => [
				'*' => ['read'],
			],
		]);

		self::assertTrue($group->can('page', 'read', '999'));
		self::assertFalse($group->can('page', 'write', '999'));
	}

	public function testCanWithNoPermissions(): void
	{
		$group = $this->createUserGroup();

		self::assertFalse($group->can('Page', 'read'));
	}
}
