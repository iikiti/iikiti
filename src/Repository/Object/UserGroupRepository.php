<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\UserGroup;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for user group entities.
 *
 * @template-extends ObjectRepository<UserGroup>
 */
class UserGroupRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = UserGroup::class,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	public function findByName(string $name): ?UserGroup
	{
		/** @var UserGroup|null $result */
		$result = $this->findOneByProperty('name', $name);

		return $result;
	}
}
