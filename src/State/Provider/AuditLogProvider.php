<?php

namespace iikiti\CMS\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use iikiti\CMS\ApiResource\AuditLogResource;
use iikiti\CMS\Entity\AuditLogEntry;
use iikiti\CMS\Repository\AuditLogEntryRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides audit log entries for the admin UI.
 *
 * @implements ProviderInterface<AuditLogResource|TraversablePaginator<AuditLogResource>>
 */
#[AutoconfigureTag('api_platform.state_provider')]
readonly class AuditLogProvider implements ProviderInterface
{
	public function __construct(
		private AuditLogEntryRepository $auditLogRepository,
		#[Autowire('%api/default_pagination_page_size%')]
		private int $pageSize = 25,
	) {
	}

	#[\Override]
	public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
	{
		$filters = $context['filters'] ?? [];

		$page = max(1, (int) ($filters['page'] ?? 1));
		$itemsPerPage = max(1, min(100, (int) ($filters['itemsPerPage'] ?? $this->pageSize)));
		$offset = ($page - 1) * $itemsPerPage;

		// Only top-level events are listed; their sub-events are attached per row.
		$qb = $this->auditLogRepository->createQueryBuilder('l')
			->andWhere('l.parent IS NULL')
			->orderBy('l.createdAt', 'DESC')
			->setMaxResults($itemsPerPage)
			->setFirstResult($offset);

		if (isset($filters['actorType'])) {
			$qb->andWhere('l.actorType = :actorType')
				->setParameter('actorType', $filters['actorType']);
		}

		if (isset($filters['action'])) {
			$qb->andWhere('l.action = :action')
				->setParameter('action', $filters['action']);
		}

		if (isset($filters['objectType'])) {
			$qb->andWhere('l.objectType = :objectType')
				->setParameter('objectType', $filters['objectType']);
		}

		if (isset($filters['actorId'])) {
			$qb->andWhere('l.userId = :userId')
				->setParameter('userId', $filters['actorId']);
		}

		$results = $qb->getQuery()->getResult();
		$total = (int) $this->auditLogRepository->count(['parent' => null]);

		$items = array_map(
			static fn (AuditLogEntry $entry): AuditLogResource => AuditLogResource::fromEntity($entry, true),
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
