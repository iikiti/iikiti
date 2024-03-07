<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Lexeme;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Repository for lexeme entities.
 */
class LexemeRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		string $entityClass = Lexeme::class
	) {
		parent::__construct($registry, $siteRegistry, $entityClass);
	}
}
