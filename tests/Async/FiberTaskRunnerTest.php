<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Async;

use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskException;
use iikiti\CMS\Async\FiberTaskRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class FiberTaskRunnerTest extends TestCase
{
	public function testStartsAllLazyHttpRequestsBeforeStreamingAndReturnsInputOrder(): void
	{
		$requestedUrls = [];
		$transport = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
			$requestedUrls[] = $url;

			return new MockResponse($url);
		});
		$mockHttpClient = $this->createMock(HttpClientInterface::class);
		$mockHttpClient->expects(self::exactly(2))
			->method('request')
			->willReturnCallback(static fn (string $method, string $url, array $options): ResponseInterface =>
				$transport->request($method, $url, $options));
		$mockHttpClient->expects(self::once())
			->method('stream')
			->willReturnCallback(static function (ResponseInterface|iterable $responses, ?float $timeout) use ($transport): ResponseStreamInterface {
				self::assertCount(2, $responses);

				return $transport->stream($responses, $timeout);
			});
		$runner = new FiberTaskRunner($mockHttpClient);

		$result = $runner->run([
			'z-last' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/z')->getContent(),
			'a-first' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/a')->getContent(),
		]);

		self::assertSame(['z-last', 'a-first'], array_keys($result));
		self::assertSame([
			'z-last' => 'https://example.test/z',
			'a-first' => 'https://example.test/a',
		], $result);
		self::assertCount(2, $requestedUrls);
	}

	public function testCanContinueWithAnotherHttpRequestAfterAResponseCompletes(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient(
			static fn (string $method, string $url): MockResponse => new MockResponse($url),
		));

		$result = $runner->run([
			'chained' => static function (FiberHttpClient $http): string {
				$first = $http->request('GET', 'https://example.test/first')->getContent();
				$second = $http->request('GET', 'https://example.test/second')->getContent();

				return $first.'+'.$second;
			},
		]);

		self::assertSame(['chained' => 'https://example.test/first+https://example.test/second'], $result);
	}

	public function testRunsLocalRenderingWorkAfterStartingRemoteRequests(): void
	{
		$requestedUrls = [];
		$events = [];
		$transport = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrls): MockResponse {
			$requestedUrls[] = $url;

			return new MockResponse('remote fragment');
		});
		$http = $this->createMock(HttpClientInterface::class);
		$http->method('request')->willReturnCallback(static fn (string $method, string $url, array $options): ResponseInterface =>
			$transport->request($method, $url, $options));
		$http->expects(self::once())
			->method('stream')
			->willReturnCallback(static function (ResponseInterface|iterable $responses, ?float $timeout) use ($transport, &$requestedUrls, &$events): ResponseStreamInterface {
				self::assertSame(['https://example.test/header'], $requestedUrls);
				$events[] = 'stream';

				return $transport->stream($responses, $timeout);
			});

		$execution = (new FiberTaskRunner($http))->runDuring(
			['header' => static fn (FiberHttpClient $client): string => $client->request('GET', 'https://example.test/header')->getContent()],
			static function () use (&$events): string {
				$events[] = 'render';

				return '<main>already rendered</main>';
			},
		);

		self::assertSame('remote fragment', $execution['tasks']['header']);
		self::assertSame('<main>already rendered</main>', $execution['work']);
		self::assertSame(['render', 'stream'], $events);
	}

	public function testTaskFailuresIdentifyTheirInputKey(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient(
			static fn (): MockResponse => new MockResponse('failure', ['http_code' => 503]),
		));

		try {
			$runner->run([
				'upstream' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/error')->getContent(),
			]);
			self::fail('A failed HTTP task must be propagated.');
		} catch (FiberTaskException $exception) {
			self::assertSame('upstream', $exception->taskKey);
		}
	}

	public function testEmptyTaskGroupReturnsImmediately(): void
	{
		self::assertSame([], (new FiberTaskRunner(new MockHttpClient()))->run([]));
	}
}
