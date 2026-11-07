<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Profiler;

use Dom\HTMLDocument;
use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use iikiti\CMS\Response\Composition\DomHtmlResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AsyncResponseDataCollectorTest extends TestCase
{
	public function testCollectsFiberGroupsAndDomResponseMode(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->recordFiberGroup(taskCount: 3, workTimeMs: 14.5, waitTimeMs: 22.1, failures: 0);
		$collector->recordHttpRequest();
		$collector->recordHttpRequest();
		$collector->recordComposition('html-dom', 4);
		$response = new DomHtmlResponse(HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>'));

		$collector->collect(Request::create('/'), $response);

		self::assertTrue($collector->isAsyncUsed());
		self::assertSame(1, $collector->getFiberGroupCount());
		self::assertSame(2, $collector->getHttpRequestCount());
		self::assertSame(4, $collector->getFragmentCount());
		self::assertTrue($collector->isDomDocumentReused());
		self::assertTrue($collector->isResponsePathUsed());
		self::assertSame('DOM HTML document', $collector->getResponse()['mode']);
		self::assertSame(3, $collector->getFiberGroups()[0]['tasks']);
		self::assertSame(0, $collector->getFailureCount());
		self::assertEqualsWithDelta(22.1, $collector->getTotalWaitTimeMs(), 0.01);
	}

	public function testDomResponseIsDistinctFromFiberAsyncIo(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->collect(Request::create('/'), new DomHtmlResponse(HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>')));

		self::assertFalse($collector->isAsyncUsed());
		self::assertTrue($collector->isDomDocumentReused());
		self::assertTrue($collector->isResponsePathUsed());
	}

	public function testCollectsLegacyResponsesAsNotAsync(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->collect(Request::create('/'), new Response('legacy'));

		self::assertFalse($collector->isAsyncUsed());
		self::assertFalse($collector->isDomDocumentReused());
		self::assertFalse($collector->isResponsePathUsed());
		self::assertSame('standard response body', $collector->getResponse()['mode']);
	}

	public function testRecordsJsonResponseMode(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->collect(Request::create('/'), new JsonResponse(['ok' => true]));

		self::assertSame('JSON response', $collector->getResponse()['mode']);
	}

	public function testRecordsRequestDetailsWithoutCredentialsOrSensitiveQueryValues(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->recordAsyncRequest(
			'get',
			'https://username:password@example.test/api/public/sites/site.example.test/assets/public-token/thumb?site=4&access_token=url-token',
			200,
			1.234,
			false,
			['api_key' => 'query-secret', 'page' => 2],
		);
		$collector->recordApiSubrequest('post', 'https://api.example.test/api/items', 503, 4.567, true);

		$asyncRequest = $collector->getAsyncRequests()[0];
		self::assertSame('GET', $asyncRequest['method']);
		self::assertSame('https://example.test/api/public/sites/site.example.test/assets/[redacted]/thumb?site=4&access_token=%5Bredacted%5D&api_key=%5Bredacted%5D&page=2', $asyncRequest['url']);
		self::assertSame(200, $asyncRequest['status']);
		self::assertEqualsWithDelta(1.23, $asyncRequest['duration_ms'], 0.01);
		self::assertFalse($asyncRequest['failed']);
		self::assertSame('POST', $collector->getApiSubrequests()[0]['method']);
		self::assertSame(503, $collector->getApiSubrequests()[0]['status']);
		self::assertTrue($collector->getApiSubrequests()[0]['failed']);

		$serialized = serialize($collector);
		self::assertStringNotContainsString('password', $serialized);
		self::assertStringNotContainsString('url-token', $serialized);
		self::assertStringNotContainsString('query-secret', $serialized);
		self::assertStringNotContainsString('public-token', $serialized);
	}

	public function testResetClearsRequestDetails(): void
	{
		$collector = new AsyncResponseDataCollector();
		$collector->recordAsyncRequest('GET', 'https://example.test/value', 200, 2.0, false);
		$collector->recordApiSubrequest('GET', 'https://api.example.test/api/value', 200, 3.0, false);

		$collector->reset();

		self::assertSame([], $collector->getAsyncRequests());
		self::assertSame([], $collector->getApiSubrequests());
		self::assertSame(0, $collector->getHttpRequestCount());
	}

	public function testFiberRunnerRecordsHttpAndLocalWorkMetrics(): void
	{
		$collector = new AsyncResponseDataCollector();
		$client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('ok'));
		$runner = new FiberTaskRunner($client, $collector);

		$runner->runDuring([
			'header' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/header')->getContent(),
		], static fn (): string => 'local content');
		$collector->collect(Request::create('/'), new Response('response'));

		self::assertSame(1, $collector->getFiberGroupCount());
		self::assertSame(1, $collector->getHttpRequestCount());
		self::assertCount(1, $collector->getAsyncRequests());
		self::assertSame(200, $collector->getAsyncRequests()[0]['status']);
		self::assertFalse($collector->getAsyncRequests()[0]['failed']);
		self::assertGreaterThanOrEqual(0.0, $collector->getTotalWaitTimeMs());
	}

	public function testFiberRunnerRecordsTransportFailureWithoutExceptionDetails(): void
	{
		$collector = new AsyncResponseDataCollector();
		$client = new MockHttpClient(static function (): never {
			throw new \Symfony\Component\HttpClient\Exception\TransportException('secret transport details');
		});
		$runner = new FiberTaskRunner($client, $collector);

		try {
			$runner->run([
				'failed' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/failure?token=hidden')->getContent(),
			]);
		} catch (\Throwable) {
		}

		$request = $collector->getAsyncRequests()[0];
		self::assertNull($request['status']);
		self::assertTrue($request['failed']);
		self::assertSame('https://example.test/failure?token=%5Bredacted%5D', $request['url']);
		self::assertStringNotContainsString('secret transport details', serialize($collector));
	}
}
