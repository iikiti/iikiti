<?php

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Doctrine\Collections\ArrayCollection;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for application entities.
 *
 * @template-extends ObjectRepository<Application>
 */
class ApplicationRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		private SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		private ApplicationRegistry $appRegistry,
		string $entityClass = Application::class
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	public function getCurrentApplication(): ?Application
	{
		return $this->appRegistry->getCurrent();
	}

	/**
	 * Loads an Application by ID with its properties eager-fetched in a single
	 * cached query (no LIMIT 1 so the joined OneToMany collection is never truncated).
	 */
	public function findByIdWithProperties(int|string $id): ?Application
	{
		$qb = $this->createQueryBuilder('o')
			->leftJoin('o.properties', 'all_props')
			->addSelect('all_props')
			->andWhere('o.id = :id')
			->setParameter('id', $id);

		/** @var list<Application> $results */
		$results = $this->_executeAndCache($qb, [
			'operation' => 'findByIdWithProperties',
			'id' => (string) $id,
			'dependentClasses' => [ObjectProperty::class],
		]);

		return $results[0] ?? null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function getMultifactorPreferences(): ?array
	{
		return $this->getCurrentApplication()?->getMultifactorPreferences();
	}

	/**
	 * @param array<string,mixed> $preferences
	 */
	public function setMultifactorPreferences(array $preferences): void
	{
		$app = $this->getCurrentApplication();
		if (null === $app) {
			throw new \Exception('No current application. Cannot set preferences.');
		}

		$app->setMultifactorPreferences($preferences);
	}

	/**
	 * @return array<Application>
	 */
	public function findByDomain(string $domain): array
	{
		$siteIds = (new ArrayCollection($this->__getSiteRepository()->findByDomain($domain)))->
			map(function (Site $site) {
				return $site->getId();
			})->toArray();

		return $this->findByProperty($this->getDiscriminatorKey(Site::class), $siteIds);
	}

	/**
	 * @return array<string,mixed>
	 */
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
