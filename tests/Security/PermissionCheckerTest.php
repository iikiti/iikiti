<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Entity\Role;
use PHPUnit\Framework\TestCase;

final class PermissionCheckerTest extends TestCase
{
	public function testRoleCanCheckPermissions(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', [
			'page' => ['read', 'write'],
		]);

		self::assertTrue($role->can('page', 'read'));
		self::assertTrue($role->can('page', 'write'));
		self::assertFalse($role->can('page', 'delete'));
		self::assertFalse($role->can('user', 'read'));
	}

	public function testRoleWithWildcard(): void
	{
		$role = new Role('System', 'ROLE_SYSTEM', ['*' => ['*']]);

		self::assertTrue($role->can('anything', 'read'));
		self::assertTrue($role->can('anything', 'write'));
	}

	public function testCustomPermissionsOverrideDefaults(): void
	{
		$role = new Role('Manager', 'ROLE_MANAGER', [
			'page' => ['read'],
		]);

		self::assertFalse($role->can('user', 'read'));

		$role->addPermission('user', 'read');

		self::assertTrue($role->can('user', 'read'));
	}

	public function testDefaultPermissionsRemainImmutable(): void
	{
		$role = new Role('Editor', 'ROLE_EDITOR', ['page' => ['read', 'write']]);
		$role->removePermission('page', 'read');

		// Removing from custom (which doesn't have 'read') doesn't affect defaults
		self::assertSame(['read', 'write'], $role->getAllPermissions()['page']);
	}
}
