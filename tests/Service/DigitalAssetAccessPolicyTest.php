<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\UploadedDigitalAsset;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class DigitalAssetAccessPolicyTest extends TestCase
{
	public function testSiteAdministratorCanManageOnlyAssetsGrantedToSitesTheyAdminister(): void
	{
		$siteA = $this->site(31);
		$siteB = $this->site(32);
		$user = $this->user(['31' => ['ROLE_ADMIN']]);
		$policy = $this->policy();
		$siteAsset = new UploadedDigitalAsset('Asset A', 'image/png', str_repeat('a', 64));
		$siteAsset->setAccessSites([$siteA]);
		$sharedAsset = new UploadedDigitalAsset('Shared', 'image/png', str_repeat('b', 64));
		$sharedAsset->setAccessSites([$siteA, $siteB]);

		self::assertTrue($policy->canManageSite($user, $siteA));
		self::assertFalse($policy->canManageSite($user, $siteB));
		self::assertTrue($policy->canManageAsset($user, $siteAsset));
		self::assertFalse($policy->canManageAsset($user, $sharedAsset));
	}

	public function testGlobalSystemRoleCanManageSharedAssetsAcrossSites(): void
	{
		$siteA = $this->site(41);
		$siteB = $this->site(42);
		$user = $this->user([0 => ['ROLE_SYSTEM']]);
		$asset = new UploadedDigitalAsset('Shared', 'image/png', str_repeat('c', 64));
		$asset->setAccessSites([$siteA, $siteB]);
		$policy = $this->policy();

		self::assertTrue($policy->isGlobalAdmin($user));
		self::assertTrue($policy->canManageSite($user, $siteA));
		self::assertTrue($policy->canManageAsset($user, $asset));
	}

	private function policy(): DigitalAssetAccessPolicy
	{
		$sites = $this->createStub(SiteRepository::class);
		$hierarchy = $this->createStub(RoleHierarchyInterface::class);
		$hierarchy->method('getReachableRoleNames')->willReturnCallback(static fn (array $roles): array => array_values($roles));

		return new DigitalAssetAccessPolicy($sites, $hierarchy);
	}

	private function site(int $id): Site
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, $id);

		return $site;
	}

	/** @param array<int|string,list<string>> $roles */
	private function user(array $roles): User
	{
		$user = new User();
		$user->setProperties(new ArrayCollection());
		$user->setProperty('roles', $roles);

		return $user;
	}
}
