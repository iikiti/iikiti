<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SearchIndexField;

/**
 * @extends ServiceEntityRepository<SearchIndexField>
 */
class SearchIndexFieldRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SearchIndexField::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	/**
	 * @return list<SearchIndexField>
	 */
	public function findByIndex(int|string $indexId): array
	{
		/** @var list<SearchIndexField> $result */
		$result = $this->findBy(['searchIndex' => $indexId], ['position' => 'ASC']);

		return $result;
	}
}
