<?php

namespace iikiti\CMS\Filters;

use Doctrine\ORM\Mapping\ClassMetadata;
use \Doctrine\ORM\Query\Filter\SQLFilter;
use iikiti\CMS\Doctrine\HintManager;
use iikiti\CMS\Entity\ObjectProperty;

/**
 * Class SqlFilter
 *
 */
class LatestObjectPropertyFilter extends SQLFilter {

	public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string	{

		$INCLUDE_REVISIONS = HintManager::getEraseHint('INCLUDE_PROPERTY_REVISIONS') == true;

		if ($targetEntity->getName() === ObjectProperty::class && !$INCLUDE_REVISIONS) {
			return $targetTableAlias . '.id IN (SELECT _lop.id FROM latest_object_properties _lop)';
		}

		return '';
	}

}
