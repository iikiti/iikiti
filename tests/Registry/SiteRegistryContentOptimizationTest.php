<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Registry\RouteContextClassifier;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\Extension\ContentOptimization\RouteContext\ContentOptimizationRouteContextContributor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class SiteRegistryContentOptimizationTest extends TestCase
{
	protected function tearDown(): void
	{
		(new \ReflectionProperty(SiteRegistry::class, 'initialized'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'populated'))->setValue(null, false);
		(new \ReflectionProperty(SiteRegistry::class, 'siteStack'))->setValue(null, new \SplStack());
		parent::tearDown();
	}

	public function testSiteSettingsRoutesResolveTheSelectedXSiteHostWhenAuthenticatedWithValidApiToken(): void
	{
		$site = new Site();
		$siteRepository = $this->createMock(SiteRepository::class);
		$siteRepository->expects(self::once())->method('findByDomain')->with('selected.example.test')->willReturn([$site]);
		$token = $this->createStub(ApiToken::class);
		$token->method('isExpired')->willReturn(false);
		$tokenRepository = $this->createMock(ApiTokenRepository::class);
		$tokenRepository->expects(self::once())->method('findOneBy')->with(['token' => ApiToken::TOKEN_PREFIX.'valid-admin-token'])->willReturn($token);
		$entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ApiToken::class => $tokenRepository,
				Site::class => $siteRepository,
				default => null,
			},
		);
		$registry = $this->createStub(ManagerRegistry::class);
		$registry->method('getManager')->willReturn($entityManager);
		$request = Request::create('https://api.example.test/api/site/42/content-optimization/settings');
		$request->attributes->set('_route', 'api_site_content_optimization_settings_get');
		$request->headers->set('X-SITE-HOST', 'selected.example.test');
		$request->headers->set('X-AUTH-TOKEN', ApiToken::TOKEN_PREFIX.'valid-admin-token');
		$requestStack = new RequestStack();
		$requestStack->push($request);
		$classifier = new RouteContextClassifier([new ContentOptimizationRouteContextContributor()]);
		$siteRegistry = new SiteRegistry($requestStack, $registry, new GuestApiTokenManager('test-secret'), 'api', false, $classifier);

		$siteRegistry->initializeForRequest();

		self::assertSame($site, SiteRegistry::getCurrent());
	}

	public function testSiteSettingsRoutesIgnoreXSiteHostWithoutVerifiedCredentials(): void
	{
		$site = new Site();
		$siteRepository = $this->createMock(SiteRepository::class);
		$siteRepository->expects(self::once())->method('findByDomain')->with('api.example.test')->willReturn([$site]);
		$tokenRepository = $this->createStub(ApiTokenRepository::class);
		$tokenRepository->method('findOneBy')->willReturn(null);
		$entityManager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
		$entityManager->method('getRepository')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ApiToken::class => $tokenRepository,
				Site::class => $siteRepository,
				default => null,
			},
		);
		$registry = $this->createStub(ManagerRegistry::class);
		$registry->method('getManager')->willReturn($entityManager);

		$request = Request::create('https://api.example.test/api/site/42/content-optimization/settings');
		$request->attributes->set('_route', 'api_site_content_optimization_settings_get');
		$request->headers->set('X-SITE-HOST', 'victim-tenant.example.test');
		$request->headers->set('X-AUTH-TOKEN', ApiToken::TOKEN_PREFIX.'forged-token');
		$requestStack = new RequestStack();
		$requestStack->push($request);
		$classifier = new RouteContextClassifier([new ContentOptimizationRouteContextContributor()]);
		$siteRegistry = new SiteRegistry($requestStack, $registry, new GuestApiTokenManager('test-secret'), 'api', false, $classifier);

		$siteRegistry->initializeForRequest();

		self::assertSame($site, SiteRegistry::getCurrent());
	}

	public function testPublicRenderRouteRejectsMissingOrMismatchedGuestToken(): void
	{
		$registry = $this->createMock(ManagerRegistry::class);
		$registry->expects(self::never())->method('getManager');
		$guestManager = new GuestApiTokenManager('test-secret');
		$request = Request::create('https://api.example.test/api/public/render/page');
		$request->attributes->set('_route', 'api_public_render_page');
		$request->headers->set('X-SITE-HOST', 'tenant-a.example.test');
		$request->headers->set('X-AUTH-TOKEN', $guestManager->issue('tenant-b.example.test'));
		$requestStack = new RequestStack();
		$requestStack->push($request);
		$classifier = new RouteContextClassifier([new ContentOptimizationRouteContextContributor()]);
		$siteRegistry = new SiteRegistry($requestStack, $registry, $guestManager, 'api', false, $classifier);

		$this->expectException(AccessDeniedHttpException::class);
		$siteRegistry->initializeForRequest();
	}

	public function testApplicationSettingsRouteDoesNotRequireASelectedSite(): void
	{
		$registry = $this->createMock(ManagerRegistry::class);
		$registry->expects(self::never())->method('getManager');
		$request = Request::create('https://api.example.test/api/admin/applications/7/content-optimization/settings');
		$request->attributes->set('_route', 'api_admin_application_content_optimization_settings_get');
		$requestStack = new RequestStack();
		$requestStack->push($request);
		$classifier = new RouteContextClassifier([new ContentOptimizationRouteContextContributor()]);
		$siteRegistry = new SiteRegistry($requestStack, $registry, new GuestApiTokenManager('test-secret'), 'api', false, $classifier);

		$siteRegistry->initializeForRequest();

		self::assertFalse(SiteRegistry::hasCurrent());
	}
}
