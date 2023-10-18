<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Repository\ObjectPropertyRepository;
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
		/** @var ObjectPropertyRepository $opr */
		$opr = $this->getEntityManager()
			->getRepository(ObjectProperty::class);
		$lp = $opr->getLatestQuery();
		$qb = $this->createQueryBuilder('s');
		$siteQ = $qb->select('s')
			->join(
				$this->getEntityManager()->getRepository(ObjectProperty::class)->getEntityName(),
				'd',
				Join::ON,
				'd.object_id = s.id AND d.name = :name AND JSON_CONTAINS(d.value, :domain) = 1'
			)
			->setParameter('name', 'domain')
			->setParameter('domain', json_encode($domain))
			->andWhere($qb->expr()->in('(d.id, d.object_id)', $lp->getSQL()));
		var_dump($siteQ->getQuery()->getSQL());
        return $siteQ->getQuery()->getResult();
    }

}
