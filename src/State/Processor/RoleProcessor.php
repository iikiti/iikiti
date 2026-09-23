<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\RoleResource;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\Plugin\PluginContainerRebuilder;
use iikiti\CMS\Repository\RoleRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles updates to role custom permissions and visibility.
 *
 * Enforces protection rules:
 * - Default permissions cannot be modified by anyone (read from Role entity).
 * - System roles (is_default=true) cannot be deleted; only custom permissions
 *   can be added/modified/removed.
 *
 * @implements ProcessorInterface<RoleResource, mixed>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class RoleProcessor implements ProcessorInterface
{
	public function __construct(
		private readonly RoleRepository $roleRepository,
		private readonly EntityManagerInterface $entityManager,
		private readonly PluginContainerRebuilder $containerRebuilder,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		return match ($operation->getName()) {
			'admin_role_update' => $this->update($data, $uriVariables['id'] ?? null),
			'admin_role_delete' => $this->delete($uriVariables['id'] ?? null),
			default => $data,
		};
	}

	private function update(RoleResource $resource, int|string|null $id): RoleResource
	{
		$role = $this->roleRepository->find($id);
		if (null === $role) {
			throw new NotFoundHttpException(sprintf('Role %d not found.', $id));
		}

		$role->setCustomPermissions($resource->customPermissions);

		if (!$role->isDefault()) {
			$role->setHidden($resource->isHidden);
		}

		$this->entityManager->persist($role);
		$this->entityManager->flush();

		return RoleResource::fromEntity($role);
	}

	private function delete(int|string|null $id): null
	{
		$role = $this->roleRepository->find($id);
		if (null === $role) {
			return null;
		}

		if ($role->isDefault()) {
			throw new ConflictHttpException(sprintf('System role "%s" cannot be deleted.', $role->getName()));
		}

		// Check if any users have this role assigned
		$this->entityManager->remove($role);
		$this->entityManager->flush();

		// A custom role was removed — schedule a container rebuild so the
		// compiled role hierarchy no longer includes it. Rebuilds are flushed
		// once at the end of the request to avoid multiple cache clears.
		$this->containerRebuilder->schedule();

		return null;
	}
}
