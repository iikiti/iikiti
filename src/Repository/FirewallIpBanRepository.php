<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\FirewallIpBan;
use iikiti\CMS\Security\Firewall\Ip\IpCidrNormalizer;

/**
 * Repository for operational firewall IP bans.
 *
 * @extends ServiceEntityRepository<FirewallIpBan>
 */
class FirewallIpBanRepository extends ServiceEntityRepository
{
	private const GLOBAL_SITE_ID = 0;
	private const MAX_PAGE_LIMIT = 200;

	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, FirewallIpBan::class);
	}

	/**
	 * Returns unexpired bans for the global scope plus the given site, when supplied.
	 *
	 * @return list<FirewallIpBan>
	 */
	public function findActiveForSiteAndGlobal(int|string|null $siteId, ?\DateTimeImmutable $now = null): array
	{
		$now ??= new \DateTimeImmutable();
		$scopes = [self::GLOBAL_SITE_ID];
		if (null !== $siteId && (string) $siteId !== (string) self::GLOBAL_SITE_ID) {
			$scopes[] = $siteId;
		}

		$qb = $this->createQueryBuilder('b')
			->andWhere('b.siteId IN (:scopes)')
			->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
			->setParameter('scopes', array_map('strval', $scopes))
			->setParameter('now', $now)
			->orderBy('b.id', 'ASC');

		return $qb->getQuery()->getResult();
	}

	/** Returns the single unexpired ban for an exact CIDR in one site scope, if any. */
	public function findActiveOneByCidr(int|string $siteId, string $cidr, ?\DateTimeImmutable $now = null): ?FirewallIpBan
	{
		$now ??= new \DateTimeImmutable();
		$normalized = IpCidrNormalizer::normalize($cidr)['cidr'];

		return $this->createQueryBuilder('b')
			->andWhere('b.siteId = :siteId')
			->andWhere('b.cidr = :cidr')
			->andWhere('b.expiresAt IS NULL OR b.expiresAt > :now')
			->setParameter('siteId', (string) $siteId)
			->setParameter('cidr', $normalized)
			->setParameter('now', $now)
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();
	}

	/** Deletes bans whose expiry has passed and returns the number removed. */
	public function deleteExpired(?\DateTimeImmutable $now = null): int
	{
		$now ??= new \DateTimeImmutable();

		return (int) $this->createQueryBuilder('b')
			->delete()
			->andWhere('b.expiresAt IS NOT NULL')
			->andWhere('b.expiresAt <= :now')
			->setParameter('now', $now)
			->getQuery()
			->execute();
	}

	/**
	 * @return list<FirewallIpBan>
	 */
	public function findPaginated(?int $siteId, int $page, int $limit): array
	{
		$safeLimit = min(max(1, $limit), self::MAX_PAGE_LIMIT);
		$offset = (max(1, $page) - 1) * $safeLimit;

		$qb = $this->createQueryBuilder('b')
			->orderBy('b.id', 'DESC')
			->setFirstResult($offset)
			->setMaxResults($safeLimit);
		if (null !== $siteId) {
			$qb->andWhere('b.siteId = :siteId')->setParameter('siteId', (string) $siteId);
		}

		return $qb->getQuery()->getResult();
	}
}
