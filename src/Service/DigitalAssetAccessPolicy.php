<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class DigitalAssetAccessPolicy
{
	public function __construct(
		private readonly SiteRepository $sites,
		private readonly RoleHierarchyInterface $roleHierarchy,
	) {
	}

	public function isGlobalAdmin(User $user): bool
	{
		$globalRoles = UserRoleManager::convertEnumsToStrings($user->getGlobalRoles());
		$reachableRoles = $this->roleHierarchy->getReachableRoleNames(array_values($globalRoles));

		return in_array('ROLE_SUPER_ADMIN', $reachableRoles, true) || in_array('ROLE_SYSTEM', $reachableRoles, true);
	}

	public function canManageSite(User $user, Site $site): bool
	{
		if ($this->isGlobalAdmin($user)) {
			return true;
		}
		$siteId = $site->getId();
		if (null === $siteId) {
			return false;
		}
		$siteRoles = UserRoleManager::convertEnumsToStrings($user->getRegistrationRoles($siteId));
		$reachableRoles = $this->roleHierarchy->getReachableRoleNames(array_values($siteRoles));

		return in_array('ROLE_ADMIN', $reachableRoles, true) || in_array('ROLE_SYSTEM', $reachableRoles, true);
	}

	/** @return list<Site> */
	public function getManageableSites(User $user): array
	{
		$manageable = [];
		foreach ($this->sites->findAll(['filterBySite' => false]) as $site) {
			if ($this->canManageSite($user, $site)) {
				$manageable[] = $site;
			}
		}

		return $manageable;
	}

	/** @return list<int|string> */
	public function getManageableSiteIds(User $user): array
	{
		$siteIds = [];
		foreach ($this->getManageableSites($user) as $site) {
			$siteId = $site->getId();
			if (null !== $siteId) {
				$siteIds[] = $siteId;
			}
		}

		return $siteIds;
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
