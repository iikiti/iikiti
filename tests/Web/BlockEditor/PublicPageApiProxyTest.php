<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Tests\Support\Async\ControllableHttpClient;
use iikiti\CMS\Tests\Support\Async\ControllableResponse;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use iikiti\CMS\Web\Template\HttpErrorPageRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PublicPageApiProxyTest extends TestCase
{
	private bool $sessionSaved = false;

	public function testRequestsTheSiteScopedPageWithAReadOnlyGuestCredential(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('www.example.test', self::header($options['headers'], 'X-SITE-HOST'));
			self::assertSame('https', self::header($options['headers'], 'X-SITE-SCHEME'));
			$guestToken = self::header($options['headers'], 'X-AUTH-TOKEN');
			self::assertNotNull($guestToken);
			self::assertNotNull((new GuestApiTokenManager('test-secret'))->decode($guestToken));
			if ('/api/public/render/pages/about/manifest' === parse_url($url, PHP_URL_PATH)) {
				self::assertSame('en', self::query($url)['lang'] ?? null);

				return new MockResponse(self::manifest());
			}

			self::assertSame('/api/public/render/pages/about/fragments/region', parse_url($url, PHP_URL_PATH));
			self::assertSame('@main', self::query($url)['_iikiti_fragment_id'] ?? null);
			self::assertArrayNotHasKey('_iikiti_render_revision', self::query($url));

			return new MockResponse(self::fragment('<p>remote page</p>'));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$request = Request::create('https://www.example.test/about?lang=en');
		$response = $proxy->page($request, 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertStringContainsString('<p>remote page</p>', (string) $response->getContent());
		self::assertSame(
			['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
			$request->attributes->get(\iikiti\CMS\Filters\InlineAssetMinificationOptions::REQUEST_ATTRIBUTE),
		);
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
			errorPages: $this->errorPageRenderer(),
		);

		$response = $proxy->page(Request::create('https://www.example.test/missing'), 'missing');

		self::assertSame(404, $response->getStatusCode());
		self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
		self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
		self::assertStringContainsString('Page not found', (string) $response->getContent());
	}

	public function testPreservesRateLimitStatusAndRetryAfterHeaderOnErrorPage(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"message":"rate limited"}', [
			'http_code' => 429,
			'response_headers' => ['retry-after' => '60'],
		]));
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
			errorPages: $this->errorPageRenderer(),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));

		self::assertSame(429, $response->getStatusCode());
		self::assertSame('60', $response->headers->get('Retry-After'));
		self::assertStringContainsString('Please slow down', (string) $response->getContent());
	}

	public function testAuthenticatedSessionIsExchangedForItsUserBoundApiToken(): void
	{
		$requests = 0;
		$this->sessionSaved = false;
		$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
			++$requests;
			if (1 === $requests) {
				self::assertTrue($this->sessionSaved, 'The current session must be saved before the nested token request.');
				self::assertSame('POST', $method);
				self::assertSame('https://api.example.test/api/auth/session-token', $url);
				self::assertSame('APPSESSID=session-id', self::header($options['headers'], 'Cookie'));
				self::assertSame('www.example.test', self::header($options['headers'], 'X-SITE-HOST'));

				return new MockResponse('{"token":"session-user-token"}');
			}

			self::assertSame('GET', $method);
			self::assertTrue(self::header($options['headers'], 'X-AUTH-TOKEN') === 'session-user-token');
			if (2 === $requests) {
				self::assertSame('/api/public/render/pages/about/manifest', parse_url($url, PHP_URL_PATH));

				return new MockResponse(self::manifest());
			}

			self::assertSame('/api/public/render/pages/about/fragments/region', parse_url($url, PHP_URL_PATH));
			self::assertSame('@main', self::query($url)['_iikiti_fragment_id'] ?? null);

			return new MockResponse(self::fragment('<p>private preview</p>'));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);
		$request = Request::create('https://www.example.test/about');
		$session = $this->createMock(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
		$session->expects(self::atLeastOnce())
			->method('save')
			->willReturnCallback(function (): void {
				$this->sessionSaved = true;
			});
		$request->setSession($session);
		$request->cookies->set('APPSESSID', 'session-id');

		$response = $proxy->page($request, 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<p>private preview</p>', (string) $response->getContent());
		self::assertSame(3, $requests);
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
			if (2 === $requests) {
				self::assertSame('/api/public/render/pages/about/manifest', parse_url($url, PHP_URL_PATH));

				return new MockResponse(self::manifest());
			}

			self::assertSame('/api/public/render/pages/about/fragments/region', parse_url($url, PHP_URL_PATH));
			self::assertSame('@main', self::query($url)['_iikiti_fragment_id'] ?? null);

			return new MockResponse(self::fragment('<p>public</p>'));
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
		self::assertStringContainsString('<p>public</p>', (string) $response->getContent());
		self::assertSame(3, $requests);
	}

	public function testRewritesAssetUrlsToTheStreamingSiteScopedApiRoute(): void
	{
		$token = str_repeat('c', 64);
		$client = new MockHttpClient(static function (string $method, string $url) use ($token): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse(self::fragment('<img src="/assets/'.$token.'/thumbnail" srcset="/assets/'.$token.'/small 1x, /assets/'.$token.'/large 2x">'));
		});
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

	public function testRewritesSelfClosingImgAssetTagsWithoutCorruptingMarkup(): void
	{
		$token = str_repeat('e', 64);
		$client = new MockHttpClient(static function (string $method, string $url) use ($token): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse(self::fragment('<img src="/assets/'.$token.'/preview" alt="Preview" />'));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$html = (string) $response->getContent();

		self::assertStringContainsString('crossorigin="use-credentials"', $html);
		self::assertStringNotContainsString('/ crossorigin', $html);
	}

	public function testAppliesCrossOriginViaDomWhenAttributesContainAngleBracketsAndVideoSources(): void
	{
		$token = str_repeat('f', 64);
		$client = new MockHttpClient(static function (string $method, string $url) use ($token): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse(self::fragment(
				'<img alt="a > b" src="/assets/'.$token.'/preview">'
				.'<video><source src="/assets/'.$token.'/clip"></video>',
			));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$html = (string) $response->getContent();

		self::assertSame(2, substr_count($html, 'crossorigin="use-credentials"'));
		self::assertStringContainsString('alt="a > b"', $html);
		self::assertStringNotContainsString('crossorigin="use-credentials" b"', $html);
	}

	public function testFragmentEndpointRefreshesCachedTokenOnceAndStopsOnDoubleUnauthorized(): void
	{
		/** @var array<string,mixed> $sessionData */
		$sessionData = [];
		$session = $this->createStub(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
		$session->method('get')->willReturnCallback(static fn (string $key): mixed => $sessionData[$key] ?? null);
		$session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$sessionData): void {
			$sessionData[$key] = $value;
		});
		$session->method('remove')->willReturnCallback(static function (string $key) use (&$sessionData): mixed {
			$old = $sessionData[$key] ?? null;
			unset($sessionData[$key]);

			return $old;
		});

		$state = new class {
			public int $exchanges = 0;
			public bool $alwaysUnauthorized = false;
		};
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use ($state): MockResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if ('/api/auth/session-token' === $path) {
				++$state->exchanges;

				return new MockResponse(json_encode(['token' => 'frag-token-'.$state->exchanges], JSON_THROW_ON_ERROR));
			}
			$token = self::header($options['headers'], 'X-AUTH-TOKEN');
			if ($state->alwaysUnauthorized || 'stale-cached-token' === $token) {
				return new MockResponse('{"message":"unauthorized"}', ['http_code' => 401]);
			}

			return new MockResponse(json_encode(['html' => '<aside>'.$token.'</aside>', 'styles' => []], JSON_THROW_ON_ERROR));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$request = Request::create('https://www.example.test/_iikiti/fragments/sample.card');
		$request->setSession($session);
		$request->cookies->set('APPSESSID', 'frag-sess');
		$sessionData[ApiSessionTokenProvider::SESSION_CACHE_KEY] = [
			'www.example.test:'.hash('sha256', 'frag-sess') => [
				'token' => 'stale-cached-token',
				'expires_at' => time() + 60,
			],
		];

		$recovered = $proxy->fragment($request, 'sample.card');
		self::assertSame(200, $recovered->getStatusCode());
		self::assertSame(1, $state->exchanges);
		self::assertStringContainsString('frag-token-1', (string) $recovered->getContent());

		// Double 401: cached token fails, refresh succeeds, refreshed token also 401 -> stops with 502 (no retry loop).
		$state->alwaysUnauthorized = true;
		$failed = $proxy->fragment($request, 'sample.card');
		self::assertSame(502, $failed->getStatusCode());
		self::assertSame(2, $state->exchanges);
	}

	public function testComposesManifestWhenMainRegionIsInlinedAsTopLevelWidgetSlots(): void
	{
		$widgetA = str_repeat('1', 64);
		$widgetB = str_repeat('2', 64);
		$slotA = str_repeat('a', 32);
		$slotB = str_repeat('b', 32);
		$manifestJson = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'
				.'<template data-iikiti-widget-slot="'.$slotA.'"></template>'
				.'<template data-iikiti-widget-slot="'.$slotB.'"></template>'
				.'</main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => str_repeat('a', 64),
			'fragments' => [
				['kind' => 'widget', 'id' => $widgetA, 'role' => 'widget', 'slot' => $slotA, 'depth' => 1],
				['kind' => 'widget', 'id' => $widgetB, 'role' => 'widget', 'slot' => $slotB, 'depth' => 1],
			],
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$client = new MockHttpClient(static function (string $method, string $url) use ($manifestJson, $widgetA, $widgetB): MockResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return new MockResponse($manifestJson);
			}
			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			if ('@main' === $id) {
				// Speculative @main request still succeeds on the API, though manifest has no main region descriptor.
				return new MockResponse(self::fragment('<template data-iikiti-widget-slot="unused"></template>'));
			}
			if ($widgetA === $id) {
				return new MockResponse(self::fragment('<section>Hero</section>', [], $widgetA));
			}
			self::assertSame($widgetB, $id);

			return new MockResponse(self::fragment('<section>Features</section>', [], $widgetB));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$html = (string) $response->getContent();

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<section>Hero</section><section>Features</section>', $html);
		self::assertStringNotContainsString('data-iikiti-widget-slot', $html);
	}

	public function testCachedSessionTokenSkipsWaveZeroAndRefreshesOnUnauthorized(): void
	{
		/** @var array<string,mixed> $sessionData */
		$sessionData = [];
		$session = $this->createStub(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
		$session->method('get')->willReturnCallback(static function (string $key) use (&$sessionData): mixed {
			return $sessionData[$key] ?? null;
		});
		$session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$sessionData): void {
			$sessionData[$key] = $value;
		});
		$session->method('remove')->willReturnCallback(static function (string $key) use (&$sessionData): mixed {
			$old = $sessionData[$key] ?? null;
			unset($sessionData[$key]);

			return $old;
		});

		$tokenExchanges = 0;
		$state = new class {
			public bool $rejectFirstCachedToken = false;
		};
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$tokenExchanges, $state): MockResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if ('/api/auth/session-token' === $path) {
				++$tokenExchanges;

				return new MockResponse(json_encode(['token' => 'user-token-'.$tokenExchanges], JSON_THROW_ON_ERROR));
			}
			$token = self::header($options['headers'], 'X-AUTH-TOKEN');
			if ($state->rejectFirstCachedToken && 'user-token-1' === $token) {
				return new MockResponse('{"message":"expired"}', ['http_code' => 401]);
			}
			if (str_ends_with($path, '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse(self::fragment('<p>token='.$token.'</p>'));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$request = Request::create('https://www.example.test/');
		$request->setSession($session);
		$request->cookies->set('APPSESSID', 'sess-123');

		// 1st request exchanges session token once and caches it.
		$first = $proxy->home($request);
		self::assertSame(200, $first->getStatusCode());
		self::assertSame(1, $tokenExchanges);
		self::assertStringContainsString('token=user-token-1', (string) $first->getContent());

		// 2nd request reuses the cached session token without calling /api/auth/session-token.
		$second = $proxy->home($request);
		self::assertSame(200, $second->getStatusCode());
		self::assertSame(1, $tokenExchanges);
		self::assertStringContainsString('token=user-token-1', (string) $second->getContent());

		// 3rd request simulates a revoked token -> 401 invalidates cache and refreshes automatically.
		$state->rejectFirstCachedToken = true;
		$third = $proxy->home($request);
		self::assertSame(200, $third->getStatusCode());
		self::assertSame(2, $tokenExchanges);
		self::assertStringContainsString('token=user-token-2', (string) $third->getContent());
	}

	public function testRewritesInteractiveFormActionsToTheRemoteApiOrigin(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse(self::fragment('<form action="/api/forms/db/signup?_site_host=www.example.test"><button>Continue</button></form>'));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
			routeClassifier: new \iikiti\CMS\Registry\RouteContextClassifier([new \iikiti\Extension\Forms\Routing\FormRouteContextContributor()]),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));

		self::assertStringContainsString('https://api.example.test/api/forms/db/signup?_site_host=www.example.test', (string) $response->getContent());
	}

	public function testFetchesAuthorizedFragmentsInFiberGroupsAndComposesOneDomResponse(): void
	{
		$descriptors = [
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)],
			['kind' => 'shell', 'id' => '17', 'role' => 'header', 'slot' => str_repeat('b', 32)],
		];
		$client = new MockHttpClient(static function (string $method, string $url) use ($descriptors): MockResponse {
			$path = parse_url($url, PHP_URL_PATH);
			if (str_ends_with((string) $path, '/manifest')) {
				return new MockResponse(self::manifest($descriptors));
			}

			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			return new MockResponse(self::fragment('@main' === $id ? '<p>Main region</p>' : '<header>Site header</header>', [], '@main' === $id ? 'main' : (string) $id));
		});
		$profiler = new AsyncResponseDataCollector();
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test', $profiler);
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client, $profiler),
			new GuestApiTokenManager('test-secret'),
			$profiler,
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$profiler->collect(Request::create('https://www.example.test/'), $response);

		self::assertInstanceOf(DomHtmlResponse::class, $response);
		$html = (string) $response->getContent();
		self::assertStringContainsString('<p>Main region</p>', $html);
		self::assertStringContainsString('<header>Site header</header>', $html);
		self::assertStringContainsString('<title>Manifest SEO title</title>', $html);
		self::assertStringContainsString('rel="canonical" href="https://www.example.test/"', $html);
		self::assertCount(3, $profiler->getAsyncRequests());
		self::assertCount(3, $profiler->getApiSubrequests());
		self::assertCount(2, $profiler->getFiberGroups());
		self::assertSame(2, $profiler->getFiberGroups()[0]['tasks']);
		self::assertSame(1, $profiler->getFiberGroups()[1]['tasks']);
		self::assertSame(['format' => 'html-dom', 'fragments' => 2], $profiler->getCompositions()[0]);
		self::assertTrue($profiler->isDomDocumentReused());
	}

	public function testFragmentRevisionConflictPreventsPartialPageResponse(): void
	{
		$requests = 0;
		$client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
			++$requests;
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest());
			}

			return new MockResponse('{"message":"page changed"}', ['http_code' => 409]);
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));

		self::assertSame(409, $response->getStatusCode());
		self::assertNotInstanceOf(DomHtmlResponse::class, $response);
		self::assertSame(2, $requests);
	}

	public function testComposesNestedWidgetFragmentsInParentBeforeChildOrder(): void
	{
		$parentId = str_repeat('c', 64);
		$childId = str_repeat('d', 64);
		$descriptors = [
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)],
			['kind' => 'widget', 'id' => $parentId, 'role' => 'widget', 'slot' => str_repeat('b', 32), 'depth' => 1],
			['kind' => 'widget', 'id' => $childId, 'role' => 'widget', 'slot' => str_repeat('c', 32), 'depth' => 2],
		];
		$client = new MockHttpClient(static function (string $method, string $url) use ($descriptors, $parentId, $childId): MockResponse {
			$path = parse_url($url, PHP_URL_PATH);
			if (str_ends_with((string) $path, '/manifest')) {
				return new MockResponse(self::manifest($descriptors));
			}

			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			if ('@main' === $id) {
				return new MockResponse(self::fragment('<template data-iikiti-widget-slot="'.str_repeat('b', 32).'"></template>'));
			}
			self::assertSame('/api/public/render/pages/about/fragments/widget', $path);
			if ($parentId === $id) {
				return new MockResponse(self::fragment('<section><template data-iikiti-widget-slot="'.str_repeat('c', 32).'"></template></section>', [], $parentId));
			}

			self::assertSame($childId, $id);
			return new MockResponse(self::fragment('<p>Nested child</p>', [], $childId));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->page(Request::create('https://www.example.test/about'), 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<section><p>Nested child</p></section>', (string) $response->getContent());
		self::assertStringNotContainsString('data-iikiti-widget-slot', (string) $response->getContent());
	}

	public function testEditorStylesFromFragmentsMergeByStyleIdAndRewriteAssetUrls(): void
	{
		$assetToken = str_repeat('d', 64);
		$descriptors = [
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)],
			['kind' => 'shell', 'id' => '17', 'role' => 'header', 'slot' => str_repeat('b', 32)],
		];
		$client = new MockHttpClient(static function (string $method, string $url) use ($descriptors, $assetToken): MockResponse {
			if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/manifest')) {
				return new MockResponse(self::manifest($descriptors, [
					['id' => 'shared-style', 'css' => '.page{color:red}'],
				], true));
			}

			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			$css = '@main' === $id ? '.main{color:blue}' : '.shell{background-image:url("/assets/'.$assetToken.'/original")}';

			return new MockResponse(self::fragment('@main' === $id ? '<p>Main</p>' : '<header>Header</header>', [
				['id' => 'shared-style', 'css' => $css],
			], '@main' === $id ? 'main' : (string) $id));
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$html = (string) $response->getContent();

		self::assertSame(1, substr_count($html, 'id="shared-style"'));
		self::assertStringContainsString('.page{color:red}', $html);
		self::assertStringContainsString('.main{color:blue}', $html);
		self::assertStringContainsString('.shell{background-image:url("https://api.example.test/api/public/sites/www.example.test/assets/'.$assetToken.'/original")}', $html);
	}

	public function testWaveOneCompletesMainRegionWhileManifestIsBlockedAndViceVersa(): void
	{
		// Direction 1: manifest held until @main completes.
		$mainDone = false;
		$client1 = new ControllableHttpClient();
		$client1->whenUrlContains('/manifest', static function () use (&$mainDone): ControllableResponse {
			return ControllableResponse::ok(self::manifest(), key: 'manifest')
				->holdUntil(static function () use (&$mainDone): bool {
					return $mainDone;
				});
		});
		$client1->whenUrlContains('/fragments/region', static function () use (&$mainDone): ControllableResponse {
			return ControllableResponse::ok(self::fragment('<p>Main First</p>'), key: 'main')
				->onComplete(static function () use (&$mainDone): void {
					$mainDone = true;
				});
		});
		$api1 = new BusinessApiClient($client1, 'https://api.example.test', 'test');
		$proxy1 = new PublicPageApiProxy($api1, new ApiSessionTokenProvider($api1), new FiberTaskRunner($client1), new GuestApiTokenManager('test-secret'));

		$response1 = $proxy1->home(Request::create('https://www.example.test/'));
		self::assertSame(200, $response1->getStatusCode());
		self::assertStringContainsString('<p>Main First</p>', (string) $response1->getContent());
		self::assertLessThan(
			array_search('complete:manifest', $client1->getEvents(), true),
			array_search('complete:main', $client1->getEvents(), true),
		);

		// Direction 2: @main held until manifest completes.
		$manifestDone = false;
		$client2 = new ControllableHttpClient();
		$client2->whenUrlContains('/manifest', static function () use (&$manifestDone): ControllableResponse {
			return ControllableResponse::ok(self::manifest(), key: 'manifest')
				->onComplete(static function () use (&$manifestDone): void {
					$manifestDone = true;
				});
		});
		$client2->whenUrlContains('/fragments/region', static function () use (&$manifestDone): ControllableResponse {
			return ControllableResponse::ok(self::fragment('<p>Main Second</p>'), key: 'main')
				->holdUntil(static function () use (&$manifestDone): bool {
					return $manifestDone;
				});
		});
		$api2 = new BusinessApiClient($client2, 'https://api.example.test', 'test');
		$proxy2 = new PublicPageApiProxy($api2, new ApiSessionTokenProvider($api2), new FiberTaskRunner($client2), new GuestApiTokenManager('test-secret'));

		$response2 = $proxy2->home(Request::create('https://www.example.test/'));
		self::assertSame(200, $response2->getStatusCode());
		self::assertStringContainsString('<p>Main Second</p>', (string) $response2->getContent());
		self::assertLessThan(
			array_search('complete:main', $client2->getEvents(), true),
			array_search('complete:manifest', $client2->getEvents(), true),
		);
	}

	public function testWaveTwoRendersSiblingWidgetsShellsAndProvidersWhileOneWidgetIsBlocked(): void
	{
		$widgetA = str_repeat('1', 64);
		$widgetB = str_repeat('2', 64);
		$slotA = str_repeat('a', 32);
		$slotB = str_repeat('b', 32);
		$slotShell = str_repeat('c', 32);
		$slotProvider = str_repeat('d', 32);
		$manifestJson = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body>'
				.'<header><template data-iikiti-shell-slot="'.$slotShell.'"></template></header>'
				.'<main><template data-iikiti-widget-slot="'.$slotA.'"></template><template data-iikiti-widget-slot="'.$slotB.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotProvider.'"></template></main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => str_repeat('a', 64),
			'fragments' => [
				['kind' => 'shell', 'id' => '17', 'role' => 'header', 'slot' => $slotShell],
				['kind' => 'widget', 'id' => $widgetA, 'role' => 'widget', 'slot' => $slotA, 'depth' => 1],
				['kind' => 'widget', 'id' => $widgetB, 'role' => 'widget', 'slot' => $slotB, 'depth' => 1],
				['kind' => 'provider', 'id' => 'sample.card', 'role' => 'provider', 'slot' => $slotProvider, 'depth' => 0, 'key' => 'card', 'dependsOn' => [], 'params' => []],
			],
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$completedSiblings = 0;
		$client = new ControllableHttpClient(static function (string $method, string $url) use ($manifestJson, $widgetA, $widgetB, &$completedSiblings): ControllableResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return ControllableResponse::ok($manifestJson, key: 'manifest');
			}
			if (str_contains($path, '/providers/sample.card')) {
				return ControllableResponse::ok(json_encode(['html' => '<aside>Provider Card</aside>', 'styles' => []], JSON_THROW_ON_ERROR), key: 'provider')
					->onComplete(static function () use (&$completedSiblings): void {
						++$completedSiblings;
					});
			}
			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			if ('@main' === $id) {
				return ControllableResponse::ok(self::fragment(''), key: 'speculative-main');
			}
			if ($widgetA === $id) {
				return ControllableResponse::ok(self::fragment('<section>Slow Widget A</section>', [], $widgetA), key: 'widget-a')
					->holdUntil(static function () use (&$completedSiblings): bool {
						return $completedSiblings >= 3;
					});
			}
			if ($widgetB === $id) {
				return ControllableResponse::ok(self::fragment('<section>Fast Widget B</section>', [], $widgetB), key: 'widget-b')
					->onComplete(static function () use (&$completedSiblings): void {
						++$completedSiblings;
					});
			}

			return ControllableResponse::ok(self::fragment('<nav>Header Shell</nav>', [], '17'), key: 'shell-17')
				->onComplete(static function () use (&$completedSiblings): void {
					++$completedSiblings;
				});
		});

		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy($api, new ApiSessionTokenProvider($api), new FiberTaskRunner($client), new GuestApiTokenManager('test-secret'));

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$html = (string) $response->getContent();
		$events = $client->getEvents();

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<nav>Header Shell</nav>', $html);
		self::assertStringContainsString('<section>Slow Widget A</section><section>Fast Widget B</section><aside>Provider Card</aside>', $html);
		self::assertLessThan(array_search('complete:widget-a', $events, true), array_search('complete:widget-b', $events, true));
		self::assertLessThan(array_search('complete:widget-a', $events, true), array_search('complete:shell-17', $events, true));
		self::assertLessThan(array_search('complete:widget-a', $events, true), array_search('complete:provider', $events, true));
	}

	public function testNestedChildWidgetCompletesWhileParentWidgetIsBlockedAndStillComposesParentBeforeChild(): void
	{
		$parentId = str_repeat('c', 64);
		$childId = str_repeat('d', 64);
		$descriptors = [
			['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)],
			['kind' => 'widget', 'id' => $parentId, 'role' => 'widget', 'slot' => str_repeat('b', 32), 'depth' => 1],
			['kind' => 'widget', 'id' => $childId, 'role' => 'widget', 'slot' => str_repeat('c', 32), 'depth' => 2],
		];

		$childDone = false;
		$client = new ControllableHttpClient(static function (string $method, string $url) use ($descriptors, $parentId, $childId, &$childDone): ControllableResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return ControllableResponse::ok(self::manifest($descriptors), key: 'manifest');
			}
			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			if ('@main' === $id) {
				return ControllableResponse::ok(self::fragment('<template data-iikiti-widget-slot="'.str_repeat('b', 32).'"></template>'), key: 'main');
			}
			if ($parentId === $id) {
				return ControllableResponse::ok(
					self::fragment('<section class="parent"><template data-iikiti-widget-slot="'.str_repeat('c', 32).'"></template></section>', [], $parentId),
					key: 'parent',
				)->holdUntil(static function () use (&$childDone): bool {
					return $childDone;
				});
			}
			return ControllableResponse::ok(self::fragment('<p class="child">Child Ready Early</p>', [], $childId), key: 'child')
				->onComplete(static function () use (&$childDone): void {
					$childDone = true;
				});
		});

		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy($api, new ApiSessionTokenProvider($api), new FiberTaskRunner($client), new GuestApiTokenManager('test-secret'));

		$response = $proxy->page(Request::create('https://www.example.test/about'), 'about');
		$html = (string) $response->getContent();

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<section class="parent"><p class="child">Child Ready Early</p></section>', $html);
		self::assertStringNotContainsString('data-iikiti-widget-slot', $html);
		self::assertLessThan(
			array_search('complete:parent', $client->getEvents(), true),
			array_search('complete:child', $client->getEvents(), true),
		);
	}

	public function testProviderDependsOnChainProceedsEagerlyWhileUnrelatedWidgetIsBlocked(): void
	{
		$slowWidgetId = str_repeat('5', 64);
		$slotWidget = str_repeat('a', 32);
		$slotA = str_repeat('b', 32);
		$slotB = str_repeat('c', 32);
		$manifestJson = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'
				.'<template data-iikiti-widget-slot="'.$slotWidget.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotA.'"></template>'
				.'<template data-iikiti-provider-slot="'.$slotB.'"></template>'
				.'</main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => str_repeat('a', 64),
			'fragments' => [
				['kind' => 'widget', 'id' => $slowWidgetId, 'role' => 'widget', 'slot' => $slotWidget, 'depth' => 1],
				['kind' => 'provider', 'id' => 'sample.first', 'role' => 'provider', 'slot' => $slotA, 'depth' => 0, 'key' => 'first', 'dependsOn' => [], 'params' => []],
				['kind' => 'provider', 'id' => 'sample.second', 'role' => 'provider', 'slot' => $slotB, 'depth' => 0, 'key' => 'second', 'dependsOn' => ['first'], 'params' => []],
			],
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$providerBDone = false;
		$client = new ControllableHttpClient(static function (string $method, string $url) use ($manifestJson, $slowWidgetId, &$providerBDone): ControllableResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return ControllableResponse::ok($manifestJson, key: 'manifest');
			}
			if (str_ends_with($path, '/providers/sample.first')) {
				return ControllableResponse::ok(json_encode(['html' => '<p>First</p>', 'styles' => []], JSON_THROW_ON_ERROR), key: 'provider-first');
			}
			if (str_ends_with($path, '/providers/sample.second')) {
				return ControllableResponse::ok(json_encode(['html' => '<p>Second</p>', 'styles' => []], JSON_THROW_ON_ERROR), key: 'provider-second')
					->onComplete(static function () use (&$providerBDone): void {
						$providerBDone = true;
					});
			}
			$id = self::query($url)['_iikiti_fragment_id'] ?? null;
			if ('@main' === $id) {
				return ControllableResponse::ok(self::fragment(''), key: 'speculative-main');
			}
			self::assertSame($slowWidgetId, $id);

			return ControllableResponse::ok(self::fragment('<p>Slow Widget</p>', [], $slowWidgetId), key: 'slow-widget')
				->holdUntil(static function () use (&$providerBDone): bool {
					return $providerBDone;
				});
		});

		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy($api, new ApiSessionTokenProvider($api), new FiberTaskRunner($client), new GuestApiTokenManager('test-secret'));

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$events = $client->getEvents();

		self::assertSame(200, $response->getStatusCode());
		self::assertStringContainsString('<p>Slow Widget</p><p>First</p><p>Second</p>', (string) $response->getContent());
		self::assertLessThan(array_search('complete:provider-second', $events, true), array_search('complete:provider-first', $events, true));
		self::assertLessThan(array_search('complete:slow-widget', $events, true), array_search('complete:provider-second', $events, true));
	}

	public function testSlidingWindowStartsOverflowFragmentsWithoutWaitingForBlockedFirstWaveFragment(): void
	{
		$fragments = [];
		$slotsHtml = '';
		$idToIndex = [];
		for ($i = 1; $i <= 34; ++$i) {
			$widgetId = str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
			$slot = str_pad(dechex($i), 32, 'f', STR_PAD_LEFT);
			$idToIndex[$widgetId] = $i;
			$fragments[] = ['kind' => 'widget', 'id' => $widgetId, 'role' => 'widget', 'slot' => $slot, 'depth' => 1];
			$slotsHtml .= '<template data-iikiti-widget-slot="'.$slot.'"></template>';
		}
		$manifestJson = json_encode([
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'.$slotsHtml.'</main></body></html>',
			'styles' => [],
			'editor_mode' => false,
			'revision' => str_repeat('a', 64),
			'fragments' => $fragments,
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);

		$w34Done = false;
		$client = new ControllableHttpClient(static function (string $method, string $url) use ($manifestJson, $idToIndex, &$w34Done): ControllableResponse {
			$path = (string) parse_url($url, PHP_URL_PATH);
			if (str_ends_with($path, '/manifest')) {
				return ControllableResponse::ok($manifestJson, key: 'manifest');
			}
			$id = (string) (self::query($url)['_iikiti_fragment_id'] ?? '');
			if ('@main' === $id) {
				return ControllableResponse::ok(self::fragment(''), key: 'speculative-main');
			}
			$idx = $idToIndex[$id];
			if (1 === $idx) {
				return ControllableResponse::ok(self::fragment('<p>W1</p>', [], $id), key: 'w1')
					->holdUntil(static function () use (&$w34Done): bool {
						return $w34Done;
					});
			}
			if (34 === $idx) {
				return ControllableResponse::ok(self::fragment('<p>W34</p>', [], $id), key: 'w34')
					->onComplete(static function () use (&$w34Done): void {
						$w34Done = true;
					});
			}

			return ControllableResponse::ok(self::fragment('<p>W'.$idx.'</p>', [], $id), key: 'w'.$idx);
		});

		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicPageApiProxy($api, new ApiSessionTokenProvider($api), new FiberTaskRunner($client), new GuestApiTokenManager('test-secret'));

		$response = $proxy->home(Request::create('https://www.example.test/'));
		$events = $client->getEvents();

		self::assertSame(200, $response->getStatusCode());
		self::assertLessThanOrEqual(32, $client->getMaxConcurrentInFlight());
		self::assertStringContainsString('<p>W1</p>', (string) $response->getContent());
		self::assertStringContainsString('<p>W34</p>', (string) $response->getContent());
		self::assertLessThan(array_search('complete:w1', $events, true), array_search('complete:w34', $events, true));
	}

	public function testFatalFragmentFailureCancelsStillBlockedSiblingRequestsImmediately(): void
	{
		foreach ([500 => 500, 409 => 409] as $apiStatus => $expectedProxyStatus) {
			$widgetA = str_repeat('7', 64);
			$widgetB = str_repeat('8', 64);
			$slotA = str_repeat('a', 32);
			$slotB = str_repeat('b', 32);
			$manifestJson = json_encode([
				'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'
					.'<template data-iikiti-widget-slot="'.$slotA.'"></template>'
					.'<template data-iikiti-widget-slot="'.$slotB.'"></template>'
					.'</main></body></html>',
				'styles' => [],
				'editor_mode' => false,
				'revision' => str_repeat('a', 64),
				'fragments' => [
					['kind' => 'widget', 'id' => $widgetA, 'role' => 'widget', 'slot' => $slotA, 'depth' => 1],
					['kind' => 'widget', 'id' => $widgetB, 'role' => 'widget', 'slot' => $slotB, 'depth' => 1],
				],
				'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
			], JSON_THROW_ON_ERROR);

			$widgetACanceled = false;
			$client = new ControllableHttpClient(static function (string $method, string $url) use ($manifestJson, $widgetA, $widgetB, $apiStatus, &$widgetACanceled): ControllableResponse {
				$path = (string) parse_url($url, PHP_URL_PATH);
				if (str_ends_with($path, '/manifest')) {
					return ControllableResponse::ok($manifestJson, key: 'manifest');
				}
				$id = self::query($url)['_iikiti_fragment_id'] ?? null;
				if ('@main' === $id) {
					return ControllableResponse::ok(self::fragment(''), key: 'speculative-main');
				}
				if ($widgetA === $id) {
					return ControllableResponse::ok(self::fragment('<p>Never</p>', [], $widgetA), key: 'widget-a')
						->holdUntil(static fn (): bool => false)
						->onCancel(static function () use (&$widgetACanceled): void {
							$widgetACanceled = true;
						});
				}
				self::assertSame($widgetB, $id);

				return ControllableResponse::error($apiStatus, '{"message":"failure"}', key: 'widget-b');
			});

			$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
			$proxy = new PublicPageApiProxy(
				$api,
				new ApiSessionTokenProvider($api),
				new FiberTaskRunner($client),
				new GuestApiTokenManager('test-secret'),
				errorPages: $this->errorPageRenderer(),
			);

			$response = $proxy->home(Request::create('https://www.example.test/'));

			self::assertSame($expectedProxyStatus, $response->getStatusCode());
			self::assertNotInstanceOf(DomHtmlResponse::class, $response);
			self::assertTrue($widgetACanceled);
			self::assertStringNotContainsString('<p>Never</p>', (string) $response->getContent());
		}
	}

	/** @param list<array{kind:string,id:string,role:string,slot:string,depth?:int}> $fragments
	 *  @param list<array{id:?string,css:string}> $styles
	 */
	private static function manifest(array $fragments = [], array $styles = [], bool $editorMode = false): string
	{
		if ([] === $fragments) {
			$fragments = [['kind' => 'region', 'id' => 'main', 'role' => 'main', 'slot' => str_repeat('a', 32)]];
		}

		$slots = '';
		foreach ($fragments as $fragment) {
			if ('widget' === $fragment['kind']) {
				continue;
			}
			$attribute = match ($fragment['kind']) {
				'region' => 'data-iikiti-region-slot',
				'shell' => 'data-iikiti-shell-slot',
				'widget' => 'data-iikiti-widget-slot',
				default => throw new \UnexpectedValueException('Unsupported test fragment kind.'),
			};
			$slots .= sprintf('<template %s="%s"></template>', $attribute, $fragment['slot']);
		}

		return json_encode([
			'html' => '<!DOCTYPE html><html><head><title>Manifest SEO title</title><link rel="canonical" href="https://www.example.test/"><template data-iikiti-fragment="custom-css"></template></head><body><main>'.$slots.'</main></body></html>',
			'styles' => $styles,
			'editor_mode' => $editorMode,
			'revision' => str_repeat('a', 64),
			'fragments' => $fragments,
			'inline_asset_minification' => ['css_minify' => true, 'js_minify' => true, 'js_uglify' => false],
		], JSON_THROW_ON_ERROR);
	}

	/** @param list<array{id:?string,css:string}> $styles */
	private static function fragment(string $html, array $styles = [], string $id = 'main'): string
	{
		return json_encode(['found' => true, 'id' => $id, 'revision' => str_repeat('a', 64), 'html' => $html, 'styles' => $styles], JSON_THROW_ON_ERROR);
	}

	/** @return array<string,mixed> */
	private static function query(string $url): array
	{
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		return $query;
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

	private function errorPageRenderer(): HttpErrorPageRenderer
	{
		$root = dirname(__DIR__, 3);
		$loader = new FilesystemLoader($root.'/templates');
		$loader->addPath($root.'/plugins/forma-2026/templates', 'Forma2026');
		$twig = new Environment($loader, ['autoescape' => 'html']);
		$twig->addGlobal('app', (object) ['request' => (object) ['locale' => 'en']]);

		return new HttpErrorPageRenderer($twig);
	}

}
