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
			->createQueryBuilder('p')
			->setParameter(':name', 'domain')
			->setParameter(':domain', $domain)
			->andWhere('p.name = :name')
			->andWhere('JSON_CONTAINS(p.value, :domain, "$")');
		var_dump($propQ->getQuery()->getResult());
        return [$this->find(1)];
    }

}
