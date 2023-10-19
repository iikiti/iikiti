<?php
namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Query\ResultSetMapping;
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

	public function getLatestQuery(): NativeQuery {
		$rsm = new ResultSetMapping();
		return $this->getEntityManager()->createNativeQuery(
			'SELECT MAX(lp.id) as id ' .
			'FROM ' . $this->getEntityName() . ' lp ' .
			'GROUP BY lp.object_id, lp.name ',
			$rsm
		);

	}

}
