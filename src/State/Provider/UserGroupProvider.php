<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\ApiResource\UserGroupResource;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Repository\Object\UserGroupRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides user group data for the admin UI.
 *
 * @implements ProviderInterface<UserGroupResource|TraversablePaginator<UserGroupResource>>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class UserGroupProvider implements ProviderInterface
{
	public function __construct(
		private UserGroupRepository $userGroupRepository,
		#[Autowire('%api/default_pagination_page_size%')]
		private int $pageSize = 25,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$groupId = $uriVariables['id'] ?? null;

		if (null !== $groupId) {
			$group = $this->userGroupRepository->find($groupId);

			return null === $group ? null : UserGroupResource::fromEntity($group);
		}

		$page = max(1, (int) ($context['filters']['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($context['filters']['itemsPerPage'] ?? $this->pageSize)));
		$offset = ($page - 1) * $itemsPerPage;

		$qb = $this->userGroupRepository->createQueryBuilder('g');
		$qb->orderBy('g.id', 'ASC')
			->setMaxResults($itemsPerPage)
			->setFirstResult($offset);

		$results = $qb->getQuery()->getResult();
		$total = (int) $this->userGroupRepository->count([]);

		$items = array_map(
			static fn (UserGroup $group): UserGroupResource => UserGroupResource::fromEntity($group),
			$results,
		);

		return new TraversablePaginator(
			new \ArrayIterator($items),
			(float) $page,
			(float) $itemsPerPage,
			(float) $total,
		);
	}
}
