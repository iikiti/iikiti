<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Integration;

use iikiti\CMS\Event\Subscriber\Initializer;
use iikiti\CMS\Registry\RouteContextClassifier;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\Extension\ContentOptimization\Analytics\AnalyticsConnectorRegistry;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Verifies the iikiti-content-optimization bundle wiring, routes, autoconfigured
 * connectors, Initializer/SiteRegistry routing contract, and keyset sitemap SQL.
 */
final class ContentOptimizationKernelWiringTest extends KernelTestCase
{
	protected function tearDown(): void
	{
		while (SiteRegistry::hasCurrent()) {
			SiteRegistry::popSite();
		}
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		parent::tearDown();
	}

	public function testRoutesAndAutoconfiguredBuiltInConnectorsAreRegistered(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		/** @var RouterInterface $router */
		$router = $container->get('router');
		$routes = $router->getRouteCollection();

		foreach ([
			'api_admin_application_content_optimization_settings_get',
			'api_admin_application_content_optimization_settings_put',
			'api_site_content_optimization_settings_get',
			'api_site_content_optimization_settings_put',
			'api_site_content_optimization_activation_put',
			'public_robots',
			'public_sitemap',
			'api_public_render_content_optimization_robots',
			'api_public_render_content_optimization_sitemap',
		] as $routeName) {
			self::assertNotNull($routes->get($routeName), sprintf('Expected route "%s" to be registered.', $routeName));
		}

		self::assertContains('HEAD', $routes->get('public_robots')->getMethods());
		self::assertContains('HEAD', $routes->get('public_sitemap')->getMethods());

		/** @var AnalyticsConnectorRegistry $registry */
		$registry = $container->get(AnalyticsConnectorRegistry::class);
		self::assertArrayHasKey('iikiti-content-optimization.ga4', $registry->all());
		self::assertArrayHasKey('iikiti-content-optimization.clarity', $registry->all());
		self::assertInstanceOf(ContentOptimizationSettings::class, $container->get(ContentOptimizationSettings::class));
	}

	public function testInitializerAcceptsApplicationAdminContentOptimizationRouteWithoutSite(): void
	{
		self::bootKernel();
		$container = self::getContainer();

		$request = Request::create('https://api.example.test/api/admin/applications/7/content-optimization/settings', 'GET');
		$request->attributes->set('_route', 'api_admin_application_content_optimization_settings_get');

		self::assertTrue(RouteContextClassifier::isSiteIndependentRoute(
			'api_admin_application_content_optimization_settings_get',
			$request->getPathInfo(),
			'api',
			false,
		));

		/** @var Initializer $initializer */
		$initializer = $container->get(Initializer::class);
		$event = new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
		$initializer->onKernelRequest($event);

		self::assertNull($event->getResponse());
		self::assertFalse(SiteRegistry::hasCurrent());
	}

	public function testPageRepositoryFindSitemapBatchForSiteExecutesValidKeysetSql(): void
	{
		$connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE object_lookup (id INTEGER PRIMARY KEY, type TEXT NOT NULL, site_id INTEGER NOT NULL)');
		$connection->executeStatement('CREATE TABLE object_properties (object_id INTEGER NOT NULL, name TEXT NOT NULL, value TEXT, secret BOOLEAN NOT NULL DEFAULT 0)');
		$connection->executeStatement("INSERT INTO object_lookup (id, type, site_id) VALUES (1, 'page', 5), (2, 'page', 5), (3, 'page', 9), (4, 'template', 5)");
		$connection->executeStatement("INSERT INTO object_properties (object_id, name, value, secret) VALUES (1, 'slug', '\"about\"', 0), (1, 'settings', '{\"iikiti_content_optimization_robots_index\":\"index\"}', 0), (2, 'slug', '\"secret-page\"', 1), (2, 'slug', '\"contact\"', 0), (3, 'slug', '\"other-site\"', 0)");

		$em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
		$em->method('getConnection')->willReturn($connection);
		$em->method('getClassMetadata')->willReturn(new \Doctrine\ORM\Mapping\ClassMetadata(\iikiti\CMS\Entity\Object\Page::class));
		$registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
		$registry->method('getManagerForClass')->willReturn($em);
		$repo = (new \ReflectionClass(PageRepository::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(\Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class, 'registry'))->setValue($repo, $registry);
		(new \ReflectionProperty(\Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository::class, 'entityClass'))->setValue($repo, \iikiti\CMS\Entity\Object\Page::class);

		$batch = $repo->findSitemapBatchForSite(5, 0, 10);

		self::assertCount(2, $batch);
		self::assertSame(1, $batch[0]['id']);
		self::assertSame('about', $batch[0]['slug']);
		self::assertSame(['iikiti_content_optimization_robots_index' => 'index'], $batch[0]['settings']);
		self::assertSame(2, $batch[1]['id']);
		self::assertSame('contact', $batch[1]['slug']);
		self::assertSame([], $batch[1]['settings']);

		$nextBatch = $repo->findSitemapBatchForSite(5, 1, 10);
		self::assertCount(1, $nextBatch);
		self::assertSame(2, $nextBatch[0]['id']);
	}
}
