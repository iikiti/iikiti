<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\State\Processor\RoleProcessor;
use iikiti\CMS\State\Provider\RoleProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/roles',
			name: 'admin_roles_list',
			provider: RoleProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/roles/{id}',
			name: 'admin_role_get',
			provider: RoleProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/roles/{id}',
			name: 'admin_role_update',
			processor: RoleProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Delete(
			uriTemplate: '/admin/roles/{id}',
			name: 'admin_role_delete',
			processor: RoleProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class RoleResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public string $name = '',
		public string $value = '',
		public bool $isDefault = true,
		public bool $isDeletable = false,
		public bool $isHidden = false,
		/** @var array<string,array<string>> */
		public array $defaultPermissions = [],
		/** @var array<string,array<string>> */
		public array $customPermissions = [],
		/** @var array<string,array<string>> */
		public array $allPermissions = [],
	) {
	}

	public static function fromEntity(Role $role): self
	{
		return new self(
			id: $role->getId(),
			name: $role->getName(),
			value: $role->getValue(),
			isDefault: $role->isDefault(),
			isDeletable: $role->isDeletable(),
			isHidden: $role->isHidden(),
			defaultPermissions: $role->getDefaultPermissions(),
			customPermissions: $role->getCustomPermissions(),
			allPermissions: $role->getAllPermissions(),
		);
	}
}
