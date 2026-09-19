<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\State\Processor\UserGroupProcessor;
use iikiti\CMS\State\Provider\UserGroupProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/user-groups',
			name: 'admin_user_groups_list',
			provider: UserGroupProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/user-groups/{id}',
			name: 'admin_user_group_get',
			provider: UserGroupProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Post(
			uriTemplate: '/admin/user-groups',
			name: 'admin_user_group_create',
			processor: UserGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Put(
			uriTemplate: '/admin/user-groups/{id}',
			name: 'admin_user_group_update',
			processor: UserGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Delete(
			uriTemplate: '/admin/user-groups/{id}',
			name: 'admin_user_group_delete',
			processor: UserGroupProcessor::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
)]
class UserGroupResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public string $name = '',
		public string $label = '',
		public ?string $description = null,
		public bool $isSystem = false,
		public bool $isHidden = false,
		/** @var array<string,array<string,array<string>>> */
		public array $permissions = [],
		public ?int $userCount = null,
	) {
	}

	public static function fromEntity(UserGroup $group): self
	{
		$perms = $group->getPermissions();

		return new self(
			id: $group->getId(),
			name: $group->getName() ?? '',
			label: $group->getLabel() ?? '',
			description: $group->getDescription(),
			isSystem: $group->isSystem(),
			isHidden: $group->isHidden(),
			permissions: $perms,
			userCount: null,
		);
	}
}
