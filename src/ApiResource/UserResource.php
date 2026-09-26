<?php

namespace iikiti\CMS\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Enum\EnumCase;
use iikiti\CMS\Manager\UserRoleManager;
use iikiti\CMS\State\Provider\UserProvider;

#[ApiResource(
	operations: [
		new GetCollection(
			uriTemplate: '/admin/users',
			name: 'admin_users_list',
			provider: UserProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
		new Get(
			uriTemplate: '/admin/users/{id}',
			name: 'admin_user_get',
			provider: UserProvider::class,
			security: 'is_granted("ROLE_ADMIN")',
		),
	],
			normalizationContext: ['attributes' => ['id', 'username', 'emails', 'groupIds']],
)]
class UserResource
{
	public function __construct(
		#[ApiProperty(identifier: true)]
		public ?int $id = null,
		public ?string $username = null,
		/** @var list<string>|null */
		public ?array $emails = null,
		/** @var array<string,string[]>|null */
		public ?array $globalRoles = null,
		/** @var array<string,string[]>|null */
		public ?array $siteRoles = null,
		/** @var list<int|string>|null */
		public ?array $groupIds = null,
		/** @var array<string,mixed>|null */
		public ?array $preferences = null,
		public ?int $creatorId = null,
		public ?\DateTimeInterface $createdDate = null,
		public ?\DateTimeInterface $updatedDate = null,
	) {
	}

	public static function fromEntity(User $user): self
	{
		/** @var array<int|string,EnumCase> $globalRoleEnums */
		$globalRoleEnums = $user->getGlobalRoles();
		$globalRoles = UserRoleManager::convertEnumsToStrings($globalRoleEnums);

		$rolesProperty = $user->getProperties()->get('roles');
		$rawRoles = $rolesProperty?->getValue() ?? null;

		/** @var array<string,mixed>|null $siteRoles */
		$siteRoles = is_array($rawRoles) ? $rawRoles : null;

		$groupIds = null;
		$rawGroups = $user->getProperties()->get('groups')?->getValue();
		if (is_array($rawGroups)) {
			/** @var list<int|string> $groupIds */
			$groupIds = array_values($rawGroups);
		}

		return new self(
			id: $user->getId(),
			username: $user->getUsername(),
			emails: $user->getEmails(),
			globalRoles: ['*' => $globalRoles],
			siteRoles: $siteRoles,
			groupIds: $groupIds,
			creatorId: $user->getCreatorId(),
			createdDate: $user->getCreatedDate(),
		);
	}
}
