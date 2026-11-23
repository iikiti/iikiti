<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\SiteAccessPolicy;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class DigitalAssetAccessPolicy
{
	private readonly SiteAccessPolicy $siteAccessPolicy;

	public function __construct(
		SiteRepository $sites,
		RoleHierarchyInterface $roleHierarchy,
		?SiteAccessPolicy $siteAccessPolicy = null,
	) {
		$this->siteAccessPolicy = $siteAccessPolicy ?? new SiteAccessPolicy($sites, $roleHierarchy);
	}

	public function isGlobalAdmin(User $user): bool
	{
		return $this->siteAccessPolicy->isGlobalAdmin($user);
	}

	public function canManageSite(User $user, Site $site): bool
	{
		return $this->siteAccessPolicy->canManageSite($user, $site, ['ROLE_ADMIN', 'ROLE_SYSTEM']);
	}

	/** @return list<Site> */
	public function getManageableSites(User $user): array
	{
		return $this->siteAccessPolicy->getManageableSites($user, ['ROLE_ADMIN', 'ROLE_SYSTEM']);
	}

	/** @return list<int|string> */
	public function getManageableSiteIds(User $user): array
	{
		return $this->siteAccessPolicy->getManageableSiteIds($user, ['ROLE_ADMIN', 'ROLE_SYSTEM']);
	}

	public function canManageAsset(User $user, AbstractDigitalAsset $asset): bool
	{
		if ($this->isGlobalAdmin($user)) {
			return true;
		}
		if ($asset->getAccessSites()->isEmpty()) {
			return false;
		}
		foreach ($asset->getAccessSites() as $site) {
			if (!$this->canManageSite($user, $site)) {
				return false;
			}
		}

		return true;
	}

	/** @param list<Site> $sites */
	public function canManageAllSites(User $user, array $sites): bool
	{
		foreach ($sites as $site) {
			if (!$this->canManageSite($user, $site)) {
				return false;
			}
		}

		return true;
	}
}
