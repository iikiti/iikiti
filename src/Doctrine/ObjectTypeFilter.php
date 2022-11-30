<?php

namespace iikiti\CMS\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use iikiti\CMS\Entity\DbObject;

class ObjectTypeFilter extends SQLFilter {

    public function addFilterConstraint(
        ClassMetadata $targetEntity,
        $targetTableAlias
    ): string {
        if(
            $targetEntity->getName() !== DbObject::class &&
            $targetEntity->getReflectionClass()->isSubclassOf(DbObject::class) == false
        ) {
            return '';
        }

        return $targetTableAlias .
            '.type = \'' .
                ($targetEntity->getName()::DEFAULT_TYPE)
            . '\'';
    }

}
