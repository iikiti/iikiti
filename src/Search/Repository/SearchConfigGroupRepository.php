<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SearchConfigGroup;

/**
 * @extends ServiceEntityRepository<SearchConfigGroup>
 */
class SearchConfigGroupRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SearchConfigGroup::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	public function findByName(string $name): ?SearchConfigGroup
	{
		/** @var SearchConfigGroup|null $result */
		$result = $this->findOneBy(['name' => $name]);

		return $result;
	}
}
