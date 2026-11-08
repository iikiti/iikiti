<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
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

		$response = $proxy->page(Request::create('https://www.example.test/about?lang=en'), 'about');

		self::assertSame(200, $response->getStatusCode());
		self::assertInstanceOf(DomHtmlResponse::class, $response);
		self::assertStringContainsString('<p>remote page</p>', (string) $response->getContent());
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
		$session = $this->createStub(SessionInterface::class);
		$session->method('getName')->willReturn('APPSESSID');
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
			'html' => '<!DOCTYPE html><html><head><template data-iikiti-fragment="custom-css"></template></head><body><main>'.$slots.'</main></body></html>',
			'styles' => $styles,
			'editor_mode' => $editorMode,
			'revision' => str_repeat('a', 64),
			'fragments' => $fragments,
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

}
