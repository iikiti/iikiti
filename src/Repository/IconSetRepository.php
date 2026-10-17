<?php

namespace iikiti\CMS\Repository;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * @template-extends ObjectRepository<IconSetEntity>
 */
class IconSetRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, IconSetEntity::class);
	}

	public function findOneBySlug(string $slug): ?IconSetEntity
	{
		return $this->findOneBy(['slug' => $slug]);
	}
}
