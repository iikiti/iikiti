<?php

namespace iikiti\CMS\Repository;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\IconEntity;
use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * @template-extends ObjectRepository<IconEntity>
 */
class IconRepository extends ObjectRepository
{
	public function __construct(ManagerRegistry $registry, SiteRegistry $siteRegistry, DatabaseCacheManager $cacheManager)
	{
		parent::__construct($registry, $siteRegistry, $cacheManager, IconEntity::class);
	}

	/**
	 * @return list<IconEntity>
	 */
	public function findBySet(IconSetEntity $set): array
	{
		/** @var list<IconEntity> */
		return $this->findBy(['iconSet' => $set], ['name' => 'ASC']);
	}

	public function findOneInSet(IconSetEntity $set, string $name): ?IconEntity
	{
		return $this->findOneBy(['iconSet' => $set, 'name' => $name]);
	}
}
