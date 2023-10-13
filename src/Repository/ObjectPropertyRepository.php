<?php
namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

}
