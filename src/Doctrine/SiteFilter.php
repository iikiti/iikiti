<?php

namespace iikiti\CMS\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;

class SiteFilter extends SQLFilter {

    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if(
            $targetEntity->getName() !== DbObject::class &&
            $targetEntity->getReflectionClass()->isSubclassOf(DbObject::class) == false
        ) {
            return '';
        } else if ($targetEntity->getName() === User::class) {
            return $targetTableAlias . '.site_id = 0';
        }

        $site = SiteRegistry::getCurrentSite();

		$this->setParameter('_siteId', $site instanceof Site ? $site->getId() : 0);

        return $targetTableAlias . '.site_id = ' . $this->getParameter('_siteId');
    }

}
