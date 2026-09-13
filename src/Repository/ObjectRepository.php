<?php

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Interfaces\SearchableRepositoryInterface;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Trait\RepositoryOptionCheckTrait;

/**
 * Repository for database objects.
 * This is a high-level abstract class that individual object repositories
 * should extend. Provides convenience methods and query management.
 *
 * @template T of object
 *
 * @template-extends ServiceEntityRepository<T>
 */
abstract class ObjectRepository extends ServiceEntityRepository implements SearchableRepositoryInterface
{
	use RepositoryOptionCheckTrait;

	public function __construct(
		ManagerRegistry $registry,
		private SiteRegistry $siteRegistry,
		string $entityClass = DbObject::class
	) {
		parent::__construct($registry, $entityClass);
	}

	public function getCreator(DbObject $object): ?User {
		return $this->getEntityManager()->getRepository(User::class)->find($object->getCreatorId());
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function createQueryBuilder($alias, $indexBy = null, array $options = []): QueryBuilder
	{
		$filterBySite = (bool) $this->_checkOption(
			'filterBySite',
			$options,
			\Closure::fromCallable([$this, '_typeCheck_bool'])
		);

		return $filterBySite ?
			$this->__filterBySite(parent::createQueryBuilder($alias, $indexBy)) :
			parent::createQueryBuilder($alias, $indexBy);
	}

	/**
	 * @param string|int $id
	 * @param LockMode|int|null $lockMode
	 * @param array<string,mixed> $options
	 *
	 * @return T|null
	 */
	public function find($id, $lockMode = null, $lockVersion = null, array $options = []): ?object
	{
		$entity = $this->findOneBy([$this->getClassMetadata()->getIdentifier()[0] => $id]);
		if (null !== $entity && null !== $lockMode) {
			if ($lockMode !== LockMode::NONE && $lockMode !== 0) {
				$this->getEntityManager()->lock($entity, $lockMode, $lockVersion);
			}
		}

		return $entity;
	}

	/**
	 * @return array<T>
	 * @param array<string,mixed> $options
	 */
	public function findAll(array $options = []): array
	{
		return $this->findBy([], null, null, null, $options);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<T>
	 */
	public function findBy(
		array $criteria,
		?array $orderBy = null,
		$limit = null,
		$offset = null,
		array $options = []
	): array {
		$filterBySite = (bool) $this->_checkOption(
			'filterBySite',
			$options,
			\Closure::fromCallable([$this, '_typeCheck_bool'])
		);

		return parent::findBy(
			$filterBySite ? $this->__filterBySite($criteria) : $criteria,
			$orderBy,
			$limit,
			$offset
		);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return T|null
	 */
	public function findOneBy(
		array $criteria,
		?array $orderBy = null,
		array $options = []
	): ?object {
		$filterBySite = (bool) $this->_checkOption(
			'filterBySite',
			$options,
			\Closure::fromCallable([$this, '_typeCheck_bool'])
		);

		return parent::findOneBy(
			$filterBySite ? $this->__filterBySite($criteria) : $criteria,
			$orderBy
		);
	}

	/**
	 * @param array<string,mixed>|QueryBuilder $criteriaOrBuilder
	 *
	 * @return array<string,int|string|null>|QueryBuilder
	 */
	protected function __filterBySite(
		array|QueryBuilder $criteriaOrBuilder = []
	): array|QueryBuilder {
		$siteId = $this->getClassMetadata()->getReflectionClass()->getConstant('SITE_SPECIFIC') ?
			($this->siteRegistry::getCurrent()->getId()) :
			0;
		if ($criteriaOrBuilder instanceof QueryBuilder) {
			return $criteriaOrBuilder->setParameter('siteId', $siteId)->
				andWhere($criteriaOrBuilder->getAllAliases()[0].'.site = :siteId');
		}

		return array_merge(['site' => $siteId], $criteriaOrBuilder);
	}

	/**
	 * @param string|array<string> $name
	 * @param string|int|float|array<array-key,mixed> $value
	 * @param array<string,mixed> $options
	 *
	 * @return array<T>
	 */
	public function findByProperty(
		string|array $name,
		string|int|float|array $value,
		array $options = []
	): array {
		return $this->__findByProperty($name, $value, $options)->getQuery()->getResult();
	}

		/**
	 * @param string|array<string> $name
	 * @param string|int|float|array<array-key,mixed> $value
	 * @param array<string,mixed> $options
	 *
	 * @return T|null
	 */
	public function findOneByProperty(
		string|array $name,
		string|int|float|array $value,
		array $options = []
	): ?object {
		return $this->__findByProperty($name, $value, $options)->getQuery()->getOneOrNullResult();
	}

	/**
	 * @param string|array<string> $name
	 * @param string|int|float|array<array-key,mixed> $value
	 * @param array<string,mixed> $options
	 */
	private function __findByProperty(
		string|array $name,
		string|int|float|array $value,
		array $options = []
	): QueryBuilder {
		$indexBy = $this->_checkOption(
			'indexBy',
			$options,
			\Closure::fromCallable([$this, '_typeCheck_stringOrArray'])
		);
		$qb = $this->createQueryBuilder('o', $indexBy, $options);
		if (is_array($name)) {
			if (!is_array($value)) {
				throw new \InvalidArgumentException('$value is expected to be an array. '.gettype($value).' provided.');
			}
			if (count($name) < 1) {
				throw new \InvalidArgumentException('Must be at least 1 criteria.');
			} elseif (count($name) != count($value)) {
				throw new \InvalidArgumentException('Size of $name must match size of $comparison');
			}
			foreach ($name as $idx => $n) {
				$nextValue = $value[$idx];
				$qb->
					join(
						'o.properties',
						'p',
						Join::WITH,
						'p.name = :name AND '.
 							'JSONB_CONTAINS(p.value, :value) = true'
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
						'JSONB_CONTAINS(p.value, :value) = true'
				)->
				setParameter(':name', $name)->
				setParameter(':value', json_encode($value));
		}

		return $qb;
	}

	public function search(string $query): mixed
	{
		return [];
	}

	public function getDiscriminatorKey(?string $classname = null): ?string {
		if ($classname !== null) {
			/** @var class-string<object> $classname */
			$cmd = $this->getEntityManager()->getClassMetadata($classname);
		} else {
			/** @var ClassMetadata<object> $cmd */
			$cmd = $this->getClassMetadata();
		}

		if (!$cmd->isRootEntity()) {
			/** @var class-string<object> $rootName */
			$rootName = $cmd->rootEntityName;
			/** @var ClassMetadata<object> $rcmd */
			$rcmd = $this->getEntityManager()->getClassMetadata($rootName);
		} else {
			$rcmd = $cmd;
		}

		return array_find_key($rcmd->discriminatorMap, fn($name) => $name == $cmd->getName());
	}

	/**
	 * @param string $name
	 * @param array<int|string> $arguments
	 *
	 * @return T|null|array<T>
	 */
	public function __call(string $name, array $arguments): mixed
	{
		// TODO: Add site filter
		return parent::__call($name, $arguments);
	}
}
