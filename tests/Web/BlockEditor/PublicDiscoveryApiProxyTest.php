<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Web\BlockEditor\PublicPageApiProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class PublicDiscoveryApiProxyTest extends TestCase
{
	public function testRobotsProxyForwardsSiteContextAndReturnsValidatedTextContent(): void
	{
		$observed = [];
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$observed): MockResponse {
			$observed = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

			return new MockResponse(json_encode(['body' => "User-agent: *\nAllow: /\n", 'contentType' => 'text/plain; charset=UTF-8'], JSON_THROW_ON_ERROR));
		});
		$proxy = $this->proxy($client);

		$response = $proxy->robots(Request::create('https://www.example.test/robots.txt'));

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('GET', $observed['method']);
		self::assertSame('/api/public/content-optimization/robots', parse_url($observed['url'], PHP_URL_PATH));
		self::assertSame('www.example.test', self::header($observed['headers'], 'X-SITE-HOST'));
		self::assertSame('https', self::header($observed['headers'], 'X-SITE-SCHEME'));
		self::assertSame('https', self::header($observed['headers'], 'X-Forwarded-Proto'));
		$token = self::header($observed['headers'], 'X-AUTH-TOKEN');
		self::assertNotNull($token);
		self::assertNotNull((new GuestApiTokenManager('test-secret'))->decode($token));
		self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
		self::assertSame("User-agent: *\nAllow: /\n", $response->getContent());
		self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
	}

	public function testSitemapRequiresExpectedXmlContentType(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url): MockResponse {
			self::assertSame('/api/public/content-optimization/sitemap', parse_url($url, PHP_URL_PATH));

			return new MockResponse(json_encode([
				'body' => '<?xml version="1.0"?><urlset></urlset>',
				'contentType' => 'application/xml; charset=UTF-8',
			], JSON_THROW_ON_ERROR));
		});
		$response = $this->proxy($client)->sitemap(Request::create('https://www.example.test/sitemap.xml'));

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
		self::assertSame('<?xml version="1.0"?><urlset></urlset>', $response->getContent());
	}

	public function testRejectsUnexpectedDocumentContentType(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
			'body' => '<html>unexpected</html>',
			'contentType' => 'text/html; charset=UTF-8',
		], JSON_THROW_ON_ERROR)));
		$response = $this->proxy($client)->sitemap(Request::create('https://www.example.test/sitemap.xml'));

		self::assertSame(502, $response->getStatusCode());
		self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
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

	/** @param array<array-key,mixed> $headers */
	private static function header(array $headers, string $name): ?string
	{
		foreach ($headers as $key => $value) {
			if (is_string($value)) {
				if (is_string($key) && 0 === strcasecmp($key, $name)) {
					return $value;
				}
				if (1 === preg_match('/^'.preg_quote($name, '/').':\s*(.*?)$/iD', trim($value), $matches)) {
					return $matches[1];
				}
			}
		}

		return null;
	}
}
