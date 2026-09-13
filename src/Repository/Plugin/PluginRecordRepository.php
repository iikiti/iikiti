<?php

namespace iikiti\CMS\Repository\Plugin;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Plugin\PluginRecord;

/**
 * Repository for plugin install/version records.
 *
 * @extends ServiceEntityRepository<PluginRecord>
 */
class PluginRecordRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = PluginRecord::class,
	) {
		parent::__construct($registry, $entityClass);
	}
}
