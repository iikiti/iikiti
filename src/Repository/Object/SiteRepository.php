<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\MfaBundle\Authentication\Interface\MfaPreferencesInterface;

/**
 * Class SiteRepository.
 */
class SiteRepository extends ObjectRepository implements MfaPreferencesInterface
{
	public function __construct(
		ManagerRegistry $registry,
		protected SiteRegistry $siteRegistry,
		string $entityClass = Site::class
	) {
		parent::__construct($registry, $entityClass);
	}

	public function getRegistry(): SiteRegistry
	{
		return $this->siteRegistry;
	}

	public function getCurrentSite(): ?Site
	{
		return $this->siteRegistry->getCurrentSite();
	}

	public function getMultifactorPreferences(): array|null
	{
		return $this->getCurrentSite()?->getMultifactorPreferences();
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		($site = $this->getCurrentSite())?->setMultifactorPreferences($preferences);
		if (null === $site) {
			throw new \Exception('No current site. Cannot set preferences.');
		}
	}

	public function findByDomain(string $domain): array
	{
		$qb = $this->createQueryBuilder('s');
		$siteQ = $qb->select('s')->
			join(
				's.properties',
				'd',
				Join::WITH,
				'd.object_id = s.id AND d.name = :name AND JSON_CONTAINS(d.value, :domain) = 1'
			)->
			setParameter('name', 'domain')->
			setParameter('domain', json_encode($domain));

		return $siteQ->getQuery()->getResult();
	}
}
