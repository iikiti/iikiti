<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\UserGroupResource;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Repository\Object\UserGroupRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Handles CRUD operations on user groups for the admin UI.
 *
 * Enforces system-locked protection: system groups cannot be deleted.
 *
 * @implements ProcessorInterface<UserGroupResource|int, mixed>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class UserGroupProcessor implements ProcessorInterface
{
	public function __construct(
		private UserGroupRepository $userGroupRepository,
		private EntityManagerInterface $entityManager,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		return match ($operation->getName()) {
			'admin_user_group_create' => $this->create($data),
			'admin_user_group_update' => $this->update($data, $uriVariables['id'] ?? null),
			'admin_user_group_delete' => $this->delete($uriVariables['id'] ?? null),
			default => $data,
		};
	}

	private function create(UserGroupResource $resource): UserGroupResource
	{
		$group = new UserGroup();
		$group->setName($resource->name);
		$group->setLabel($resource->label);
		$group->setDescription($resource->description);
		$group->setSystem(false);
		$group->setPermissions($resource->permissions);

		$this->entityManager->persist($group);
		$this->entityManager->flush();

		return UserGroupResource::fromEntity($group);
	}

	private function update(UserGroupResource $resource, int|string|null $id): UserGroupResource
	{
		$group = $this->userGroupRepository->find($id);
		if (null === $group) {
			throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(sprintf('User group %d not found.', $id));
		}

		$group->setName($resource->name);
		$group->setLabel($resource->label);
		$group->setDescription($resource->description);
		if (!$group->isSystem()) {
			$group->setHidden($resource->isHidden);
		}
		if (!$group->isSystem()) {
			$group->setPermissions($resource->permissions);
		}

		$this->entityManager->persist($group);
		$this->entityManager->flush();

		return UserGroupResource::fromEntity($group);
	}

	private function delete(int|string|null $id): null
	{
		$group = $this->userGroupRepository->find($id);
		if (null === $group) {
			return null;
		}

		if ($group->isSystem()) {
			throw new ConflictHttpException(sprintf('System user group "%s" cannot be deleted.', $group->getName()));
		}

		$this->entityManager->remove($group);
		$this->entityManager->flush();

		return null;
	}
}
