<?php
namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\ObjectProperty;

/**
 * Class ObjectPropertyRepository
 *
 * @package iikiti\CMS\Repository
 */
class ObjectPropertyRepository extends ServiceEntityRepository {

	public function __construct(ManagerRegistry $registry, string $entityClass = ObjectProperty::class) {
		parent::__construct($registry, $entityClass);
	}

	public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        return parent::createQueryBuilder($alias, $indexBy);
    }

	public function createQueryBuilderWithLatest($alias, $indexBy = null): Query {
		$join = $this->createQueryBuilder('_lp')
			->select('MAX(_lp.id) AS id')
			->groupBy('_lp.object_id, _lp.name');
		return $this->getEntityManager()->createQuery(
			'SELECT lp ' .
			'FROM ' . $this->getClassName() . ' lp ' .
				'JOIN (' . $join->getQuery()->getSQL() . ') __lp ON lp.id = lp.id '
		);
	}

}
