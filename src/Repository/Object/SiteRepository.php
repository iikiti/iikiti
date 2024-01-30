<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Application;
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

	public function getApplicationBySite(Site $site): ?Application
	{
		return $this->getEntityManager()->getRepository(Application::class)->find(
			$site->getProperties()->get(Application::PROPERTY_KEY)->getValue()
		);
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

	public function findByDomain(string $domain, int $appId = null): array
	{
		return $this->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain);
	}

	public function findByApplication(int $appId = null): array
	{
		return $this->findByProperty(Application::PROPERTY_KEY, $appId);
	}

	public function findByDomainAndApplication(string $domain, int $appId): array
	{
		return $this->findByProperty(
			[Site::DOMAIN_PROPERTY_KEY, Application::PROPERTY_KEY],
			[$domain, $appId]
		);
	}
}
