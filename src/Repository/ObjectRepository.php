<?php
namespace iikiti\CMS\Repository;

use iikiti\CMS\Entity\DbObject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class ObjectRepository
 *
 * @package iikiti\CMS\Repository
 */
abstract class ObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, string $entityClass = DbObject::class)
    {
        parent::__construct($registry, $entityClass);
    }

}
