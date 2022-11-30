<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Class PageRepository
 *
 * @package iikiti\CMS\Repository
 */
class PageRepository extends ObjectRepository
{
    public function __construct(ManagerRegistry $registry, string $entityClass = Page::class)
    {
        parent::__construct($registry, $entityClass);
    }
}
