<?php

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\SiteGroupResource;
use iikiti\CMS\Entity\Object\SiteGroup;
use iikiti\CMS\Repository\Object\SiteGroupRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles CRUD operations on site groups for the admin UI.
 *
 * Enforces system-locked protection: system site groups cannot be deleted.
 *
 * @implements ProcessorInterface<SiteGroupResource|int, mixed>
 */
#[AutoconfigureTag('api_platform.state_processor')]
readonly class SiteGroupProcessor implements ProcessorInterface
{
	public function __construct(
		private SiteGroupRepository $siteGroupRepository,
		private EntityManagerInterface $entityManager,
	) {
	}

	#[\Override]
	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
	{
		return match ($operation->getName()) {
			'admin_site_group_create' => $this->create($data),
			'admin_site_group_update' => $this->update($data, $uriVariables['id'] ?? null),
			'admin_site_group_delete' => $this->delete($uriVariables['id'] ?? null),
			default => $data,
		};
	}

	private function create(SiteGroupResource $resource): SiteGroupResource
	{
		$group = new SiteGroup();
		$group->setName($resource->name);
		$group->setLabel($resource->label);
		$group->setDescription($resource->description);
		$group->setSystem(false);
		$group->setSiteIds($resource->siteIds);

		$this->entityManager->persist($group);
		$this->entityManager->flush();

		return SiteGroupResource::fromEntity($group);
	}

	private function update(SiteGroupResource $resource, int|string|null $id): SiteGroupResource
	{
		$group = $this->siteGroupRepository->find($id);
		if (null === $group) {
			throw new NotFoundHttpException(sprintf('Site group %d not found.', $id));
		}

		$group->setName($resource->name);
		$group->setLabel($resource->label);
		$group->setDescription($resource->description);
		if (!$group->isSystem()) {
			$group->setSiteIds($resource->siteIds);
		}

		$this->entityManager->persist($group);
		$this->entityManager->flush();

		return SiteGroupResource::fromEntity($group);
	}

	private function delete(int|string|null $id): null
	{
		$group = $this->siteGroupRepository->find($id);
		if (null === $group) {
			return null;
		}

		if ($group->isSystem()) {
			throw new ConflictHttpException(sprintf('System site group "%s" cannot be deleted.', $group->getName()));
		}

		$this->entityManager->remove($group);
		$this->entityManager->flush();

		return null;
	}
}
