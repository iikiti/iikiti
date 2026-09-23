<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoleHierarchyIntegrationTest extends KernelTestCase
{
	public function testCompiledRoleHierarchyContainsWildcardPatterns(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$merged = $container->getParameter('security.role_hierarchy.roles');

		self::assertIsArray($merged);
		self::assertArrayHasKey('ROLE_SYSTEM', $merged);
		self::assertArrayHasKey('ROLE_*', $merged);
		self::assertArrayHasKey('ROLE_PLUGIN_*', $merged);
		self::assertArrayHasKey('ROLE_SITE_*', $merged);
		self::assertArrayHasKey('ROLE_MOD_*', $merged);
	}

	public function testPermissionCheckerReceivesRoleHierarchy(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		self::assertTrue($container->has(\iikiti\CMS\Security\PermissionChecker::class));

		$checker = $container->get(\iikiti\CMS\Security\PermissionChecker::class);

		self::assertInstanceOf(\iikiti\CMS\Security\PermissionChecker::class, $checker);
	}
}
