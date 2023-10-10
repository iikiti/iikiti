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

	public function find($id)
    {
        return $this->findOneBy([ 'id' => $id ]);
    }

    public function findAll()
    {
        return parent::findBy([]);
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
    {
        return parent::findBy($criteria, $orderBy, $limit, $offset);
    }

    public function findOneBy(array $criteria, ?array $orderBy = null)
    {
        return parent::findOneBy($criteria, $orderBy);
    }

    public function search(string $query): mixed {

    }

    public function __call($method, $arguments) {
		return parent::__call($method, $arguments);
	}

}
