<?php

namespace iikiti\CMS\Search\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Search\Entity\SearchAnalyzerLayer;

/**
 * @extends ServiceEntityRepository<SearchAnalyzerLayer>
 */
class SearchAnalyzerLayerRepository extends ServiceEntityRepository
{
	public function __construct(
		ManagerRegistry $registry,
		string $entityClass = SearchAnalyzerLayer::class,
	) {
		parent::__construct($registry, $entityClass);
	}

	/**
	 * Find all layers belonging to an analyzer chain (by analyzer name),
	 * optionally scoped to an index or a field.
	 *
	 * @return list<SearchAnalyzerLayer>
	 */
	public function findByAnalyzer(
		string $analyzerName,
		?int $indexId = null,
		?int $fieldId = null,
	): array {
		$qb = $this->createQueryBuilder('l')->
			where('l.name = :name')->
			setParameter('name', $analyzerName)->
			orderBy('l.position', 'ASC');

		if (null !== $indexId) {
			$qb->andWhere('l.searchIndexId = :indexId')->
				setParameter('indexId', $indexId);
		}

		if (null !== $fieldId) {
			$qb->andWhere('l.fieldId = :fieldId')->
				setParameter('fieldId', $fieldId);
		}

		/** @var list<SearchAnalyzerLayer> $result */
		$result = $qb->getQuery()->getResult();

		return $result;
	}
}
