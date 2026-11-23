<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Security;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Enum\EnumCase;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\SiteAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class SiteAccessPolicyTest extends TestCase
{
	public function testGlobalAdminAndSiteRoleChecks(): void
	{
		$site = $this->createStub(Site::class);
		$site->method('getId')->willReturn(7);

		$otherSite = $this->createStub(Site::class);
		$otherSite->method('getId')->willReturn(8);

		$sites = $this->createStub(SiteRepository::class);
		$sites->method('findAll')->willReturn([$site, $otherSite]);

		$hierarchy = new RoleHierarchy([
			'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
			'ROLE_SITE_MANAGER' => ['ROLE_USER'],
		]);

		$policy = new SiteAccessPolicy($sites, $hierarchy);

		$globalAdmin = $this->createStub(User::class);
		$globalAdmin->method('getGlobalRoles')->willReturn([new EnumCase('SUPER_ADMIN', 'ROLE_SUPER_ADMIN')]);
		$globalAdmin->method('getRegistrationRoles')->willReturn([]);

		self::assertTrue($policy->isGlobalAdmin($globalAdmin));
		self::assertTrue($policy->canManageSite($globalAdmin, $site));
		self::assertSame([7, 8], $policy->getManageableSiteIds($globalAdmin));

		$siteManager = $this->createStub(User::class);
		$siteManager->method('getGlobalRoles')->willReturn([]);
		$siteManager->method('getRegistrationRoles')->willReturnMap([
			[7, [new EnumCase('SITE_MANAGER', 'ROLE_SITE_MANAGER')]],
			[8, []],
		]);

		self::assertFalse($policy->isGlobalAdmin($siteManager));
		self::assertFalse($policy->canManageSite($siteManager, $site, ['ROLE_ADMIN', 'ROLE_SYSTEM']));
		self::assertTrue($policy->canManageSite($siteManager, $site, ['ROLE_SITE_MANAGER', 'ROLE_ADMIN']));
		self::assertFalse($policy->canManageSite($siteManager, $otherSite, ['ROLE_SITE_MANAGER']));
		self::assertTrue($policy->hasSiteRole($siteManager, $site, 'ROLE_SITE_MANAGER'));
		self::assertFalse($policy->hasSiteRole($siteManager, $otherSite, 'ROLE_SITE_MANAGER'));
	}
}
