<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Page;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Controller\Page\HomeController;
use iikiti\CMS\Controller\Page\ObjectController;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Repository\Object\PageRepository;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Security\MfaAuthenticationGate;
use iikiti\CMS\Web\BlockEditor\PageRendering;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;

final class PublicPageControllerSiteIndependentTest extends TestCase
{
	public function testHomeControllerProxiesViaApiBeforeCheckingSecurityWhenEnabled(): void
	{
		$proxyRequests = 0;
		$proxy = $this->proxy($proxyRequests);
		$security = $this->createMock(Security::class);
		$security->expects(self::never())->method('getUser');
		$mfaGate = new MfaAuthenticationGate($security, $this->createStub(TokenStorageInterface::class));
		$container = $this->createStub(ContainerInterface::class);
		$pageRendering = static function (): PageRendering {
			self::fail('Local PageRendering should not be invoked when publicPageRenderViaApi is enabled.');
		};

		$controller = new HomeController(
			$security,
			$container,
			$pageRendering,
			$proxy,
			$mfaGate,
			'api',
			true,
		);

		$response = $controller->index(Request::create('https://example.test/'));

		self::assertGreaterThan(0, $proxyRequests);
		self::assertSame(404, $response->getStatusCode());
	}

	public function testObjectControllerProxiesViaApiBeforeCheckingSecurityWhenEnabled(): void
	{
		$proxyRequests = 0;
		$proxy = $this->proxy($proxyRequests);
		$security = $this->createMock(Security::class);
		$security->expects(self::never())->method('getUser');
		$mfaGate = new MfaAuthenticationGate($security, $this->createStub(TokenStorageInterface::class));
		$container = $this->createStub(ContainerInterface::class);
		$pages = $this->createMock(PageRepository::class);
		$pages->expects(self::never())->method('findOneByProperty');
		$pageRendering = static function (): PageRendering {
			self::fail('Local PageRendering should not be invoked when publicPageRenderViaApi is enabled.');
		};

		$controller = new ObjectController(
			$security,
			$container,
			$pageRendering,
			$pages,
			$proxy,
			$mfaGate,
			'api',
			true,
		);

		$response = $controller->page(Request::create('https://example.test/about'), 'about');

		self::assertGreaterThan(0, $proxyRequests);
		self::assertSame(404, $response->getStatusCode());
	}

	public function testHomeAndObjectControllersEnforceMfaGateWhenDirectRenderingIsUsed(): void
	{
		$proxyRequests = 0;
		$proxy = $this->proxy($proxyRequests);
		$security = $this->createMock(Security::class);
		$security->expects(self::exactly(2))->method('getUser')->willReturn(new User());
		$security->method('isGranted')->with('IS_AUTHENTICATED_FULLY')->willReturn(false);
		$mfaGate = new MfaAuthenticationGate($security, $this->createStub(TokenStorageInterface::class));
		$container = $this->createStub(ContainerInterface::class);
		$pages = $this->createMock(PageRepository::class);
		$pages->expects(self::never())->method('findOneByProperty');
		$pageRendering = static function (): PageRendering {
			self::fail('Local PageRendering should not be invoked while MFA challenge is pending.');
		};

		$homeController = new HomeController($security, $container, $pageRendering, $proxy, $mfaGate, 'api', false);
		$objectController = new ObjectController($security, $container, $pageRendering, $pages, $proxy, $mfaGate, 'api', false);

		$homeResponse = $homeController->index(Request::create('https://example.test/'));
		$pageResponse = $objectController->page(Request::create('https://example.test/about'), 'about');

		self::assertInstanceOf(RedirectResponse::class, $homeResponse);
		self::assertSame('/api/auth/mfa/challenge', $homeResponse->getTargetUrl());
		self::assertInstanceOf(RedirectResponse::class, $pageResponse);
		self::assertSame('/api/auth/mfa/challenge', $pageResponse->getTargetUrl());
		self::assertSame(0, $proxyRequests);
	}

	private function proxy(int &$requestCount): PublicPageApiProxy
	{
		$client = new MockHttpClient(static function () use (&$requestCount): MockResponse {
			++$requestCount;

			return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');

		return new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);
	}
}
