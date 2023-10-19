<?php

namespace iikiti\CMS\Filters;

use Doctrine\ORM\Mapping\ClassMetadata;
use \Doctrine\ORM\Query\Filter\SQLFilter;
use iikiti\CMS\Entity\ObjectProperty;

/**
 * Class SqlFilter
 *
 */
class LatestObjectPropertyFilter extends SQLFilter {

	public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
	{
		if ($targetEntity->getName() === ObjectProperty::class) {
			return $targetTableAlias . '.id IN (SELECT _lop.id FROM latest_object_properties _lop)';
		}

		return '';
	}

}
