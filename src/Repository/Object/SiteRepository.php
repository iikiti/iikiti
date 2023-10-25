<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Class SiteRepository
 *
 * @package iikiti\CMS\Repository
 */
class SiteRepository extends ObjectRepository {

    public function __construct(ManagerRegistry $registry, string $entityClass = Site::class) {
        parent::__construct($registry, $entityClass);
    }

    public function findByDomain(string $domain): array {
		$qb = $this->createQueryBuilder('s');
		$siteQ = $qb->select('s')
			->join(
				's.properties',
				'd',
				Join::WITH,
				'd.object_id = s.id AND d.name = :name AND JSON_CONTAINS(d.value, :domain) = 1'
			)
			->setParameter('name', 'domain')
			->setParameter('domain', json_encode($domain));
        return $siteQ->getQuery()->getResult();
    }

}
