<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Admin;

use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;
use PHPUnit\Framework\TestCase;

final class AdminScreenRegistryTest extends TestCase
{
	public function testGetScreensAggregatesFromAllExtensions(): void
	{
		$extensionA = new TestExtensionWithScreens([
			new AdminScreen(path: '/blog', title: 'Blog', type: 'list', apiPath: '/admin/blog'),
		]);

		$extensionB = new TestExtensionWithScreens([
			new AdminScreen(path: '/products', title: 'Products', type: 'list', apiPath: '/admin/products'),
		]);

		$registry = new AdminMenuRegistry([$extensionA, $extensionB]);

		$screens = $registry->getScreens();

		self::assertCount(2, $screens);
		self::assertSame('/blog', $screens[0]->path);
		self::assertSame('/products', $screens[1]->path);
	}

	public function testGetScreensReturnsEmptyForNoScreens(): void
	{
		$extension = new TestExtensionWithScreens([]);

		$registry = new AdminMenuRegistry([$extension]);

		self::assertSame([], $registry->getScreens());
	}

	public function testGetScreensIsSortedByPath(): void
	{
		$extension = new TestExtensionWithScreens([
			new AdminScreen(path: '/zebra', title: 'Z', type: 'list'),
			new AdminScreen(path: '/apple', title: 'A', type: 'list'),
			new AdminScreen(path: '/mango', title: 'M', type: 'list'),
		]);

		$registry = new AdminMenuRegistry([$extension]);

		$screens = $registry->getScreens();

		self::assertSame('/apple', $screens[0]->path);
		self::assertSame('/mango', $screens[1]->path);
		self::assertSame('/zebra', $screens[2]->path);
	}

	public function testGetScreensPreservesScreenTypeAndConfig(): void
	{
		$extension = new TestExtensionWithScreens([
			new AdminScreen(
				path: '/users',
				title: 'Users',
				type: 'list',
				apiPath: '/admin/users',
				config: ['columns' => [['key' => 'id', 'label' => 'ID']]],
			),
			new AdminScreen(
				path: '/dashboard',
				title: 'Dashboard',
				type: 'custom',
				component: 'Dashboard',
			),
		]);

		$registry = new AdminMenuRegistry([$extension]);

		$screens = $registry->getScreens();

		$dashboardScreen = $screens[0];
		self::assertSame('custom', $dashboardScreen->type);
		self::assertSame('Dashboard', $dashboardScreen->component);
		self::assertNull($dashboardScreen->bundle);

		$userScreen = $screens[1];
		self::assertSame('list', $userScreen->type);
		self::assertSame('/admin/users', $userScreen->apiPath);
		self::assertCount(1, $userScreen->config['columns']);
	}
}

/**
 * Minimal stub implementing AdminExtensionInterface for testing.
 */
final class TestExtensionWithScreens implements AdminExtensionInterface
{
	/** @param list<AdminScreen> $screens */
	public function __construct(
		private array $screens = [],
	) {
	}

	public function getMenuItems(): array
	{
		return [AdminMenuItem::create('Test', '/test')];
	}

	public function getResources(): array
	{
		return [AdminApiResource::create('test', '/api/test', 'Test')];
	}

	public function getAdminScreens(): array
	{
		return $this->screens;
	}
}
