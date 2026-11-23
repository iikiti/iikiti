<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Async;

use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskException;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Async\Promise;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ServerException;
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

	public function testUnresolvablePendingPromiseTerminatesWithLogicExceptionInsteadOfLooping(): void
	{
		$unresolved = Promise::withResolvers()->promise;
		$runner = new FiberTaskRunner(new MockHttpClient());

		try {
			$runner->run([
				'stuck' => static fn (FiberHttpClient $http): mixed => $unresolved->execute($http),
			]);
			self::fail('An unresolved Promise must fail cleanly.');
		} catch (FiberTaskException $exception) {
			self::assertSame('stuck', $exception->taskKey);
			self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
		}
	}

	public function testHttpErrorInOneTaskDoesNotAbortSiblingTasks(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient(static function (string $method, string $url): MockResponse {
			if (str_contains($url, '/fail')) {
				return new MockResponse('boom', ['http_code' => 503]);
			}

			return new MockResponse('healthy');
		}));

		$results = $runner->run([
			'failing' => static function (FiberHttpClient $http): string {
				try {
					return $http->request('GET', 'https://example.test/fail')->getContent();
				} catch (ServerException) {
					return 'recovered';
				}
			},
			'healthy' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/ok')->getContent(),
		]);

		self::assertSame([
			'failing' => 'recovered',
			'healthy' => 'healthy',
		], $results);
	}

	public function testChainedRequestInsideResumedFiberIsImmediatelyPolledAlongsideSiblings(): void
	{
		$streamSizes = [];
		$transport = new MockHttpClient(static function (string $method, string $url): MockResponse {
			if (str_contains($url, '/slow')) {
				return new MockResponse(['part-1', 'part-2']);
			}

			return new MockResponse($url);
		});
		$http = $this->createStub(HttpClientInterface::class);
		$http->method('request')->willReturnCallback(static fn (string $method, string $url, array $options): ResponseInterface =>
			$transport->request($method, $url, $options));
		$http->method('stream')->willReturnCallback(static function (ResponseInterface|iterable $responses, ?float $timeout) use ($transport, &$streamSizes): ResponseStreamInterface {
			$streamSizes[] = count($responses);

			return $transport->stream($responses, $timeout);
		});

		$results = (new FiberTaskRunner($http))->run([
			'chained' => static function (FiberHttpClient $client): string {
				$first = $client->request('GET', 'https://example.test/first')->getContent();
				$second = $client->request('GET', 'https://example.test/second')->getContent();

				return $first.'+'.$second;
			},
			'slow' => static fn (FiberHttpClient $client): string => $client->request('GET', 'https://example.test/slow')->getContent(),
		]);

		self::assertSame([
			'chained' => 'https://example.test/first+https://example.test/second',
			'slow' => 'part-1part-2',
		], $results);
		// Second stream() call is entered as soon as `chained` spawns `/second`, while `/slow` is still active.
		self::assertGreaterThanOrEqual(2, count($streamSizes));
		self::assertSame(2, $streamSizes[1]);
	}

	public function testRunUntilReturnsCompletedResultsWithoutFailingOnCancelledSiblings(): void
	{
		$slowUnwound = false;
		$runner = new FiberTaskRunner(new MockHttpClient(static function (string $method, string $url): MockResponse {
			if (str_contains($url, '/slow')) {
				return new MockResponse(['part-1', 'part-2']);
			}

			return new MockResponse('fast-ok');
		}));

		$results = $runner->runUntil(
			[
				'fast' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/fast')->getContent(),
				'slow' => static function (FiberHttpClient $http) use (&$slowUnwound): string {
					try {
						return $http->request('GET', 'https://example.test/slow')->getContent();
					} finally {
						$slowUnwound = true;
					}
				},
			],
			static fn (array $completed): bool => isset($completed['fast']),
		);

		self::assertSame(['fast' => 'fast-ok'], $results);
		self::assertTrue($slowUnwound);
	}

	public function testReAwaitedUnresolvablePromiseStopsAtDeadline(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient());

		try {
			$runner->run([
				'reawait' => static function (FiberHttpClient $http): mixed {
					while (true) {
						try {
							return Promise::withResolvers()->promise->execute($http);
						} catch (\LogicException) {
							usleep(5_000);
						}
					}
				},
			], 0.05);
			self::fail('Expected deadline TimeoutException.');
		} catch (FiberTaskException $exception) {
			self::assertSame('reawait', $exception->taskKey);
			self::assertInstanceOf(\Symfony\Component\HttpClient\Exception\TimeoutException::class, $exception->getPrevious());
		}
	}

	public function testFiberHttpClientClampsPositiveCallerTimeoutsAndOverridesUnlimitedZeroToDeadline(): void
	{
		$recordedOptions = [];
		$runner = new FiberTaskRunner(new MockHttpClient(static function (string $method, string $url, array $options) use (&$recordedOptions): MockResponse {
			$recordedOptions[$url] = $options;

			return new MockResponse('ok');
		}));

		$runner->run([
			'excessive' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/excessive', [
				'timeout' => 30.0,
				'max_duration' => 30.0,
			])->getContent(),
			'unlimited' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/unlimited', [
				'timeout' => 0,
				'max_duration' => 0,
			])->getContent(),
			'shorter' => static fn (FiberHttpClient $http): string => $http->request('GET', 'https://example.test/shorter', [
				'timeout' => 0.25,
				'max_duration' => 0.25,
			])->getContent(),
		], 1.5);

		self::assertLessThanOrEqual(1.5, $recordedOptions['https://example.test/excessive']['timeout']);
		self::assertLessThanOrEqual(1.5, $recordedOptions['https://example.test/excessive']['max_duration']);
		self::assertGreaterThan(0.0, $recordedOptions['https://example.test/unlimited']['timeout']);
		self::assertLessThanOrEqual(1.5, $recordedOptions['https://example.test/unlimited']['timeout']);
		self::assertGreaterThan(0.0, $recordedOptions['https://example.test/unlimited']['max_duration']);
		self::assertLessThanOrEqual(1.5, $recordedOptions['https://example.test/unlimited']['max_duration']);
		self::assertSame(0.25, $recordedOptions['https://example.test/shorter']['timeout']);
		self::assertSame(0.25, $recordedOptions['https://example.test/shorter']['max_duration']);
	}
}
