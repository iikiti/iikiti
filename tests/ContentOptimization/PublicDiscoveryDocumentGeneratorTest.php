<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Page;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\Render\RenderedFragmentCache;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\PublicDiscoveryDocumentGenerator;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PublicDiscoveryDocumentGenerator.php';

final class PublicDiscoveryDocumentGeneratorTest extends TestCase
{
	private Site $site;
	private Application $application;
	private ContentOptimizationSettings $settings;
	private PageRepository $pages;
	private SiteRepository $sites;
	private ResponseHeaderStore $headerStore;

	protected function setUp(): void
	{
		new SiteRegistry(new RequestStack(), $this->createStub(ManagerRegistry::class), new GuestApiTokenManager('test-secret'), 'api');
		while (SiteRegistry::hasCurrent()) {
			SiteRegistry::popSite();
		}
		$this->site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($this->site, 17);
		$this->site->setProperty(Site::DOMAIN_PROPERTY_KEY, 'canonical.example.test:8443');
		$this->site->setProperty('name', 'Example Site');
		SiteRegistry::pushSite($this->site);
		$this->application = new Application();
		$this->sites = $this->createStub(SiteRepository::class);
		$this->sites->method('getApplicationBySite')->willReturn($this->application);
		$this->settings = new ContentOptimizationSettings();
		$this->pages = $this->createStub(PageRepository::class);
		$policy = new ResponseHeaderPolicy();
		$this->headerStore = new ResponseHeaderStore($policy);
	}

	protected function tearDown(): void
	{
		while (SiteRegistry::hasCurrent()) {
			SiteRegistry::popSite();
		}
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		parent::tearDown();
	}

	public function testRobotsReflectsAiAccessAndAddsOnlyCanonicalEnabledSitemap(): void
	{
		$defaults = $this->settings->defaults();
		$defaults['crawlers']['aiAccess'] = 'disallow';
		$this->settings->saveApplicationDefaults($this->application, $defaults);
		$generator = $this->generator();
		$request = Request::create('http://untrusted.example/robots.txt?private=1');
		$request->headers->set('X-SITE-SCHEME', 'https');

		$result = $generator->robots($request);

		self::assertSame('text/plain; charset=UTF-8', $result['contentType']);
		self::assertStringContainsString("User-agent: GPTBot\nDisallow: /", $result['body']);
		self::assertStringContainsString("User-agent: ClaudeBot\nDisallow: /", $result['body']);
		self::assertStringContainsString('Sitemap: http://canonical.example.test:8443/sitemap.xml', $result['body']);
		self::assertStringNotContainsString('untrusted.example', $result['body']);
		self::assertStringNotContainsString('private=1', $result['body']);

		$this->settings->saveSiteOverrides($this->site, ['sitemap' => ['enabled' => false]]);
		self::assertStringNotContainsString('Sitemap:', $generator->robots($request)['body']);
	}

	public function testSitemapIncludesOnlyRouteableIndexablePagesWithoutEffectiveNoindexHeaders(): void
	{
		$this->headerStore->setPolicy($this->application, [[
			'name' => 'X-Robots-Tag',
			'action' => 'set',
			'value' => 'googlebot: noindex, nofollow',
			'conditions' => ['field' => 'path', 'operator' => 'equals', 'value' => '/header-hidden'],
		]]);
		$pages = $this->createMock(PageRepository::class);
		$pages->expects(self::once())->method('findSitemapBatchForSite')->with(17, 0, 1000)->willReturn([
			['id' => 1, 'slug' => 'about', 'settings' => []],
			['id' => 2, 'slug' => 'private', 'settings' => ['iikiti_content_optimization_robots_index' => 'noindex']],
			['id' => 3, 'slug' => 'header-hidden', 'settings' => []],
			['id' => 4, 'slug' => 'nested/path', 'settings' => []],
			['id' => 5, 'slug' => 'off-canonical', 'settings' => ['iikiti_content_optimization_canonical_url' => 'https://other.example.test/elsewhere']],
			['id' => 6, 'slug' => 'self-canonical', 'settings' => ['iikiti_content_optimization_canonical_url' => '/self-canonical']],
		]);
		$this->pages = $pages;
		$generator = $this->generator();
		$request = Request::create('http://attacker.example/sitemap.xml?query=discard-me');
		$request->headers->set('X-SITE-SCHEME', 'https');

		$result = $generator->sitemap($request);

		self::assertSame('application/xml; charset=UTF-8', $result['contentType']);
		self::assertStringContainsString('<loc>http://canonical.example.test:8443/</loc>', $result['body']);
		self::assertStringContainsString('<loc>http://canonical.example.test:8443/about</loc>', $result['body']);
		self::assertStringContainsString('<loc>http://canonical.example.test:8443/self-canonical</loc>', $result['body']);
		self::assertStringNotContainsString('/private</loc>', $result['body']);
		self::assertStringNotContainsString('/header-hidden</loc>', $result['body']);
		self::assertStringNotContainsString('/nested/path</loc>', $result['body']);
		self::assertStringNotContainsString('/off-canonical</loc>', $result['body']);
		self::assertStringNotContainsString('attacker.example', $result['body']);
		self::assertStringNotContainsString('query=discard-me', $result['body']);
		self::assertStringNotContainsString('INTERNAL_API_BASE_URL', $result['body']);
	}

	public function testSitemapShortCircuitsUnconditionalNoindexHeaderAndReturnsCleanEmptyUrlset(): void
	{
		$this->headerStore->setPolicy($this->application, [[
			'name' => 'X-Robots-Tag',
			'action' => 'set',
			'value' => 'none',
		]]);
		$pages = $this->createMock(PageRepository::class);
		$pages->expects(self::never())->method('findSitemapBatchForSite');
		$this->pages = $pages;

		$result = $this->generator()->sitemap(Request::create('https://canonical.example.test/sitemap.xml'));

		self::assertSame(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>\n",
			$result['body'],
		);
	}

	public function testSitemapHonorsSiteRemoveActionOverApplicationNoindexAndSupportsCaching(): void
	{
		$this->headerStore->setPolicy($this->application, [[
			'name' => 'X-Robots-Tag',
			'action' => 'set',
			'value' => 'noindex',
		]]);
		$this->headerStore->setPolicy($this->site, [[
			'name' => 'X-Robots-Tag',
			'action' => 'remove',
		]]);
		$pages = $this->createMock(PageRepository::class);
		$pages->expects(self::exactly(2))->method('findSitemapBatchForSite')->willReturn([
			['id' => 10, 'slug' => 'public-page', 'settings' => []],
		]);
		$this->pages = $pages;
		$cache = new TagAwareAdapter(new ArrayAdapter());
		$generator = $this->generator(null, $cache);
		$request = Request::create('https://canonical.example.test/sitemap.xml');

		$first = $generator->sitemap($request);
		$second = $generator->sitemap($request);
		self::assertSame($first['body'], $second['body']);
		self::assertStringContainsString('<loc>https://canonical.example.test:8443/public-page</loc>', $first['body']);

		$cache->invalidateTags([RenderedFragmentCache::CMS_DEPENDENCY_TAG]);
		$third = $generator->sitemap($request);
		self::assertSame($first['body'], $third['body']);
	}

	public function testSitemapReturnsNotFoundWhenDisabledOrDomainInvalid(): void
	{
		$this->settings->saveSiteOverrides($this->site, ['sitemap' => ['enabled' => false]]);
		try {
			$this->generator()->sitemap(Request::create('https://canonical.example.test/sitemap.xml'));
			self::fail('Disabled sitemap must return 404.');
		} catch (NotFoundHttpException) {
		}

		$this->settings->saveSiteOverrides($this->site, ['sitemap' => ['enabled' => true]]);
		$this->site->setProperty(Site::DOMAIN_PROPERTY_KEY, 'not a valid domain/path');
		$this->expectException(NotFoundHttpException::class);
		$this->generator()->sitemap(Request::create('https://canonical.example.test/sitemap.xml'));
	}

	public function testSitemapKeysetPaginatesAcrossMultipleBatches(): void
	{
		$batch1 = [];
		for ($i = 1; $i <= 1000; ++$i) {
			$batch1[] = ['id' => $i, 'slug' => 'page-'.$i, 'settings' => []];
		}
		$pages = $this->createMock(PageRepository::class);
		$calls = [];
		$pages->expects(self::exactly(2))->method('findSitemapBatchForSite')->willReturnCallback(
			static function (int $siteId, int $afterId, int $limit) use ($batch1, &$calls): array {
				$calls[] = [$siteId, $afterId, $limit];
				return 0 === $afterId ? $batch1 : [['id' => 1001, 'slug' => 'page-1001', 'settings' => []]];
			},
		);
		$this->pages = $pages;

		$result = $this->generator()->sitemap(Request::create('https://canonical.example.test/sitemap.xml'));

		self::assertSame([[17, 0, 1000], [17, 1000, 1000]], $calls);
		self::assertStringContainsString('<loc>https://canonical.example.test:8443/page-1001</loc>', $result['body']);
	}

	public function testDisabledPluginReturnsNotFoundBeforeReadingApplicationSettings(): void
	{
		$this->site->getConfiguration()->disablePlugin(ContentOptimizationSettings::PLUGIN_SLUG);
		$sites = $this->createMock(SiteRepository::class);
		$sites->expects(self::never())->method('getApplicationBySite');
		$generator = $this->generator($sites);

		try {
			$generator->robots(Request::create('https://canonical.example.test/robots.txt'));
			self::fail('Disabled plugin discovery documents must return 404.');
		} catch (NotFoundHttpException $exception) {
			self::assertSame(404, $exception->getStatusCode());
		}
	}

	public function testPublicSchemeDefaultsToTheResolvedRequestScheme(): void
	{
		$result = $this->generator()->robots(Request::create('http://untrusted.example/robots.txt'));

		self::assertStringContainsString('Sitemap: http://canonical.example.test:8443/sitemap.xml', $result['body']);
		self::assertStringNotContainsString('untrusted.example', $result['body']);
	}

	private function generator(?SiteRepository $sites = null, ?TagAwareCacheInterface $cache = null): PublicDiscoveryDocumentGenerator
	{
		$entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturn($this->sites);

		return new PublicDiscoveryDocumentGenerator(
			$sites ?? $this->sites,
			$this->pages,
			$this->settings,
			new EditorSettingsManager($entityManager),
			new ResponseHeaderResolver($entityManager, $this->headerStore, new ResponseHeaderPolicy(), 0),
			new PageSeoSettingsNormalizer(),
			$this->headerStore,
			new ResponseHeaderPolicy(),
			$cache,
		);
	}
}
