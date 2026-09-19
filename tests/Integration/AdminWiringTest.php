<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\Admin\CoreAdminExtension;
use iikiti\CMS\Security\ApiTokenManager;
use iikiti\CMS\Security\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminWiringTest extends KernelTestCase
{
	public function testAdminServicesAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		self::assertInstanceOf(AdminMenuRegistry::class, $container->get(AdminMenuRegistry::class));
		self::assertInstanceOf(CoreAdminExtension::class, $container->get(CoreAdminExtension::class));
	}

	public function testApiTokenManagerIsRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		self::assertInstanceOf(ApiTokenManager::class, $container->get(ApiTokenManager::class));
	}

	public function testPermissionCheckerIsRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		self::assertInstanceOf(PermissionChecker::class, $container->get(PermissionChecker::class));
	}

	public function testAuditServicesAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		self::assertInstanceOf(
			\iikiti\CMS\Audit\AuditLogger::class,
			$container->get(\iikiti\CMS\Audit\AuditLogger::class)
		);
	}

	public function testAdminMenuExtensionTagIsApplied(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$registry = $container->get(AdminMenuRegistry::class);
		$menu = $registry->getMenu();

		$labels = array_map(fn ($item): string => $item->label, $menu);
		self::assertContains('Users', $labels);
		self::assertContains('Roles & Permissions', $labels);
	}

	public function testAdminRoutesExist(): void
	{
		self::bootKernel();

		$router = self::getContainer()->get('router');
		$routeCollection = $router->getRouteCollection();

		$expectedRoutes = [
			'admin_home' => '/admin',
			'admin_users_list' => '/api/admin/users',
			'admin_user_groups_list' => '/api/admin/user-groups',
			'admin_roles_list' => '/api/admin/roles',
			'admin_audit_logs_list' => '/api/admin/audit-logs',
			'admin_menu_list' => '/api/admin/menu',
		];

		foreach ($expectedRoutes as $name => $path) {
			$route = $routeCollection->get($name);
			self::assertNotNull($route, "Route {$name} should exist");
			self::assertSame($path, $route->getPath());
		}
	}
}
