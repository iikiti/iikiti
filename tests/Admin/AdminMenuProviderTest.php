<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Admin;

use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\Admin\AdminMenuRegistry;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminMenuResource;
use iikiti\CMS\State\Provider\AdminMenuProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AdminMenuProviderTest extends TestCase
{
	public function testSiteManagerOnlySeesMenuItemsWithExplicitSiteManagerPermission(): void
	{
		$registry = $this->createStub(AdminMenuRegistry::class);
		$registry->method('getMenu')->willReturn([
			AdminMenuItem::create('Users', '/users', 'users', 10),
			AdminMenuItem::create('Workflows', '/workflows', 'workflow', 20, [
				AdminMenuItem::create('Definitions', '/workflows/definitions', null, 21, [], 'ROLE_SITE_MANAGER'),
			], 'ROLE_SITE_MANAGER'),
		]);
		$security = $this->createStub(Security::class);
		$security->method('isGranted')->willReturnCallback(static fn (string $role): bool => 'ROLE_SITE_MANAGER' === $role);
		$provider = new AdminMenuProvider($registry, $security);

		$result = $provider->provide(new GetCollection());

		self::assertInstanceOf(AdminMenuResource::class, $result);
		self::assertCount(1, $result->items);
		self::assertSame('/workflows', $result->items[0]->path);
		self::assertSame(['/workflows/definitions'], array_map(static fn (AdminMenuItem $item): string => $item->path, $result->items[0]->children));
	}
}
