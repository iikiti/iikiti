<?php

namespace iikiti\CMS\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use iikiti\CMS\Repository\RoleRepository;

/**
 * Persistent role definition with metadata and permission templates.
 *
 * Roles are registered at runtime via {@see \iikiti\CMS\Enum\UserRoleEnum} and
 * seeded into this entity by migration. The {@see defaultPermissions} field is
 * immutable — it cannot be modified by anyone. The {@see customPermissions}
 * field is editable by administrators to extend a role's capabilities.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
#[ORM\UniqueConstraint(name: 'uniq_role_value', columns: ['value'])]
class Role
{
	#[ORM\Id]
	#[ORM\GeneratedValue(strategy: 'IDENTITY')]
	#[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
	private int|string|null $id = null;

	#[ORM\Column(type: Types::STRING, length: 128)]
	private string $name;

	#[ORM\Column(type: Types::STRING, length: 128, unique: true)]
	private string $value;

	#[ORM\Column(name: 'is_default', type: Types::BOOLEAN, options: ['default' => true])]
	private bool $isDefault = true;

	#[ORM\Column(name: 'is_deletable', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isDeletable = false;

	#[ORM\Column(name: 'is_hidden', type: Types::BOOLEAN, options: ['default' => false])]
	private bool $isHidden = false;

	/** @var array<string,array<string>> */
	#[ORM\Column(name: 'default_permissions', type: Types::JSON, options: ['default' => '{}'])]
	private array $defaultPermissions = [];

	/** @var array<string,array<string>> */
	#[ORM\Column(name: 'custom_permissions', type: Types::JSON, options: ['default' => '{}'])]
	private array $customPermissions = [];

	#[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $createdAt;

	#[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
	private \DateTimeImmutable $updatedAt;

	/**
	 * @param array<string,array<string>> $defaultPermissions
	 */
	public function __construct(
		string $name,
		string $value,
		array $defaultPermissions = [],
	) {
		$this->name = $name;
		$this->value = $value;
		$this->defaultPermissions = $defaultPermissions;
		$this->createdAt = new \DateTimeImmutable();
		$this->updatedAt = new \DateTimeImmutable();
	}

	public function getId(): int|string|null
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): static
	{
		$this->name = $name;
		$this->touch();

		return $this;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function isDefault(): bool
	{
		return $this->isDefault;
	}

	public function setDefault(bool $isDefault): static
	{
		$this->isDefault = $isDefault;
		$this->touch();

		return $this;
	}

	public function isDeletable(): bool
	{
		return $this->isDeletable;
	}

	public function setDeletable(bool $isDeletable): static
	{
		$this->isDeletable = $isDeletable;
		$this->touch();

		return $this;
	}

	public function isHidden(): bool
	{
		return $this->isHidden;
	}

	public function setHidden(bool $isHidden): static
	{
		$this->isHidden = $isHidden;
		$this->touch();

		return $this;
	}

	/**
	 * @return array<string,array<string>>
	 */
	public function getDefaultPermissions(): array
	{
		return $this->defaultPermissions;
	}

	/**
	 * @return array<string,array<string>>
	 */
	public function getCustomPermissions(): array
	{
		return $this->customPermissions;
	}

	/**
	 * @param array<string,array<string>> $customPermissions
	 */
	public function setCustomPermissions(array $customPermissions): static
	{
		$this->customPermissions = $customPermissions;
		$this->touch();

		return $this;
	}

	/**
	 * Merged permissions: default + custom (custom overrides default for same keys).
	 *
	 * @return array<string,array<string>>
	 */
	public function getAllPermissions(): array
	{
		$all = $this->defaultPermissions;

		foreach ($this->customPermissions as $objectType => $actions) {
			if (isset($all[$objectType])) {
				$all[$objectType] = array_values(array_unique(array_merge(
					$all[$objectType],
					$actions,
				)));
			} else {
				$all[$objectType] = $actions;
			}
		}

		return $all;
	}

	/**
	 * Add a custom permission (cannot modify default permissions).
	 */
	public function addPermission(string $objectType, string $action): static
	{
		if (!isset($this->customPermissions[$objectType])) {
			$this->customPermissions[$objectType] = [];
		}

		if (!in_array($action, $this->customPermissions[$objectType], true)) {
			$this->customPermissions[$objectType][] = $action;
		}

		$this->touch();

		return $this;
	}

	/**
	 * Remove a custom permission (cannot remove from default permissions).
	 */
	public function removePermission(string $objectType, string $action): static
	{
		if (!isset($this->customPermissions[$objectType])) {
			return $this;
		}

		$this->customPermissions[$objectType] = array_values(array_filter(
			$this->customPermissions[$objectType],
			static fn (string $a): bool => $a !== $action,
		));

		if ([] === $this->customPermissions[$objectType]) {
			unset($this->customPermissions[$objectType]);
		}

		$this->touch();

		return $this;
	}

	/**
	 * Check if this role has the given permission (default or custom).
	 * Uses wildcard: '*' in objectType or action matches any.
	 */
	public function can(string $objectType, string $action): bool
	{
		$permissions = $this->getAllPermissions();

		if (isset($permissions['*']) && in_array('*', $permissions['*'], true)) {
			return true;
		}

		if (isset($permissions[$objectType])) {
			if (in_array('*', $permissions[$objectType], true) || in_array($action, $permissions[$objectType], true)) {
				return true;
			}
		}

		return false;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getUpdatedAt(): \DateTimeImmutable
	{
		return $this->updatedAt;
	}

	private function touch(): void
	{
		$this->updatedAt = new \DateTimeImmutable();
	}
}
