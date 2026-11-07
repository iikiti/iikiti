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
		self::assertGreaterThanOrEqual(0.0, $collector->getTotalWaitTimeMs());
	}
}
