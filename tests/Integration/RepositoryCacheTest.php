<?php

namespace iikiti\CMS\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Cache\Strategy\DoctrineResultCacheStrategy;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\CacheState;
use iikiti\CMS\Service\DatabaseCacheManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Functional tests for repository-level database query caching against a
 * real database. The test database schema must exist; tests are skipped when
 * the database is unavailable.
 */
final class RepositoryCacheTest extends KernelTestCase
{
	private const REGISTRY_DOMAIN = 'registry.example.com';

	private EntityManagerInterface $em;
	private SiteRepository $repository;
	private CacheState $cacheState;
	private DatabaseCacheManager $cacheManager;

	/** @var array<int,int> */
	private array $createdSiteIds = [];

	/** @var array<int,int> */
	private array $registrySiteIds = [];

	/** @var array<int,int> map of site id => property creator user id */
	private array $siteCreators = [];

	protected function setUp(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		/** @var EntityManagerInterface $em */
		$em = $container->get('doctrine.orm.entity_manager');
		try {
			$em->getConnection()->executeQuery('SELECT 1');
		} catch (\Throwable $e) {
			$this->markTestSkipped('Database not available: '.$e->getMessage());
		}

		$this->em = $em;
		/** @var CacheState $cacheState */
		$cacheState = $container->get(CacheState::class);
		$this->cacheState = $cacheState;
		/** @var DatabaseCacheManager $cacheManager */
		$cacheManager = $container->get(DatabaseCacheManager::class);
		$this->cacheManager = $cacheManager;

		$this->ensurePlaceholderZero();
		$this->cleanupLeftoverSites();

		// SiteRegistry populates itself from the current request host on first
		// use. Seed a registry site and push a matching request so obtaining
		// the repository below succeeds without a request.
		$registryDomain = self::REGISTRY_DOMAIN;
		$this->registrySiteIds[] = $this->insertSite($registryDomain, true);

		/** @var RequestStack $requestStack */
		$requestStack = $container->get(RequestStack::class);
		$requestStack->push(Request::create('http://'.$registryDomain));

		/** @var SiteRepository $repository */
		$repository = $em->getRepository(Site::class);
		$this->repository = $repository;

		$current = SiteRegistry::getCurrent();
		$this->assertInstanceOf(Site::class, $current);

		$this->cacheState->enable();
	}

	protected function tearDown(): void
	{
		if (isset($this->em) && $this->em->getConnection()->isConnected()) {
			$this->deleteEntityRows(array_merge($this->createdSiteIds, $this->registrySiteIds));
		}

		parent::tearDown();
	}

	public function testFindByDomainReturnsSeedData(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);

		$sites = $this->repository->findByDomain($domain);

		$this->assertCount(1, $sites);
		$this->assertInstanceOf(Site::class, $sites[0]);
		$this->assertSame($domain, $sites[0]->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue());
	}

	public function testCacheIsUsedOnSecondFetch(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);
		$this->assertCount(1, $this->repository->findByDomain($domain));

		// Cache the result, then remove the row behind Doctrine's back so any
		// cache hit must come from the cached result, not the database.
		$this->deleteSiteRows();
		$this->em->clear();

		$sites = $this->repository->findByDomain($domain);

		$this->assertCount(1, $sites);
		$this->assertInstanceOf(Site::class, $sites[0]);
		// The cached raw result is re-hydrated even though the rows were
		// deleted behind Doctrine's back, proving the result came from cache.
		$this->assertSame($this->createdSiteIds[0], (int) $sites[0]->getId());
	}

	public function testCacheDisabledPerQuery(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);
		$this->assertCount(
			1,
			$this->repository->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain, ['cache' => false])
		);

		$this->deleteSiteRows();
		$this->em->clear();

		$sites = $this->repository->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain, ['cache' => false]);

		$this->assertCount(0, $sites);
	}

	public function testCacheDisabledPerRequest(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);
		$this->assertCount(1, $this->repository->findByDomain($domain));

		$this->cacheState->disable();
		$this->deleteSiteRows();
		$this->em->clear();

		$sites = $this->repository->findByDomain($domain);

		$this->assertCount(0, $sites);
	}

	public function testInvalidationBumpsGeneration(): void
	{
		$generationBefore = $this->cacheManager->getGeneration(Site::class);

		$this->cacheManager->invalidate(Site::class);

		$this->assertSame($generationBefore + 1, $this->cacheManager->getGeneration(Site::class));
	}

	public function testInvalidatedEntityIsRefetchedFromDatabase(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);
		$this->assertCount(
			1,
			$this->repository->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain, ['cacheTTL' => 3600])
		);

		$this->cacheManager->invalidate(Site::class);
		$this->em->clear();

		$sites = $this->repository->findByProperty(Site::DOMAIN_PROPERTY_KEY, $domain);

		$this->assertCount(1, $sites);
	}

	public function testFindOneByUsesCaching(): void
	{
		$domain = $this->seedSite(self::REGISTRY_DOMAIN);
		$site = $this->repository->findOneBy(['id' => $this->createdSiteIds[0]]);

		$this->assertInstanceOf(Site::class, $site);
		$this->assertSame($domain, $site->getProperties()->get(Site::DOMAIN_PROPERTY_KEY)?->getValue());
	}

	public function testStrategiesAreSelectableThroughRegistry(): void
	{
		$this->assertSame(
			DoctrineResultCacheStrategy::NAME,
			$this->cacheManager->getStrategy()->getName()
		);

		$this->cacheState->setOverrideStrategy('none');
		$this->assertSame('none', $this->cacheManager->getStrategy()->getName());
	}

	/**
	 * Seeds a single Site with a unique domain property using raw SQL so the
	 * cache layer (and not the identity map) is what's under test.
	 */
	private function seedSite(string $domain): string
	{
		$uniqueDomain = $domain.'.'.substr(bin2hex(random_bytes(4)), 0, 8);
		$this->createdSiteIds[] = $this->insertSite($uniqueDomain, true);

		$found = $this->repository->findByProperty(Site::DOMAIN_PROPERTY_KEY, $uniqueDomain, ['cache' => false]);
		$this->assertCount(1, $found, 'Seed data could not be queried back.');

		return $uniqueDomain;
	}

	private function insertSite(string $domain, bool $track = false): int
	{
		// ObjectProperty.creator is a OneToOne to User; every property needs
		// its own creator user row.
		$userRow = $this->em->getConnection()->executeQuery(
			'INSERT INTO iikiti_iikiti.objects (created_date, creator_id, site_id, type) '.
			"VALUES (NOW(), 0, 0, 'user') RETURNING id"
		)->fetchAssociative();
		$userId = (int) ($userRow['id'] ?? 0);

		$siteRow = $this->em->getConnection()->executeQuery(
			'INSERT INTO iikiti_iikiti.objects (created_date, creator_id, site_id, type) '.
			"VALUES (NOW(), 0, 0, 'site') RETURNING id"
		)->fetchAssociative();
		$siteId = (int) ($siteRow['id'] ?? 0);

		$this->em->getConnection()->executeStatement(
			'INSERT INTO iikiti_iikiti.object_properties (object_id, name, value, created, creator_id) '.
			'VALUES (:object_id, :name, :value, NOW(), :creator_id)',
			[
				'object_id' => $siteId,
				'name' => Site::DOMAIN_PROPERTY_KEY,
				'value' => json_encode($domain, JSON_THROW_ON_ERROR),
				'creator_id' => $userId,
			]
		);

		if ($track) {
			$this->siteCreators[$siteId] = $userId;
		}

		return $siteId;
	}

	private function deleteSiteRows(): void
	{
		$this->deleteEntityRows($this->createdSiteIds);
	}

	/**
	 * Deletes the properties, sites and their creator users for the given
	 * site ids, respecting foreign key ordering.
	 *
	 * @param array<int,int> $siteIds
	 */
	private function deleteEntityRows(array $siteIds): void
	{
		foreach ($siteIds as $id) {
			$this->em->getConnection()->executeStatement(
				'DELETE FROM iikiti_iikiti.object_properties WHERE object_id = :id',
				['id' => $id]
			);
		}
		foreach ($siteIds as $id) {
			$this->em->getConnection()->executeStatement(
				'DELETE FROM iikiti_iikiti.objects WHERE id = :id',
				['id' => $id]
			);
			$creatorId = $this->siteCreators[$id] ?? null;
			if (null !== $creatorId) {
				$this->em->getConnection()->executeStatement(
					'DELETE FROM iikiti_iikiti.objects WHERE id = :id',
					['id' => $creatorId]
				);
			}
		}
	}

	/**
	 * Site query results are filtered to site_id 0 (sites are not
	 * site-specific). The dev database satisfies this foreign key with an
	 * id=0 placeholder object; create it when missing.
	 */
	private function ensurePlaceholderZero(): void
	{
		$this->em->getConnection()->executeStatement(
			'INSERT INTO iikiti_iikiti.objects (id, created_date, creator_id, site_id, type) '.
			"VALUES (0, NOW(), 0, NULL, 'application') ON CONFLICT (id) DO NOTHING"
		);
	}

	/**
	 * Remove site rows leftover from interrupted runs so each test starts
	 * from a clean slate.
	 */
	private function cleanupLeftoverSites(): void
	{
		$this->em->getConnection()->executeStatement(
			'DELETE FROM iikiti_iikiti.object_properties WHERE object_id IN '.
			'(SELECT id FROM iikiti_iikiti.objects WHERE type IN (\'site\', \'user\'))'
		);
		$this->em->getConnection()->executeStatement(
			"DELETE FROM iikiti_iikiti.objects WHERE type IN ('site', 'user')"
		);
	}
}
