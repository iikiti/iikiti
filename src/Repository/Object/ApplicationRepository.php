<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\MfaBundle\Authentication\Interface\MfaPreferencesInterface;

class ApplicationRepository extends ObjectRepository implements MfaPreferencesInterface
{
	public function __construct(
		ManagerRegistry $registry,
		protected ApplicationRegistry $appRegistry,
		string $entityClass = Application::class
	) {
		parent::__construct($registry, $entityClass);
	}

	public function getRegistry(): SiteRegistry
	{
		return $this->siteRegistry;
	}

	public function getCurrentApplication(): ?Application
	{
		return $this->appRegistry->getCurrentApplication();
	}

	public function getMultifactorPreferences(): array|null
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

	public function findByDomain(string $domain): array
	{
		$siteRep = $this->getEntityManager()->getRepository(Site::class);

		return $siteRep->findByDomain($domain);
	}
}
