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

	/** @return list<UserGroup> */
	public function findAvailableForSite(int $siteId): array
	{
		$groups = $this->findAll(['filterBySite' => false]);
		$groups = array_values(array_filter($groups, static fn (UserGroup $group): bool =>
			in_array((string) $group->getSiteId(), ['0', (string) $siteId], true)));
		usort($groups, static fn (UserGroup $left, UserGroup $right): int =>
			strnatcasecmp($left->getLabel() ?? $left->getName() ?? '', $right->getLabel() ?? $right->getName() ?? ''));

		return $groups;
	}
}
