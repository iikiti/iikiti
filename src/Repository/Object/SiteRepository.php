<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Class SiteRepository
 *
 * @package iikiti\CMS\Repository
 */
class SiteRepository extends ObjectRepository
{

    public function __construct(ManagerRegistry $registry, string $entityClass = Site::class)
    {
        parent::__construct($registry, $entityClass);
    }

    public function findByDomain(string $domain): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere(
                'JSON_CONTAINS(o.content_json, :domain, \'$.domain\') = 1'
            )->setParameter('domain', json_encode($domain))->getQuery()->getResult();
    }

}
