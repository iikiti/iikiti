<?php
namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
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
		/** @var ObjectRepository $propRep */
		$propQ = $this->getEntityManager()
			->getRepository(ObjectProperty::class)
			->createQueryBuilder('p2')
			->select('p2.object_id')
			->setParameter(':name', 'domain')
			->setParameter(':domain', json_encode($domain))
			->andWhere('p2.name = :name')
			->andWhere("JSON_CONTAINS(p2.value, :domain) = 1")
			->orderBy('p2.created')
			->setMaxResults(1);
		$siteQ = $this->getEntityManager()
			->createQuery(
				'SELECT s ' .
				'FROM ' . $this->getClassName() . ' s ' .
					'JOIN s.properties p ' .
				'WHERE p.name = \'domain\' AND JSON_CONTAINS(p.value, :domain) = 1 AND s.id IN (' .
					$propQ->getDQL() . ')'
			)
			->setParameter(':name', 'domain')
			->setParameter('domain', json_encode($domain));
        return $siteQ->getResult();
    }

}
