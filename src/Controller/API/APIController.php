<?php

namespace iikiti\CMS\Controller\API;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * API Controller.
 */
class APIController
{
	public function __construct(private ManagerRegistry $emi)
	{
	}

	/**
	 * getObject.
	 */
	public function getObject(
		string $typeClass = DbObject::class,
		array $criteria = [],
		?array $orderBy = null,
		?int $limit = null,
		?int $offset = null
	): ?array {
		if (!class_exists($typeClass)) {
			throw new \Exception('Class type "'.$typeClass.'" does not exist.');
		} elseif (
			DbObject::class != $typeClass &&
			false == (new \ReflectionClass($typeClass))->
				isSubclassOf(DbObject::class)
		) {
			throw new \Exception('Class type "'.$typeClass.'" is not instance of '.DbObject::class);
		}
		$objRep = $this->emi->getRepository($typeClass);

		return $objRep->findBy($criteria, $orderBy, $limit, $offset);
	}

	/**
	 * @param string $typeClass Object class. Must be subclass of
	 *                          DbObject.
	 * @param string $criteria  criteria for query (where clause)
	 * @param array  $options   Associative array of options.
	 *                          See ObjectRepository for full details of options array.
	 *
	 * @return array|ArrayCollection|DbObject|false
	 *
	 * @throws \Exception
	 */
	public function getObjectsByContent(
		string $typeClass = DbObject::class,
		string $criteria = '',
		array $options = []
	): array|ArrayCollection|DbObject|bool {
		if (!class_exists($typeClass)) {
			throw new \Exception('Class type "'.$typeClass.'" does not exist.');
		} elseif (
			DbObject::class != $typeClass &&
			false == (new \ReflectionClass($typeClass))->
				isSubclassOf(DbObject::class)
		) {
			throw new \Exception('Class type "'.$typeClass.'" is not instance of '.DbObject::class);
		}
		/** @var ObjectRepository $rep */
		$rep = $this->emi->getRepository($typeClass);

		// TODO: Change findByMeta to findByContent and implement.
		return $rep->findByContent(
			criteria: $criteria,
			options: $options
		);
	}
}
