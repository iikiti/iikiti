<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\ApiResource\SiteGroupResource;
use iikiti\CMS\Entity\Object\SiteGroup;
use iikiti\CMS\Repository\Object\SiteGroupRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides site group data for the admin UI.
 *
 * @implements ProviderInterface<SiteGroupResource|TraversablePaginator<SiteGroupResource>>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class SiteGroupProvider implements ProviderInterface
{
	public function __construct(
		private SiteGroupRepository $siteGroupRepository,
		#[Autowire('%api/default_pagination_page_size%')]
		private int $pageSize = 25,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$groupId = $uriVariables['id'] ?? null;

		if (null !== $groupId) {
			$group = $this->siteGroupRepository->find($groupId);

			return null === $group ? null : SiteGroupResource::fromEntity($group);
		}

		$page = max(1, (int) ($context['filters']['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($context['filters']['itemsPerPage'] ?? $this->pageSize)));
		$offset = ($page - 1) * $itemsPerPage;

		$qb = $this->siteGroupRepository->createQueryBuilder('g');
		$qb->orderBy('g.id', 'ASC')
			->setMaxResults($itemsPerPage)
			->setFirstResult($offset);

		$results = $qb->getQuery()->getResult();
		$total = (int) $this->siteGroupRepository->count([]);

		$items = array_map(
			static fn (SiteGroup $group): SiteGroupResource => SiteGroupResource::fromEntity($group),
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
