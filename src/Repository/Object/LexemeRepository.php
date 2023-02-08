<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Lexeme;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Class PageRepository
 *
 * @package iikiti\CMS\Repository
 */
class LexemeRepository extends ObjectRepository
{
    public function __construct(ManagerRegistry $registry, string $entityClass = Lexeme::class)
    {
        parent::__construct($registry, $entityClass);
    }
}
