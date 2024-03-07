<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Repository for page entities.
 */
class PageRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		string $entityClass = Page::class
	) {
		parent::__construct($registry, $siteRegistry, $entityClass);
	}
}
