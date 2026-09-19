<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\SiteGroup;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for site group entities.
 *
 * @template-extends ObjectRepository<SiteGroup>
 */
class SiteGroupRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = SiteGroup::class,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	public function findByName(string $name): ?SiteGroup
	{
		/** @var SiteGroup|null $result */
		$result = $this->findOneByProperty('name', $name);

		return $result;
	}

	/**
	 * Resolve the site IDs stored in a group's `site_ids` property to Site entities.
	 *
	 * @return array<Site>
	 */
	public function findSites(SiteGroup $group): array
	{
		$ids = $group->getSiteIds();
		if ([] === $ids) {
			return [];
		}

		/** @var array<Site> $sites */
		$sites = $this->getEntityManager()
			->getRepository(Site::class)
			->findBy(['id' => $ids]);

		return $sites;
	}
}
