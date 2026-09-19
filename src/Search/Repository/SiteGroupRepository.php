<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SiteGroup;

/**
 * @extends ServiceEntityRepository<SiteGroup>
 */
class SiteGroupRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SiteGroup::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	public function findByName(string $name): ?SiteGroup
	{
		/** @var SiteGroup|null $result */
		$result = $this->findOneBy(['name' => $name]);

		return $result;
	}
}
