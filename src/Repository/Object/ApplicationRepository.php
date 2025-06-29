<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;

/**
 * Repository for application entities.
 */
class ApplicationRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		private SiteRegistry $siteRegistry,
		private ApplicationRegistry $appRegistry,
		string $entityClass = Application::class
	) {
		parent::__construct($registry, $siteRegistry, $entityClass);
	}

	public function getCurrentApplication(): ?Application
	{
		return $this->appRegistry->getCurrent();
	}

	public function getMultifactorPreferences(): ?array
	{
		return $this->getCurrentApplication()?->getMultifactorPreferences();
	}

	public function setMultifactorPreferences(array $preferences): void
	{
		($app = $this->getCurrentApplication())?->setMultifactorPreferences($preferences);
		if (null === $app) {
			throw new \Exception('No current application. Cannot set preferences.');
		}
	}

	/**
	 * @return array<Application>
	 */
	public function findByDomain(string $domain): array
	{
		$siteIds = (new ArrayCollection($this->__getSiteRepository()->findByDomain($domain)))->
			map(function (Site $site) {
				return $site->getId();
			});

		return $this->findByProperty($this->getDiscriminatorKey(Site::class), $siteIds);
	}

	public function getSites(Application $app): array
	{
		return $this->__getSiteRepository()->findByApplication($app);
	}

	private function __getSiteRepository(): SiteRepository
	{
		/** @var SiteRepository $siteRep */
		$siteRep = $this->getEntityManager()->getRepository(Site::class);

		return $siteRep;
	}
}
