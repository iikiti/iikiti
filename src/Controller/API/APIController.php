<?php

namespace iikiti\CMS\Controller\API;

use Doctrine\Common\Collections\ArrayCollection;
use iikiti\CMS\Entity\DbObject;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class APIController
 *
 * @package iikiti\CMSBundle\Controller\API
 */
class APIController {

    public function __construct(private ManagerRegistry $emi) {
    }
    
    /**
     * getObject
     *
     * @param ManagerRegistry $registry 
     * @return array|null
     */
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
        $objRep = $this->emi->getRepository($typeClass);
        return $objRep->findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * @param string           $typeClass   Object class. Must be subclass of
     *                                      DbObject.
     * @param string|\stdClass $criteria    Criteria for query (where clause).
     * @param array            $options     Associative array of options.
     *          See ObjectRepository for full details of options array.
     *
     * @return array|ArrayCollection|DbObject|false
     * @throws \Exception
     */
    public function getObjectsByContent(
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
        // TODO: Change findByMeta to findByContent and implement.
        return $this->emi->getRepository($typeClass)->findByContent(
            criteria: $criteria,
            options: $options
        );
    }

}
