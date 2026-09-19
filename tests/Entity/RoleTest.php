<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity;

use iikiti\CMS\Entity\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
	public function testConstructorSetsDefaults(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', [
			'page' => ['read', 'write'],
		]);

		self::assertSame('Editor', $role->getName());
		self::assertSame('ROLE_EDITOR', $role->getValue());
		self::assertTrue($role->isDefault());
		self::assertFalse($role->isDeletable());
		self::assertFalse($role->isHidden());
		self::assertSame(['page' => ['read', 'write']], $role->getDefaultPermissions());
		self::assertSame([], $role->getCustomPermissions());
	}

	public function testGetAllPermissionsMergesDefaultsAndCustom(): void
	{
		$role = new Role('Manager', 'ROLE_MANAGER', [
			'page' => ['read'],
		]);
		$role->addPermission('user', 'read');
		$role->addPermission('page', 'write');

		$all = $role->getAllPermissions();

		self::assertSame(['read', 'write'], $all['page']);
		self::assertSame(['read'], $all['user']);
	}

	public function testCanWithDefaultPermissions(): void
	{
		$role = new Role('Admin', 'ROLE_ADMIN', [
			'page' => ['read', 'write', 'delete'],
		]);

		self::assertTrue($role->can('page', 'read'));
		self::assertTrue($role->can('page', 'write'));
		self::assertTrue($role->can('page', 'delete'));
		self::assertFalse($role->can('user', 'read'));
	}

	public function testCanWithCustomPermissions(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', ['page' => ['read', 'write']]);
		$role->addPermission('user', 'read');

		self::assertTrue($role->can('user', 'read'));
	}

	public function testCanWithWildcard(): void
	{
		$role = new Role('System', 'ROLE_SYSTEM', ['*' => ['*']]);

		self::assertTrue($role->can('anything', 'read'));
		self::assertTrue($role->can('anything', 'write'));
	}

	public function testAddPermissionCreatesNewObjectType(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->addPermission('search', 'read');

		self::assertSame(['search' => ['read']], $role->getCustomPermissions());
	}

	public function testAddPermissionAppendsToExisting(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->addPermission('page', 'read');
		$role->addPermission('page', 'write');

		self::assertSame(['read', 'write'], $role->getCustomPermissions()['page']);
	}

	public function testAddDuplicatePermissionDoesNotDuplicate(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->addPermission('page', 'read');
		$role->addPermission('page', 'read');

		self::assertSame(['read'], $role->getCustomPermissions()['page']);
	}

	public function testRemovePermission(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->addPermission('page', 'read');
		$role->addPermission('page', 'write');
		$role->removePermission('page', 'read');

		self::assertSame(['write'], $role->getCustomPermissions()['page']);
	}

	public function testRemovePermissionCleansUpEmptyType(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->addPermission('page', 'read');
		$role->removePermission('page', 'read');

		self::assertSame([], $role->getCustomPermissions());
	}

	public function testRemovePermissionNotInCustomIsNoop(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', ['page' => ['read']]);
		$role->removePermission('page', 'read');

		self::assertSame([], $role->getCustomPermissions());
		self::assertTrue($role->can('page', 'read'));
	}

	public function testDefaultPermissionsCannotBeRemoved(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', ['page' => ['read', 'write']]);
		$role->removePermission('page', 'read');

		self::assertSame([], $role->getCustomPermissions());
		self::assertSame(['page' => ['read', 'write']], $role->getAllPermissions());
	}

	public function testSetName(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->setName('Senior Editor');

		self::assertSame('Senior Editor', $role->getName());
	}

	public function testSetDeletable(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->setDeletable(true);

		self::assertTrue($role->isDeletable());
	}

	public function testSetHidden(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', []);
		$role->setHidden(true);

		self::assertTrue($role->isHidden());
	}
}
