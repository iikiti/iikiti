<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Service\DigitalAssetAccessPolicy;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class WorkflowAccessPolicy
{
	public function __construct(
		private readonly DigitalAssetAccessPolicy $siteAccess,
		private readonly RoleHierarchyInterface $roleHierarchy,
	) {
	}

	public function isGlobalAdmin(User $user): bool
	{
		return $this->siteAccess->isGlobalAdmin($user);
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
		$roles = UserRoleManager::convertEnumsToStrings($user->getRegistrationRoles($siteId));
		$reachableRoles = $this->roleHierarchy->getReachableRoleNames(array_values($roles));

		return in_array('ROLE_SITE_MANAGER', $reachableRoles, true) ||
			in_array('ROLE_ADMIN', $reachableRoles, true) ||
			in_array('ROLE_SYSTEM', $reachableRoles, true);
	}

	public function canManageDefinition(User $user, WorkflowDefinition $definition, Site $site): bool
	{
		if ('plugin' === $definition->getOwnerType()) {
			return false;
		}
		if ($this->isGlobalAdmin($user)) {
			return true;
		}
		$siteId = $site->getId();

		return 'site' === $definition->getOwnerType() &&
			null !== $siteId &&
			$definition->getOwnerKey() === (string) $siteId &&
			$this->canManageSite($user, $site);
	}

	public function canAssignDefinition(User $user, Site $site): bool
	{
		return $this->isGlobalAdmin($user) || $this->canManageSite($user, $site);
	}

	public function canForkDefinition(User $user, WorkflowDefinition $definition, Site $site): bool
	{
		if ($this->isGlobalAdmin($user)) {
			return true;
		}

		return $this->canManageSite($user, $site) &&
			$definition->hasSite($site);
	}

	public function canActAsSiteRole(User $user, Site $site, string $roleKey): bool
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
