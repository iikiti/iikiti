<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\Template;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for templates.
 *
 * @template-extends ObjectRepository<Template>
 */
class TemplateRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = Template::class,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	/**
	 * Loads all templates for a site with their properties eager-fetched in a
	 * single cached query (invalidated on both Template and ObjectProperty writes).
	 *
	 * @return list<Template>
	 */
	public function findBySiteWithProperties(Site $site): array
	{
		$qb = $this->createQueryBuilder('o', null, ['filterBySite' => false])
			->leftJoin('o.properties', 'all_props')
			->addSelect('all_props')
			->andWhere('o.site = :site')
			->setParameter('site', $site->getId());

		/** @var list<Template> $templates */
		$templates = $this->_executeAndCache($qb, [
			'operation' => 'findBySiteWithProperties',
			'site_id' => $site->getId(),
			'dependentClasses' => [ObjectProperty::class],
		]);

		return $templates;
	}
}
