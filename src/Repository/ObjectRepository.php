<?php
namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Interfaces\SearchableRepositoryInterface;

/**
 * Class ObjectRepository
 *
 * @package iikiti\CMS\Repository
 */
abstract class ObjectRepository extends ServiceEntityRepository implements
    SearchableRepositoryInterface {

    public function __construct(
        ManagerRegistry $registry,
        string $entityClass = DbObject::class
    ) {
        parent::__construct($registry, $entityClass);
    }

    public function findByContent(string $criteria, array $options) {
        $qb = $this->createQueryBuilder('o');
        $qb->where($criteria);
        if(!empty($options['orderBy'])) {
            foreach($options['orderBy'] as $field => $order) {
                $qb->addOrderBy($field, $order);
            }
        }
        if(!empty($options['limit'])) {
            $qb->setMaxResults($options['limit']);
        }
        $qb->setParameters($options['parameters']);
        if($options['singleResult']) {
            return $qb->getQuery()->getOneOrNullResult() ?? false;
        }
        return $qb->getQuery()->getResult();
    }

    public function search(string $query): mixed {

    }

}
