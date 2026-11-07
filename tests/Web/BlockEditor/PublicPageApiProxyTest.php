<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class PublicPageApiProxyTest extends TestCase
{
	public function testRequestsTheSiteScopedPageWithAReadOnlyGuestCredential(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('https://api.example.test/api/public/render/pages/about?lang=en', $url);
			self::assertSame('www.example.test', self::header($options['headers'], 'X-SITE-HOST'));
			self::assertSame('https', self::header($options['headers'], 'X-SITE-SCHEME'));
			$guestToken = self::header($options['headers'], 'X-AUTH-TOKEN');
			self::assertNotNull($guestToken);
			self::assertNotNull((new GuestApiTokenManager('test-secret'))->decode($guestToken));

			return new MockResponse('{"html":"<main>remote page</main>"}');
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->page(Request::create('https://www.example.test/about?lang=en'), 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('<main>remote page</main>', $response->getContent());
	}

	public function testMapsAnUnavailablePageFromTheBusinessApi(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"missing"}', ['http_code' => 404]));
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->page(Request::create('https://www.example.test/missing'), 'missing');

		self::assertSame(404, $response->getStatusCode());
	}

	public function testAuthenticatedSessionIsExchangedForItsUserBoundApiToken(): void
	{
		$requests = 0;
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
			++$requests;
			if (1 === $requests) {
				self::assertSame('POST', $method);
				self::assertSame('https://api.example.test/api/auth/session-token', $url);
				self::assertSame('APPSESSID=session-id', self::header($options['headers'], 'Cookie'));
				self::assertSame('www.example.test', self::header($options['headers'], 'X-SITE-HOST'));

				return new MockResponse('{"token":"session-user-token"}');
			}

			self::assertSame('GET', $method);
			self::assertTrue(self::header($options['headers'], 'X-AUTH-TOKEN') === 'session-user-token');

			return new MockResponse('{"html":"<main>private preview</main>"}');
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);
		$request = Request::create('https://www.example.test/about');
		$session = $this->createStub(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
		$request->setSession($session);
		$request->cookies->set('APPSESSID', 'session-id');

		$response = $proxy->page($request, 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('<main>private preview</main>', $response->getContent());
		self::assertSame(2, $requests);
	}

	public function testAnonymousSessionFallsBackToItsSiteBoundGuestCredential(): void
	{
		$requests = 0;
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
			++$requests;
			if (1 === $requests) {
				self::assertSame('/api/auth/session-token', parse_url($url, PHP_URL_PATH));

				return new MockResponse('{"error":"Authenticated user session required."}', ['http_code' => 401]);
			}

			self::assertSame('GET', $method);
			$token = self::header($options['headers'], 'X-AUTH-TOKEN');
			self::assertNotNull($token);
			self::assertNotNull((new GuestApiTokenManager('test-secret'))->decode($token));

			return new MockResponse('{"html":"<main>public</main>"}');
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);
		$request = Request::create('https://www.example.test/about');
		$session = $this->createStub(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
		$request->setSession($session);
		$request->cookies->set('APPSESSID', 'anonymous-session');

		$response = $proxy->page($request, 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('<main>public</main>', $response->getContent());
		self::assertSame(2, $requests);
	}

	public function testRewritesAssetUrlsToTheStreamingSiteScopedApiRoute(): void
	{
		$token = str_repeat('c', 64);
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
			'html' => '<img src="/assets/'.$token.'/thumbnail" srcset="/assets/'.$token.'/small 1x, /assets/'.$token.'/large 2x">',
		], JSON_THROW_ON_ERROR)));
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));

		self::assertStringContainsString('https://api.example.test/api/public/sites/www.example.test/assets/'.$token.'/thumbnail', (string) $response->getContent());
		self::assertStringContainsString('https://api.example.test/api/public/sites/www.example.test/assets/'.$token.'/small 1x', (string) $response->getContent());
		self::assertStringContainsString('crossorigin="use-credentials"', (string) $response->getContent());
	}

	public function testRewritesInteractiveFormActionsToTheRemoteApiOrigin(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
			'html' => '<form action="/api/forms/db/signup?_site_host=www.example.test"><button>Continue</button></form>',
		], JSON_THROW_ON_ERROR)));
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));

		self::assertStringContainsString('https://api.example.test/api/forms/db/signup?_site_host=www.example.test', (string) $response->getContent());
	}

	/** @param array<array-key,mixed> $headers */
	private static function header(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (is_string($key) && 0 === strcasecmp($name, $key) && is_string($value)) {
				return $value;
			}
			if (is_string($value) && 1 === preg_match('/^'.preg_quote($name, '/').':\s*(.*?)$/iD', trim($value), $matches)) {
				return $matches[1];
			}
		}

		return null;
	}
}
