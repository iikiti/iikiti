<?php

namespace iikiti\CMS\Security;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\UserGroupRepository;
use iikiti\CMS\Repository\RoleRepository;

/**
 * Resolves whether a user has a given permission on an object type.
 *
 * Permission resolution order:
 * 1. ROLE_SYSTEM always grants access (system-level override).
 * 2. Check user's roles against Role entities' permissions (default + custom).
 * 3. Check user's group memberships against UserGroup ACL permissions.
 * 4. Deny by default.
 *
 * Roles provide the permission template; user groups provide ACL overrides.
 */
class PermissionChecker
{
	public function __construct(
		private readonly RoleRepository $roleRepository,
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	/**
	 * Check if the user has the given permission on the object type.
	 *
	 * @param string     $objectType  Short entity type (e.g. 'User', 'Page', 'Application')
	 * @param string     $action      Action name (e.g. 'read', 'write', 'delete')
	 * @param mixed|null $object       Optional object instance for fine-grained checks
	 */
	public function canAccess(User $user, string $objectType, string $action, mixed $object = null): bool
	{
		$siteId = SiteRegistry::hasCurrent() ? (SiteRegistry::getCurrent()->getId() ?? 0) : 0;
		$siteId = (string) $siteId;

		$roleEnums = $user->getRegistrationRoles((string) $siteId);
		$roleValues = UserRoleManager::convertEnumsToStrings($roleEnums);

		if (in_array('ROLE_SYSTEM', $roleValues, true)) {
			return true;
		}

		if ($this->checkRolePermissions($roleValues, $objectType, $action)) {
			return true;
		}

		if ($this->checkGroupPermissions($user, $objectType, $action, $object)) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<int|string,string> $roleValues
	 */
	private function checkRolePermissions(array $roleValues, string $objectType, string $action): bool
	{
		foreach ($roleValues as $value) {
			$role = $this->roleRepository->findByValue($value);
			if (null === $role) {
				continue;
			}

			if ($role->can($objectType, $action)) {
				return true;
			}
		}

		return false;
	}

	private function checkGroupPermissions(User $user, string $objectType, string $action, mixed $object = null): bool
	{
		$groupIds = $this->getUserGroupIds($user);
		if ([] === $groupIds) {
			return false;
		}

		/** @var UserGroupRepository $groupRepo */
		$groupRepo = $this->entityManager->getRepository(UserGroup::class);
		$groups = $groupRepo->findByProperty('groups', $groupIds);

		foreach ($groups as $group) {
			$objectId = $object instanceof DbObject ? $object->getId() : null;
			$canAccess = $group->can($objectType, $action, $objectId !== null ? (string) $objectId : null);
			if ($canAccess) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private function getUserGroupIds(User $user): array
	{
		$value = $user->getProperties()->get('groups')?->getValue();

		return is_array($value) ? array_values($value) : [];
	}
}
