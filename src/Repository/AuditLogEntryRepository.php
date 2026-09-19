<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AuditLogEntry;

/**
 * Repository for audit log entries.
 *
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
	) {
		parent::__construct($registry, AuditLogEntry::class);
	}

	/**
	 * @return array<AuditLogEntry>
	 */
	public function findRecent(int $limit = 50): array
	{
		return $this->findBy([], ['createdAt' => 'DESC'], $limit);
	}

	/**
	 * @param array<string,mixed> $criteria
	 * @param array<string,string>|null $orderBy
	 *
	 * @return array<AuditLogEntry>
	 */
	public function findByAdvanced(
		array $criteria,
		?array $orderBy = null,
		?int $limit = null,
		?int $offset = null,
	): array {
		return $this->findBy($criteria, $orderBy, $limit, $offset);
	}
}
