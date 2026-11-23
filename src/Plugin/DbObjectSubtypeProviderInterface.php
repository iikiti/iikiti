<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin;

use iikiti\CMS\Entity\DbObject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Allows plugins to register additional {@see DbObject} STI subtypes dynamically.
 */
#[AutoconfigureTag('iikiti.cms.db_object_subtype')]
interface DbObjectSubtypeProviderInterface
{
	/**
	 * Map of STI discriminator value => concrete DbObject subclass FQCN.
	 *
	 * @return array<string, class-string<DbObject>>
	 */
	public function getDbObjectSubtypes(): array;
}
