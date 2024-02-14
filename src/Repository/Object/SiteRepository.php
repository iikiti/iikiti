<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\MfaBundle\Authentication\Interface\ApplicationSubordinateInterface;
use iikiti\MfaBundle\Authentication\Interface\MfaPreferencesInterface;

/**
 * Class SiteRepository.
 */
class SiteRepository extends ObjectRepository implements
	MfaPreferencesInterface,
	ApplicationSubordinateInterface
{
	public function __construct(
		ManagerRegistry $registry,
		private SiteRegistry $siteRegistry,
		string $entityClass = Site::class
	) {
		parent::__construct($registry, $siteRegistry, $entityClass);
	}

	public function getApplicationRepository(): ApplicationRepository
	{
		/** @var ApplicationRepository $appRep */
		$appRep = $this->getEntityManager()->getRepository(Application::class);

		return $appRep;
	}

	public function getApplicationBySite(Site $site): ?Application
	{
		return $this->getEntityManager()->getRepository(Application::class)->find(
			$site->getProperties()->get(Application::PROPERTY_KEY)->getValue()
		);
	}

	public function getCurrent(): ?Site
	{
		return $this->siteRegistry::getCurrent();
	}

	public function getMultifactorPreferences(): array|null
	{
		return $this->getCurrent()?->getMultifactorPreferences();
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		($site = $this->getCurrent())?->setMultifactorPreferences($preferences);
		if (null === $site) {
			throw new \Exception('No current site. Cannot set preferences.');
		}
	}

	/**
	 * @return array<Site>
	 */
	public function findByDomain(string $domain, ?int $appId = null): array
	{
		return $this->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain);
	}

	/**
	 * @return array<Site>
	 */
	public function findByApplication(int|Application|null $application = null): array
	{
		return $this->findByProperty(
			Application::PROPERTY_KEY,
			$application instanceof Application ? $application->getId() : $application
		);
	}

	/**
	 * @return array<Site>
	 */
	public function findByDomainAndApplication(string $domain, int $appId): array
	{
		return $this->findByProperty(
			[Site::DOMAIN_PROPERTY_KEY, Application::PROPERTY_KEY],
			[$domain, $appId]
		);
	}
}
