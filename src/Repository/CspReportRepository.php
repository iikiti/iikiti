<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\CspReport;

/**
 * Repository for CSP violation reports.
 *
 * @extends ServiceEntityRepository<CspReport>
 */
class CspReportRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, CspReport::class);
	}

	/**
	 * @return array<CspReport>
	 */
	public function findRecent(int $limit = 50): array
	{
		return $this->findBy([], ['createdAt' => 'DESC'], $limit);
	}

	/**
	 * @return array<CspReport>
	 */
	public function findBySite(int|string $siteId, int $limit = 50): array
	{
		return $this->findBy(['siteId' => $siteId], ['createdAt' => 'DESC'], $limit);
	}
}
