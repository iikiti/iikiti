<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Api;

use iikiti\CMS\Api\BusinessApiClient;
use iikiti\CMS\Api\BusinessApiException;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BusinessApiClientTest extends TestCase
{
	public function testUserTokenAndValidatedApiRouteAreForwarded(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('GET', $method);
			self::assertSame('https://api.example.test/api/objects?site=4&page=2', $url);
			self::assertTrue(self::hasHeader($options['headers'], 'X-AUTH-TOKEN', 'user-token'));
			self::assertTrue(self::hasHeader($options['headers'], 'X-SITE-HOST', 'site.example.test'));
			self::assertSame(0, $options['max_redirects']);

			return new MockResponse('{"items":[{"id":7}]}');
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');

		self::assertSame(['items' => [['id' => 7]]], $api->request(
			'get',
			'/api/objects',
			'user-token',
			query: ['site' => 4, 'page' => 2],
			headers: ['X-SITE-HOST' => 'site.example.test'],
		));
	}

	public function testPublicRequestDoesNotSendAnEmptyCredential(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertFalse(self::hasHeader($options['headers'], 'X-AUTH-TOKEN', ''));

			return new MockResponse('{"public":true}');
		});
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test');

		self::assertSame(['public' => true], $api->request('GET', '/api/sites'));
	}

	public function testResolveMapChangesTransportAddressWithoutChangingApiHost(): void
	{
		$client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
			self::assertSame('http://iikiti.localhost/api/public/render/home/manifest', $url);
			self::assertSame(['iikiti.localhost' => '172.17.0.1'], $options['resolve']);

			return new MockResponse('{"ok":true}');
		});
		$api = new BusinessApiClient(
			$client,
			'http://iikiti.localhost',
			'test',
			resolve: ['iikiti.localhost' => '172.17.0.1'],
		);

		self::assertSame(['ok' => true], $api->request('GET', '/api/public/render/home/manifest'));
	}

	public function testAsyncRequestUsesFiberHttpClient(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"id":9}'));
		$profiler = new AsyncResponseDataCollector();
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test', $profiler);
		$runner = new FiberTaskRunner($client, $profiler);
		$result = $runner->run([
			'object' => static fn (FiberHttpClient $http): array => $api->requestAsync($http, 'GET', '/api/objects/9', 'token', query: ['page' => 2, 'access_token' => 'not-for-profiler']),
		]);

		self::assertSame(['id' => 9], $result['object']);
		self::assertCount(1, $profiler->getAsyncRequests());
		self::assertCount(1, $profiler->getApiSubrequests());
		self::assertSame('https://api.example.test/api/objects/9?page=2&access_token=%5Bredacted%5D', $profiler->getApiSubrequests()[0]['url']);
		self::assertStringNotContainsString('not-for-profiler', serialize($profiler));
	}

	public function testSyncRequestRecordsQueryAndHttpStatusWithoutCredentialValues(): void
	{
		$client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"items":[]}', ['http_code' => 201]));
		$profiler = new AsyncResponseDataCollector();
		$api = new BusinessApiClient($client, 'https://api.example.test', 'test', $profiler);

		$api->request('GET', '/api/objects', 'user-token', query: ['site' => 4, 'auth_token' => 'query-secret']);

		$request = $profiler->getApiSubrequests()[0];
		self::assertSame('GET', $request['method']);
		self::assertSame('https://api.example.test/api/objects?site=4&auth_token=%5Bredacted%5D', $request['url']);
		self::assertSame(201, $request['status']);
		self::assertFalse($request['failed']);
		self::assertStringNotContainsString('user-token', serialize($profiler));
		self::assertStringNotContainsString('query-secret', serialize($profiler));
	}

	public function testRejectsAPathOutsideTheBusinessApi(): void
	{
		$api = new BusinessApiClient(new MockHttpClient(), 'https://api.example.test', 'test');
		$this->expectException(\InvalidArgumentException::class);

		$api->request('GET', 'https://other.example/api/objects');
	}

	public function testNonSuccessResponseDoesNotExposeItsBody(): void
	{
		$profiler = new AsyncResponseDataCollector();
		$api = new BusinessApiClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('secret', ['http_code' => 403])), 'https://api.example.test', 'test', $profiler);

		try {
			$api->request('GET', '/api/admin/users', 'token');
			self::fail('Expected a BusinessApiException.');
		} catch (BusinessApiException $exception) {
			self::assertSame(403, $exception->statusCode);
			self::assertStringNotContainsString('secret', $exception->getMessage());
		}

		self::assertSame(403, $profiler->getApiSubrequests()[0]['status']);
		self::assertTrue($profiler->getApiSubrequests()[0]['failed']);
		self::assertStringNotContainsString('secret', serialize($profiler));
	}

	public function testProductionRejectsInsecureBaseUrl(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new BusinessApiClient(new MockHttpClient(), 'http://api.example.test', 'prod');
	}

	/** @param array<array-key,mixed> $headers */
	private static function hasHeader(array $headers, string $name, string $value): bool
	{
		foreach ($headers as $headerName => $headerValue) {
			if (is_string($headerName) && 0 === strcasecmp($name, $headerName) && $value === $headerValue) {
				return true;
			}
			if (is_string($headerValue) && 1 === preg_match('/^'.preg_quote($name, '/').':\s*'.preg_quote($value, '/').'$/iD', trim($headerValue))) {
				return true;
			}
		}

		return false;
	}
}
