<?php

namespace iikiti\CMS\Entity\Object;

use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Entity\DbObject;

/**
 * User group entity.
 *
 * Groups users for collective permission management and ACL overrides.
 * Group membership is stored on the User entity as a `groups` JSON property
 * containing an array of group IDs.
 *
 * Like users, user groups are global (SITE_SPECIFIC = false) by default.
 * Site-scoped groups can be created by setting the `site_id` property.
 */
#[ORM\Entity(repositoryClass: \iikiti\CMS\Repository\Object\UserGroupRepository::class)]
#[ORM\Table(name: 'objects')]
#[ApiResource]
class UserGroup extends DbObject
{
	public const SITE_SPECIFIC = false;

	public const SYSTEM_KEY = 'is_system';
	public const HIDDEN_KEY = 'is_hidden';
	public const NAME_KEY = 'name';
	public const LABEL_KEY = 'label';
	public const DESCRIPTION_KEY = 'description';
	public const PERMISSIONS_KEY = 'permissions';

	/**
	 * @return string[]
	 */
	public function getUserGroupIds(): array
	{
		$value = $this->getProperties()->get('groups')?->getValue();

		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param string[] $groupIds
	 */
	public function setUserGroupIds(array $groupIds): void
	{
		$this->setProperty('groups', array_values($groupIds));
	}

	public function getName(): ?string
	{
		return $this->getProperties()->get(self::NAME_KEY)?->getValue();
	}

	public function setName(string $name): static
	{
		$this->setProperty(self::NAME_KEY, $name);

		return $this;
	}

	public function getLabel(): ?string
	{
		return $this->getProperties()->get(self::LABEL_KEY)?->getValue();
	}

	public function setLabel(string $label): static
	{
		$this->setProperty(self::LABEL_KEY, $label);

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->getProperties()->get(self::DESCRIPTION_KEY)?->getValue();
	}

	public function setDescription(?string $description): void
	{
		$this->setProperty(self::DESCRIPTION_KEY, $description);
	}

	public function isSystem(): bool
	{
		return (bool) $this->getProperties()->get(self::SYSTEM_KEY)?->getValue();
	}

	public function setSystem(bool $isSystem): static
	{
		$this->setProperty(self::SYSTEM_KEY, $isSystem);

		return $this;
	}

	public function isHidden(): bool
	{
		return (bool) $this->getProperties()->get(self::HIDDEN_KEY)?->getValue();
	}

	public function setHidden(bool $isHidden): static
	{
		$this->setProperty(self::HIDDEN_KEY, $isHidden);

		return $this;
	}

	/**
	 * @return array<string,array<string>>
	 */
	/**
	 * @return array<string,array<string|int,array<string>>>
	 */
	public function getPermissions(): array
	{
		$value = $this->getProperties()->get(self::PERMISSIONS_KEY)?->getValue();

		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<string,array<string>> $permissions
	 */
	/**
	 * @param array<string,array<string|int,array<string>>> $permissions
	 */
	public function setPermissions(array $permissions): static
	{
		$this->setProperty(self::PERMISSIONS_KEY, $permissions);

		return $this;
	}

	/**
	 * Check if this group grants the given permission.
	 *
	 * Permissions format: {objectType: {objectId_or_*: {action: [roles]}}}
	 */
	public function can(string $objectType, string $action, ?string $objectId = null): bool
	{
		$permissions = $this->getPermissions();

		/** @var array<string|int,array<string>>|null $wildcardPerms */
		$wildcardPerms = $permissions['*'] ?? null;
		if (is_array($wildcardPerms)) {
			$allActions = $wildcardPerms['*'] ?? null;
			if (is_array($allActions) && in_array('*', $allActions, true)) {
				return true;
			}
			$actionPerms = $wildcardPerms[$action] ?? null;
			if (is_array($actionPerms) && in_array($action, $actionPerms, true)) {
				return true;
			}
		}

		$typePerms = $permissions[$objectType] ?? null;
		if (is_array($typePerms)) {
			$checks = array_filter([$objectId, '*'], static fn (?string $v): bool => null !== $v);
			foreach ($checks as $id) {
				$permList = $typePerms[$id] ?? null;
				if (is_array($permList) && (in_array('*', $permList, true) || in_array($action, $permList, true))) {
					return true;
				}
			}
		}

		return false;
	}
}
