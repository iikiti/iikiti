<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Admin;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\Admin\AdminExtensionTrait;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\Admin\CoreAdminExtension;
use iikiti\CMS\Admin\PluginOwnedAdminExtensionInterface;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Plugin\PluginActivationChecker;
use iikiti\CMS\Plugin\PluginManifest;
use iikiti\CMS\Plugin\PluginRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Security\GuestApiTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminMenuRegistryTest extends TestCase
{
	public function testCoreExtensionProvidesMenuItems(): void
	{
		$extension = new CoreAdminExtension();

		$items = $extension->getMenuItems();

		self::assertNotEmpty($items);
		$labels = array_map(fn ($item): string => $item->label, $items);
		self::assertContains('Users', $labels);
		self::assertContains('User Groups', $labels);
		self::assertContains('Roles & Permissions', $labels);
		self::assertContains('Applications', $labels);
		self::assertContains('Sites', $labels);
		self::assertContains('Site Groups', $labels);
		self::assertContains('Plugins', $labels);
		self::assertContains('Audit Log', $labels);
	}

	public function testCoreExtensionProvidesResources(): void
	{
		$extension = new CoreAdminExtension();

		$resources = $extension->getResources();

		$labels = array_map(fn ($r): string => $r->label, $resources);
		self::assertContains('Users', $labels);
		self::assertContains('Roles', $labels);
		self::assertContains('Audit Log', $labels);
	}

	public function testMenuItemsHavePriorityOrdering(): void
	{
		$extension = new CoreAdminExtension();

		$items = $extension->getMenuItems();

		$dashboard = array_values(array_filter($items, fn ($i): bool => '/dashboard' === $i->path));
		self::assertCount(1, $dashboard);
		// The registry sorts descending by priority (higher sorts first), so
		// the Dashboard carries the highest priority to lead the navigation.
		self::assertSame(1000, $dashboard[0]->priority);

		$users = array_values(array_filter($items, fn ($i): bool => '/users' === $i->path));
		self::assertSame(100, $users[0]->priority);
	}

	public function testDashboardSortsFirstInRegistry(): void
	{
		$extension = new CoreAdminExtension();
		$registry = new AdminMenuRegistry([$extension]);

		$items = $registry->getMenu();

		self::assertNotEmpty($items);
		self::assertSame('Dashboard', $items[0]->label);
	}

	public function testMenuItemsHaveIcons(): void
	{
		$extension = new CoreAdminExtension();

		$items = $extension->getMenuItems();

		foreach ($items as $item) {
			if ('/dashboard' === $item->path) {
				continue;
			}
			self::assertNotNull($item->icon, "Item {$item->label} should have an icon");
		}
	}

	public function testRegistryCollectsScreens(): void
	{
		$extension = new CoreAdminExtension();
		$registry = new AdminMenuRegistry([$extension]);

		$screens = $registry->getScreens();

		self::assertNotEmpty($screens);
		$paths = array_map(fn (AdminScreen $s): string => $s->path, $screens);
		self::assertContains('/dashboard', $paths);
		self::assertContains('/users', $paths);
		self::assertContains('/plugins', $paths);
		self::assertContains('/audit-log', $paths);
	}

	public function testFirewallScreenIsRegisteredForTheSvelteRoute(): void
	{
		$registry = new AdminMenuRegistry([new CoreAdminExtension()]);

		$firewall = array_values(array_filter(
			$registry->getScreens(),
			static fn (AdminScreen $s): bool => '/security/firewall' === $s->path,
		));

		self::assertCount(1, $firewall);
		self::assertSame('Firewall', $firewall[0]->component);
		self::assertSame('custom', $firewall[0]->type);
	}

	public function testScreensAreSortedByPath(): void
	{
		$extension = new CoreAdminExtension();
		$registry = new AdminMenuRegistry([$extension]);

		$screens = $registry->getScreens();

		$paths = array_map(fn (AdminScreen $s): string => $s->path, $screens);
		$sorted = $paths;
		sort($sorted);
		self::assertSame($sorted, $paths);
	}

	public function testFiltersPluginOwnedAdminExtensionsByActivation(): void
	{
		$registry = new PluginRegistry();
		$registry->add(new PluginManifest('Inactive', 'inactive-plugin', 'Acme\\Inactive', 'Acme\\Inactive\\InactiveBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_OPT_IN));
		$registry->add(new PluginManifest('Active', 'active-plugin', 'Acme\\Active', 'Acme\\Active\\ActiveBundle', '1.0.0', '^1.0', activationMode: PluginManifest::ACTIVATION_DEFAULT_ON));

		$siteRegistry = new SiteRegistry(
			new RequestStack(),
			$this->createStub(ManagerRegistry::class),
			new GuestApiTokenManager('test-secret'),
			'api',
		);
		SiteRegistry::pushSite(new Site());
		try {
			$checker = new PluginActivationChecker($registry, $siteRegistry);

			$inactiveExt = new class implements PluginOwnedAdminExtensionInterface {
				use AdminExtensionTrait;

				public function getPluginSlug(): string { return 'inactive-plugin'; }
				public function getMenuItems(): array { return [AdminMenuItem::create('Inactive', '/inactive')]; }
				public function getResources(): array { return []; }
				public function getAdminScreens(): array { return [new AdminScreen('/inactive', 'Inactive')]; }
			};

			$activeExt = new class implements PluginOwnedAdminExtensionInterface {
				use AdminExtensionTrait;

				public function getPluginSlug(): string { return 'active-plugin'; }
				public function getMenuItems(): array { return [AdminMenuItem::create('Active', '/active')]; }
				public function getResources(): array { return []; }
				public function getAdminScreens(): array { return [new AdminScreen('/active', 'Active')]; }
			};

			$menuRegistry = new AdminMenuRegistry([$inactiveExt, $activeExt], $checker);
			self::assertCount(1, $menuRegistry->getMenu());
			self::assertSame('/active', $menuRegistry->getMenu()[0]->path);
			self::assertCount(1, $menuRegistry->getScreens());
			self::assertSame('/active', $menuRegistry->getScreens()[0]->path);
		} finally {
			SiteRegistry::popSite();
		}
	}
}
