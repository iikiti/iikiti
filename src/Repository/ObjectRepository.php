<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Interfaces\SearchableRepositoryInterface;
use iikiti\CMS\Registry\SiteRegistry;

/**
 * @template T of object
 *
 * @template-extends ServiceEntityRepository<T>
 */
abstract class ObjectRepository extends ServiceEntityRepository implements SearchableRepositoryInterface
{
	public function __construct(
		ManagerRegistry $registry,
		private SiteRegistry $siteRegistry,
		string $entityClass = DbObject::class
	) {
		parent::__construct($registry, $entityClass);
	}

	public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
	{
		return $this->__filterBySite(parent::createQueryBuilder($alias, $indexBy));
	}

	public function find($id, $lockMode = null, $lockVersion = null): ?DbObject
	{
		$entity = $this->findOneBy([$this->getClassMetadata()->getIdentifier()[0] => $id]);
		if (null !== $entity && null !== $lockMode && LockMode::NONE !== $lockMode) {
			$this->getEntityManager()->lock($entity, $lockMode, $lockVersion);
		}

		return $entity;
	}

	public function findAll(): array
	{
		return parent::findBy([]);
	}

	public function findBy(
		array $criteria,
		?array $orderBy = null,
		$limit = null,
		$offset = null
	): array {
		return parent::findBy($this->__filterBySite($criteria), $orderBy, $limit, $offset);
	}

	public function findOneBy(array $criteria, ?array $orderBy = null): ?DbObject
	{
		return parent::findOneBy($this->__filterBySite($criteria), $orderBy);
	}

	protected function __filterBySite(
		array|QueryBuilder $criteriaOrBuilder = []
	): array|QueryBuilder {
		$siteId = $this->getClassMetadata()->getReflectionClass()->getConstant('SITE_SPECIFIC') ?
			($this->siteRegistry::getCurrent()->getId()) :
			0;
		if ($criteriaOrBuilder instanceof QueryBuilder) {
			return $criteriaOrBuilder->setParameter('siteId', $siteId)->
				andWhere($criteriaOrBuilder->getAllAliases()[0].'.site_id = :siteId');
		}

		return array_merge(['site_id' => $siteId], $criteriaOrBuilder);
	}

	/**
	 * @param string|array<string> $name
	 *
	 * @return array<DbObject>
	 */
	public function findByProperty(string|array $name, string|int|float|array $value): array
	{
		return $this->__findByProperty($name, $value)->getQuery()->getResult();
	}

	/**
	 * @param string|array<string> $name
	 *
	 * @return ?DbObject
	 */
	public function findOneByProperty(string|array $name, string|int|float|array $value): ?DbObject
	{
		return $this->__findByProperty($name, $value)->getQuery()->getOneOrNullResult();
	}

	/**
	 * @param string|array<string> $name
	 */
	private function __findByProperty(
		string|array $name,
		string|int|float|array $value
	): QueryBuilder {
		$qb = $this->createQueryBuilder('o');
		if (is_array($name)) {
			if (!is_array($value)) {
				throw new \InvalidArgumentException('$value is expected to be an array. '.gettype($value).' provided.');
			}
			if (count($name) < 1) {
				throw new \InvalidArgumentException('Must be at least 1 criteria.');
			} elseif (count($name) != count($value)) {
				throw new \InvalidArgumentException('Size of $name must match size of $comparison');
			}
			foreach ($name as $n) {
				/** @var string|int|float $comp */
				$nextValue = next($comparison);
				$qb->
					join(
						'o.properties',
						'p',
						Join::WITH,
						'p.name = :name AND '.
							'JSON_CONTAINS(p.value, :value) = 1'
					)->
					setParameter(':name', $n)->
					setParameter(':value', json_encode($nextValue));
			}
		} else {
			$qb->
				join(
					'o.properties',
					'p',
					Join::WITH,
					'p.name = :name AND '.
						'JSON_CONTAINS(p.value, :value) = 1'
				)->
				setParameter(':name', $name)->
				setParameter(':value', json_encode($value));
		}

		return $qb;
	}

	public function search(string $query): mixed
	{
		// TODO: Implement search
	}

	public function __call($method, $arguments): mixed
	{
		// TODO: Add site filter
		return parent::__call($method, $arguments);
	}
}
