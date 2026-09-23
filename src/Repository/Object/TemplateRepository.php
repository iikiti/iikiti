<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Template;
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
}
