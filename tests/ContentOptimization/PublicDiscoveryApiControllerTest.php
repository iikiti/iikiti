<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\ContentOptimization;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\GuestApiUser;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Service\ResponseHeaderPolicy;
use iikiti\CMS\Service\ResponseHeaderResolver;
use iikiti\CMS\Service\ResponseHeaderStore;
use iikiti\CMS\Web\BlockEditor\EditorSettingsManager;
use iikiti\Extension\ContentOptimization\Controller\Page\PublicDiscoveryApiController;
use iikiti\Extension\ContentOptimization\Service\ContentOptimizationSettings;
use iikiti\Extension\ContentOptimization\Service\PageSeoSettingsNormalizer;
use iikiti\Extension\ContentOptimization\Service\PublicDiscoveryDocumentGenerator;
use iikiti\Extension\ContentOptimization\Service\SeoMetadataResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/ContentOptimizationSettings.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PageSeoSettingsNormalizer.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/SeoMetadataResolver.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Service/PublicDiscoveryDocumentGenerator.php';
require_once dirname(__DIR__, 2).'/plugins/iikiti-content-optimization/src/Controller/Page/PublicDiscoveryApiController.php';

final class PublicDiscoveryApiControllerTest extends TestCase
{
	protected function setUp(): void
	{
		$site = new Site();
		(new \ReflectionProperty(DbObject::class, 'id'))->setValue($site, 21);
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

	public function testGuestUserWithMatchingSiteHostReceivesDiscoveryDocuments(): void
	{
		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn(new GuestApiUser('example.test', 'guest:example.test'));
		$mfa = new MfaAuthenticationGate($security, $this->createStub(TokenStorageInterface::class));

		$controller = new PublicDiscoveryApiController($security, $mfa, $this->generator());

		$robotsRequest = Request::create('https://api.example.test/api/public/content-optimization/robots');
		$robotsRequest->headers->set('X-SITE-HOST', 'example.test');
		$robotsResponse = $controller->robots($robotsRequest);

		self::assertSame(200, $robotsResponse->getStatusCode());
		self::assertStringContainsString('public', (string) $robotsResponse->headers->get('Cache-Control'));
		self::assertStringContainsString('max-age=300', (string) $robotsResponse->headers->get('Cache-Control'));
		$robotsPayload = json_decode((string) $robotsResponse->getContent(), true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('text/plain; charset=UTF-8', $robotsPayload['contentType']);

		$sitemapRequest = Request::create('https://api.example.test/api/public/content-optimization/sitemap');
		$sitemapRequest->headers->set('X-SITE-HOST', 'EXAMPLE.TEST');
		$sitemapResponse = $controller->sitemap($sitemapRequest);
		self::assertSame(200, $sitemapResponse->getStatusCode());
		$sitemapPayload = json_decode((string) $sitemapResponse->getContent(), true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('application/xml; charset=UTF-8', $sitemapPayload['contentType']);
	}

	public function testRejectsAnonymousMismatchedGuestHostAndUnfinishedMfaUser(): void
	{
		$documents = $this->generator();
		$tokenStorage = $this->createStub(TokenStorageInterface::class);

		// Anonymous
		$anonSecurity = $this->createStub(Security::class);
		$anonSecurity->method('getUser')->willReturn(null);
		$anonController = new PublicDiscoveryApiController($anonSecurity, new MfaAuthenticationGate($anonSecurity, $tokenStorage), $documents);
		try {
			$anonController->robots(Request::create('/api/public/content-optimization/robots'));
			self::fail('Anonymous request must be denied.');
		} catch (AccessDeniedHttpException) {
		}

		// Mismatched guest host
		$guestSecurity = $this->createStub(Security::class);
		$guestSecurity->method('getUser')->willReturn(new GuestApiUser('tenant-a.example.test', 'guest:tenant-a.example.test'));
		$guestController = new PublicDiscoveryApiController($guestSecurity, new MfaAuthenticationGate($guestSecurity, $tokenStorage), $documents);
		$mismatchRequest = Request::create('/api/public/content-optimization/robots');
		$mismatchRequest->headers->set('X-SITE-HOST', 'tenant-b.example.test');
		try {
			$guestController->robots($mismatchRequest);
			self::fail('Mismatched guest host must be denied.');
		} catch (AccessDeniedHttpException) {
		}

		// Unfinished MFA user
		$userSecurity = $this->createStub(Security::class);
		$userSecurity->method('getUser')->willReturn(new User());
		$userSecurity->method('isGranted')->willReturn(false);
		$mfa = new MfaAuthenticationGate($userSecurity, $tokenStorage);
		$mfaController = new PublicDiscoveryApiController($userSecurity, $mfa, $documents);
		$this->expectException(AccessDeniedHttpException::class);
		$mfaController->robots(Request::create('/api/public/content-optimization/robots'));
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
