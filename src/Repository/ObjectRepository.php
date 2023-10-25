<?php
namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Interfaces\SearchableRepositoryInterface;
use iikiti\CMS\Registry\SiteRegistry;

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

	public function findByContent(string $criteria, array $options): array {
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

	public function createQueryBuilder($alias, $indexBy = null): QueryBuilder {
        return $this->__filterBySite(parent::createQueryBuilder($alias, $indexBy));
    }

	public function find($id, $lockMode = null, $lockVersion = null): ?DbObject {
		$entity = $this->findOneBy([ $this->getClassMetadata()->getIdentifier()[0] => $id ]);
		if($entity !== null && $lockMode !== null && $lockMode !== LockMode::NONE) {
			$this->getEntityManager()->lock($entity, $lockMode, $lockVersion);
		}
		return $entity;
	}

	public function findAll(): array {
		return parent::findBy([]);
	}

	public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array {
		return parent::findBy($this->__filterBySite($criteria), $orderBy, $limit, $offset);
	}

	public function findOneBy(array $criteria, ?array $orderBy = null): ?DbObject {
		return parent::findOneBy($this->__filterBySite($criteria), $orderBy);
	}

	protected function __filterBySite(array|QueryBuilder $criteriaOrBuilder = []): array|QueryBuilder {
		$siteId = $this->getClassMetadata()->getReflectionClass()->getConstant('SITE_SPECIFIC') ?
			SiteRegistry::getCurrentSite()->getId() :
			0;
		if($criteriaOrBuilder instanceof QueryBuilder) {
			return $criteriaOrBuilder->setParameter('siteId', $siteId)
				->andWhere($criteriaOrBuilder->getAllAliases()[0] . '.site_id = :siteId');
		}
		return array_merge(['site_id' => $siteId], $criteriaOrBuilder);
	}

	public function findByProperty(string $name, Comparison $comparison): array {
		return $this->getEntityManager()
			->getRepository(ObjectRepository::class)
			->findBy([
				'name' => $name,
				'value' => $comparison
			]);
	}

	public function search(string $query): mixed {

	}

	public function __call($method, $arguments): mixed {
		// TODO: Add site filter
		return parent::__call($method, $arguments);
	}

}
