<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SearchFilter;

/**
 * @extends ServiceEntityRepository<SearchFilter>
 */
class SearchFilterRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SearchFilter::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	/**
	 * @return list<SearchFilter>
	 */
	public function findByIndex(int|string $indexId): array
	{
		/** @var list<SearchFilter> $result */
		$result = $this->findBy(['searchIndex' => $indexId], ['name' => 'ASC']);

		return $result;
	}

	/**
	 * @return list<SearchFilter>
	 */
	public function findEnabledByIndex(int|string $indexId): array
	{
		/** @var list<SearchFilter> $result */
		$result = $this->findBy(
			['searchIndex' => $indexId, 'isEnabled' => true],
			['name' => 'ASC']
		);

		return $result;
	}
}
