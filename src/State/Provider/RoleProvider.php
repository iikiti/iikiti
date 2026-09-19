<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use iikiti\CMS\ApiResource\RoleResource;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\Repository\RoleRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Provides role definitions for the admin UI.
 *
 * @implements ProviderInterface<RoleResource>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class RoleProvider implements ProviderInterface
{
	public function __construct(
		private RoleRepository $roleRepository,
	) {
	}

	/**
	 * @return RoleResource|list<RoleResource>
	 */
	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$roleId = $uriVariables['id'] ?? null;

		if (null !== $roleId) {
			$role = $this->roleRepository->find($roleId);

			return null === $role ? null : RoleResource::fromEntity($role);
		}

		/** @var list<Role> $roles */
		$roles = $this->roleRepository->findBy([], ['name' => 'ASC']);

		return array_map(
			static fn (Role $role): RoleResource => RoleResource::fromEntity($role),
			$roles,
		);
	}
}
