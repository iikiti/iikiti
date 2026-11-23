<?php

declare(strict_types=1);

namespace iikiti\Extension\ConsentManagement\Repository;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;
use iikiti\Extension\ConsentManagement\Entity\ConsentRecord;

/**
 * Repository for consent records.
 *
 * Site-scoped records use the current site id; global records use site id 0.
 * Consent records are located by `privacy.consent.user_id` (logged-in users)
 * or `privacy.consent.session_id` (anonymous sessions), both scoped to a site.
 *
 * @extends ObjectRepository<ConsentRecord>
 */
class ConsentRecordRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, ConsentRecord::class);
	}

	/**
	 * Find the consent record for a logged-in user on a site.
	 */
	public function findForUser(Site $site, int|string $userId): ?ConsentRecord
	{
		$qb = $this->createQueryBuilder('c', null, ['filterBySite' => false]);
		$qb->andWhere('c.site = :siteId')
			->setParameter('siteId', (int) ($site->getId() ?? 0))
			->join('c.properties', 'pu', Join::WITH, 'pu.name = :userIdProp')
			->setParameter('userIdProp', ConsentRecord::PROP_USER_ID)
			->andWhere('JSONB_CONTAINS(pu.value, :userId) = true')
			->setParameter('userId', json_encode($userId))
			->orderBy('c.id', 'DESC')
			->setMaxResults(1);

		return $qb->getQuery()->getOneOrNullResult();
	}

	/**
	 * Find the consent record for an anonymous session on a site.
	 */
	public function findForSession(Site $site, string $sessionId): ?ConsentRecord
	{
		$qb = $this->createQueryBuilder('c', null, ['filterBySite' => false]);
		$qb->andWhere('c.site = :siteId')
			->setParameter('siteId', (int) ($site->getId() ?? 0))
			->join('c.properties', 'ps', Join::WITH, 'ps.name = :sessionIdProp')
			->setParameter('sessionIdProp', ConsentRecord::PROP_SESSION_ID)
			->andWhere('JSONB_CONTAINS(ps.value, :sessionId) = true')
			->setParameter('sessionId', json_encode($sessionId))
			->orderBy('c.id', 'DESC')
			->setMaxResults(1);

		return $qb->getQuery()->getOneOrNullResult();
	}

	/**
	 * @return list<ConsentRecord>
	 */
	public function findAllForSite(Site $site, int $page = 1, int $itemsPerPage = 25): array
	{
		$qb = $this->createQueryBuilder('c', null, ['filterBySite' => false])
			->andWhere('c.site = :siteId')
			->setParameter('siteId', (int) ($site->getId() ?? 0))
			->orderBy('c.created_date', 'DESC')
			->setMaxResults($itemsPerPage)
			->setFirstResult(($page - 1) * $itemsPerPage);

		return $qb->getQuery()->getResult();
	}

	public function countForSite(Site $site): int
	{
		$query = $this->createQueryBuilder('c', null, ['filterBySite' => false])
			->select('COUNT(c.id)')
			->andWhere('c.site = :siteId')
			->setParameter('siteId', (int) ($site->getId() ?? 0))
			->getQuery();

		return (int) $query->getSingleScalarResult();
	}
}
