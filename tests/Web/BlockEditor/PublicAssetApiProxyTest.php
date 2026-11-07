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
