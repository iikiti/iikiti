<?php

declare(strict_types=1);

namespace iikiti\CMS\Security;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Neutral core policy for global-admin and site-management authorization
 * checks across unrelated CMS subsystems and plugins.
 */
final class SiteAccessPolicy
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

	/**
	 * Checks whether a user can manage a site with any of the allowed roles.
	 *
	 * @param list<string> $allowedSiteRoles
	 */
	public function canManageSite(
		User $user,
		Site $site,
		array $allowedSiteRoles = ['ROLE_ADMIN', 'ROLE_SYSTEM'],
	): bool {
		if ($this->isGlobalAdmin($user)) {
			return true;
		}
		$siteId = $site->getId();
		if (null === $siteId) {
			return false;
		}

		$siteRoles = UserRoleManager::convertEnumsToStrings($user->getRegistrationRoles($siteId));
		$reachableRoles = $this->roleHierarchy->getReachableRoleNames(array_values($siteRoles));

		foreach ($allowedSiteRoles as $role) {
			if (in_array($role, $reachableRoles, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $allowedSiteRoles
	 *
	 * @return list<Site>
	 */
	public function getManageableSites(User $user, array $allowedSiteRoles = ['ROLE_ADMIN', 'ROLE_SYSTEM']): array
	{
		$manageable = [];
		foreach ($this->sites->findAll(['filterBySite' => false]) as $site) {
			if ($this->canManageSite($user, $site, $allowedSiteRoles)) {
				$manageable[] = $site;
			}
		}

		return $manageable;
	}

	/**
	 * @param list<string> $allowedSiteRoles
	 *
	 * @return list<int|string>
	 */
	public function getManageableSiteIds(User $user, array $allowedSiteRoles = ['ROLE_ADMIN', 'ROLE_SYSTEM']): array
	{
		$siteIds = [];
		foreach ($this->getManageableSites($user, $allowedSiteRoles) as $site) {
			$siteId = $site->getId();
			if (null !== $siteId) {
				$siteIds[] = $siteId;
			}
		}

		return $siteIds;
	}

	public function hasSiteRole(User $user, Site $site, string $roleKey): bool
	{
		$siteId = $site->getId();
		if (null === $siteId) {
			return false;
		}
		$roles = UserRoleManager::convertEnumsToStrings($user->getRegistrationRoles($siteId));
		$reachableRoles = $this->roleHierarchy->getReachableRoleNames(array_values($roles));

		return in_array($roleKey, $reachableRoles, true);
	}
}
