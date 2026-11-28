<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Web\BlockEditor;

use iikiti\CMS\Api\ApiSessionTokenProvider;
use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Security\GuestApiTokenManager;
use iikiti\CMS\Web\BlockEditor\PublicAssetApiProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class PublicAssetApiProxyTest extends TestCase
{
	public function testProxiesTheSiteBoundPublicAssetResponse(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('https://api.example.test/api/public/assets/'.str_repeat('a', 64).'/thumbnail', $url);
			self::assertSame('www.example.test', self::header($options['headers'], 'X-SITE-HOST'));
			$guest = self::header($options['headers'], 'X-AUTH-TOKEN');
			self::assertNotNull($guest);
			self::assertNotNull((new GuestApiTokenManager('test-secret'))->decode($guest));

			return new MockResponse('png', [
				'http_code' => 200,
				'response_headers' => [
					'Content-Type: image/png',
					'Cache-Control: public, max-age=3600',
					'X-Content-Type-Options: nosniff',
					'Content-Disposition: inline; filename="image.png"',
				],
			]);
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicAssetApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->file(Request::create('https://www.example.test/assets/'.str_repeat('a', 64).'/thumbnail'), str_repeat('a', 64), 'thumbnail');

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('png', $response->getContent());
		self::assertSame('image/png', $response->headers->get('Content-Type'));
		self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
		self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
		self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
	}

	public function testDoesNotProxyServerErrorsAsSuccessfulFiles(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicAssetApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$response = $proxy->file(Request::create('https://www.example.test/assets/'.str_repeat('b', 64).'/original'), str_repeat('b', 64), 'original');

		self::assertSame(502, $response->getStatusCode());
	}

	public function testPluginAssetRejectsTraversalAndNormalizesValidSegments(): void
	{
		$requestedUrls = [];
		$client = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
			$requestedUrls[] = $url;

			return new MockResponse('export default 1;', [
				'http_code' => 200,
				'response_headers' => ['Content-Type: application/javascript; charset=UTF-8'],
			]);
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicAssetApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$request = Request::create('https://www.example.test/');
		foreach (['../secret.js', 'dist\\editor.js', 'dist/.env', 'dist/editor.js?x=1', '/etc/passwd'] as $badPath) {
			self::assertSame(404, $proxy->pluginAsset($request, 'iikiti-forms', $badPath)->getStatusCode());
		}
		self::assertSame(404, $proxy->pluginAsset($request, '../evil', 'dist/editor.js')->getStatusCode());
		self::assertSame([], $requestedUrls);

		$ok = $proxy->pluginAsset($request, 'iikiti-forms', 'dist/my file.js');
		self::assertSame(200, $ok->getStatusCode());
		self::assertSame(['https://api.example.test/api/public/plugin-assets/iikiti-forms/dist/my%20file.js'], $requestedUrls);
	}

	public function testForwardsQueryParametersConditionalAndRangeHeadersAndPassesThrough206(): void
	{
		$token = str_repeat('c', 64);
		$client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('https://api.example.test/api/public/assets/'.$token.'/original?v=deadbeef1234', $url);
			self::assertSame('bytes=0-3', self::header($options['headers'], 'Range'));
			self::assertSame('"deadbeef"', self::header($options['headers'], 'If-None-Match'));

			return new MockResponse('ABCD', [
				'http_code' => 206,
				'response_headers' => [
					'Content-Type: application/octet-stream',
					'Content-Range: bytes 0-3/16',
					'Accept-Ranges: bytes',
					'Content-Length: 4',
					'Vary: Accept-Encoding',
					'ETag: "0123456789abcdef"',
				],
			]);
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicAssetApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$request = Request::create(
			'https://www.example.test/assets/'.$token.'/original?v=deadbeef1234&ignored=1',
			'GET',
			[],
			[],
			[],
			[
				'HTTP_RANGE' => 'bytes=0-3',
				'HTTP_IF_NONE_MATCH' => '"deadbeef"',
			],
		);
		$response = $proxy->file($request, $token, 'original');

		self::assertSame(206, $response->getStatusCode());
		self::assertSame('ABCD', $response->getContent());
		self::assertSame('bytes 0-3/16', $response->headers->get('Content-Range'));
		self::assertSame('bytes', $response->headers->get('Accept-Ranges'));
		self::assertSame('Accept-Encoding', $response->headers->get('Vary'));
	}

	public function testProxiesSignedFileWithHead304And416Responses(): void
	{
		$token = str_repeat('d', 64);
		$sig = str_repeat('e', 64);
		$responses = [
			static function (string $method, string $url) use ($token, $sig): MockResponse {
				self::assertSame('HEAD', $method);
				self::assertSame(
					'https://api.example.test/api/public/assets/signed/'.$token.'/original?v=2&exp=1764300900&sig='.$sig.'&site=5&d=attachment',
					$url,
				);

				return new MockResponse('should-be-stripped', [
					'http_code' => 200,
					'response_headers' => [
						'Content-Type: application/pdf',
						'Content-Length: 4096',
						'Accept-Ranges: bytes',
						'Cache-Control: private, no-store',
					],
				]);
			},
			static fn (): MockResponse => new MockResponse('', [
				'http_code' => 304,
				'response_headers' => [
					'ETag: "abc"',
					'Cache-Control: public, max-age=3600',
				],
			]),
			static fn (): MockResponse => new MockResponse('', [
				'http_code' => 416,
				'response_headers' => [
					'Content-Range: bytes */4096',
					'Accept-Ranges: bytes',
				],
			]),
		];
		$client = new MockHttpClient($responses);
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');
		$proxy = new PublicAssetApiProxy(
			$api,
			new ApiSessionTokenProvider($api),
			new FiberTaskRunner($client),
			new GuestApiTokenManager('test-secret'),
		);

		$headRequest = Request::create(
			'https://www.example.test/assets/signed/'.$token.'/original?exp=1764300900&site=5&d=attachment&v=2&sig='.$sig,
			'HEAD',
		);
		$headResponse = $proxy->signedFile($headRequest, $token, 'original');
		self::assertSame(200, $headResponse->getStatusCode());
		self::assertSame('', $headResponse->getContent());
		self::assertSame('4096', $headResponse->headers->get('Content-Length'));
		self::assertSame('bytes', $headResponse->headers->get('Accept-Ranges'));

		$notModified = $proxy->file(Request::create('https://www.example.test/assets/'.$token.'/original'), $token, 'original');
		self::assertSame(304, $notModified->getStatusCode());
		self::assertSame('', $notModified->getContent());
		self::assertSame('"abc"', $notModified->headers->get('ETag'));

		$unsatisfiable = $proxy->file(Request::create('https://www.example.test/assets/'.$token.'/original'), $token, 'original');
		self::assertSame(416, $unsatisfiable->getStatusCode());
		self::assertSame('bytes */4096', $unsatisfiable->headers->get('Content-Range'));
		self::assertSame('bytes', $unsatisfiable->headers->get('Accept-Ranges'));
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
