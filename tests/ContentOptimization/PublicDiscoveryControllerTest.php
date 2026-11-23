<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use iikiti\Extension\ContentOptimization\Controller\Page\PublicDiscoveryController;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\PublicDiscoveryDocumentGenerator;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PublicDiscoveryDocumentGenerator.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Controller/Page/PublicDiscoveryController.php';

final class PublicDiscoveryControllerTest extends TestCase
{
	protected function setUp(): void
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 22);
		$site->setProperty(Site::DOMAIN_PROPERTY_KEY, 'example.test');
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, true);
		SiteRegistry::pushSite($site);
	}

	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		parent::tearDown();
	}

	public function testDirectModeDelegatesToDocumentGeneratorWithPublicCacheControl(): void
	{
		$proxyCalls = 0;
		$client = new MockHttpClient(static function () use (&$proxyCalls): MockResponse {
			++$proxyCalls;
			return new MockResponse('{}');
		});
		$controller = new PublicDiscoveryController($this->proxy($client), $this->generator(), 'full');

		$robots = $controller->robots(Request::create('https://example.test/robots.txt', 'GET'));
		self::assertSame(200, $robots->getStatusCode());
		self::assertSame('text/plain; charset=UTF-8', $robots->headers->get('Content-Type'));
		self::assertStringContainsString('public', (string) $robots->headers->get('Cache-Control'));
		self::assertStringContainsString('max-age=300', (string) $robots->headers->get('Cache-Control'));

		$sitemap = $controller->sitemap(Request::create('https://example.test/sitemap.xml', 'HEAD'));
		self::assertSame(200, $sitemap->getStatusCode());
		self::assertSame('application/xml; charset=UTF-8', $sitemap->headers->get('Content-Type'));
		self::assertStringContainsString('public', (string) $sitemap->headers->get('Cache-Control'));
		self::assertSame(0, $proxyCalls);
	}

	public function testConsumerModeDelegatesToPublicPageApiProxy(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/robots')) {
				return new MockResponse(json_encode([
					'body' => "User-agent: *\nAllow: /\n",
					'contentType' => 'text/plain; charset=UTF-8',
				], JSON_THROW_ON_ERROR));
			}

			return new MockResponse(json_encode([
				'body' => '<urlset></urlset>',
				'contentType' => 'application/xml; charset=UTF-8',
			], JSON_THROW_ON_ERROR));
		});

		$controller = new PublicDiscoveryController($this->proxy($client), $this->generator(), 'consumer');

		$robots = $controller->robots(Request::create('https://example.test/robots.txt'));
		self::assertSame(200, $robots->getStatusCode());
		self::assertSame("User-agent: *\nAllow: /\n", $robots->getContent());

		$sitemap = $controller->sitemap(Request::create('https://example.test/sitemap.xml'));
		self::assertSame(200, $sitemap->getStatusCode());
		self::assertSame('<urlset></urlset>', $sitemap->getContent());
	}

	private function proxy(MockHttpClient $client): PublicPageApiProxy
	{
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');

		return new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);
	}

	private function generator(): PublicDiscoveryDocumentGenerator
	{
		$sites = $this->createStub(SiteRepository::class);
		$sites->method('getApplicationBySite')->willReturn(new Application());
		$pages = $this->createStub(PageRepository::class);
		$pages->method('findSitemapBatchForSite')->willReturn([]);
		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('getRepository')->willReturn($sites);
		$settings = new ContentOptimizationSettings();
		$normalizer = new PageSeoSettingsNormalizer();
		$policy = new ResponseHeaderPolicy();
		$store = new ResponseHeaderStore($policy);

		return new PublicDiscoveryDocumentGenerator(
			$sites,
			$pages,
			$settings,
			new EditorSettingsManager($em),
			new ResponseHeaderResolver($em, $store, $policy, 0),
			$normalizer,
			$store,
			$policy,
		);
	}
}
