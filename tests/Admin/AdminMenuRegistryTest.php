<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Admin;

use iikiti\CMS\Admin\CoreAdminExtension;
use PHPUnit\Framework\TestCase;

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

		$dashboard = array_values(array_filter($items, fn ($i): bool => $i->path === '/dashboard'));
		self::assertCount(1, $dashboard);
		self::assertSame(0, $dashboard[0]->priority);

		$users = array_values(array_filter($items, fn ($i): bool => $i->path === '/users'));
		self::assertSame(100, $users[0]->priority);
	}

	public function testMenuItemsHaveIcons(): void
	{
		$extension = new CoreAdminExtension();

		$items = $extension->getMenuItems();

		foreach ($items as $item) {
			if ($item->path === '/dashboard') {
				continue;
			}
			self::assertNotNull($item->icon, "Item {$item->label} should have an icon");
		}
	}
}
