<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Lexeme;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for lexeme entities.
 *
 * @template-extends ObjectRepository<Lexeme>
 */
class LexemeRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = Lexeme::class
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}
}
