<?php

namespace iikiti\CMS\Controller\API;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;

/**
 * Class APIController
 *
 * @package iikiti\CMSBundle\Controller\API
 */
class APIController {

    /**
     * @var \Doctrine\Persistence\ManagerRegistry
     */
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine) {
        $this->doctrine = $doctrine;
    }

    public function getObject(
        string $typeClass = DbObject::class,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): ?array {
        if(!class_exists($typeClass)) {
            throw new \Exception('Class type "' . $typeClass . '" does not exist.');
        } elseif(
            $typeClass != DbObject::class &&
            (new \ReflectionClass($typeClass))
                ->isSubclassOf(DbObject::class) == false
        ) {
            throw new \Exception(
                'Class type "' . $typeClass . '" is not instance of ' .
                DbObject::class
            );
        }
        $objRep = $this->doctrine->getRepository($typeClass);
        return $objRep->findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * @param string           $typeClass   Object class. Must be subclass of
     *                                      DbObject.
     * @param string|\stdClass $criteria    Criteria for query (where clause).
     * @param array            $options     Associative array of options.
     *          See ObjectRepository for full details of options array.
     *
     * @return array|\Doctrine\Common\Collections\ArrayCollection|\iikiti\CMS\Entity\DbObject|false
     * @throws \Exception
     */
    public function getObjectsByMeta(
        string $typeClass = DbObject::class,
        string|\stdClass $criteria = '',
        array $options = []
    ): array|ArrayCollection|DbObject|bool {
        if(!class_exists($typeClass)) {
            throw new \Exception(
                'Class type "' . $typeClass . '" does not exist.'
            );
        } elseif(
            $typeClass != DbObject::class &&
            (new \ReflectionClass($typeClass))
                ->isSubclassOf(DbObject::class) == false
        ) {
            throw new \Exception(
                'Class type "' . $typeClass . '" is not instance of ' .
                DbObject::class
            );
        }
        return $this->doctrine->getRepository($typeClass)->findByMeta(
            criteria: $criteria,
            options: $options
        );
    }

}
