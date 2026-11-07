<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Async;

use iikiti\CMS\Async\FiberHttpClient;
use iikiti\CMS\Async\FiberTaskRunner;
use iikiti\CMS\Async\Promise;
use iikiti\CMS\Async\PromiseAggregateError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PromiseTest extends TestCase
{
	public function testConstructorExecutorRunsImmediatelyAndResolveIsIdempotent(): void
	{
		$executorRan = false;
		$promise = new Promise(static function (callable $resolve, callable $reject) use (&$executorRan): void {
			$executorRan = true;
			$resolve('first');
			$reject(new RuntimeException('ignored after settlement'));
		});

		self::assertTrue($executorRan);
		self::assertTrue($promise->isFulfilled());
		self::assertSame('first', $promise->getValue());
	}

	public function testResolveAdoptsThenablesAndStaticResolveReturnsPromiseInputs(): void
	{
		$thenable = new class {
			public function then(callable $resolve, callable $reject): void
			{
				$resolve('thenable value');
			}
		};
		$resolved = Promise::resolve($thenable);

		self::assertSame('thenable value', $resolved->getValue());
		self::assertSame($resolved, Promise::resolve($resolved));
	}

	public function testCustomPromiseIsLazyAndCanChainAHttpOperation(): void
	{
		$http = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('payload'));
		$promise = Promise::async(static fn (FiberHttpClient $client): string =>
			$client->request('GET', 'https://example.test/value')->getContent())
			->then(static fn (string $value): string => strtoupper($value));

		self::assertTrue($promise->isPending());
		self::assertSame('PAYLOAD', $promise->await(new FiberTaskRunner($http)));
		self::assertTrue($promise->isFulfilled());
		self::assertSame('PAYLOAD', $promise->getValue());
	}

	public function testCatchRecoversFromAnRejectedHttpOperationAndFinallyRuns(): void
	{
		$http = new MockHttpClient(static fn (): MockResponse => new MockResponse('unavailable', ['http_code' => 503]));
		$finallyRan = false;
		$promise = Promise::async(static fn (FiberHttpClient $client): string =>
			$client->request('GET', 'https://example.test/unavailable')->getContent())
			->catch(static fn (\Throwable $exception): string => 'fallback')
			->finally(static function () use (&$finallyRan): void {
				$finallyRan = true;
			});

		self::assertSame('fallback', $promise->await(new FiberTaskRunner($http)));
		self::assertTrue($finallyRan);
		self::assertTrue($promise->isFulfilled());
	}

	public function testRejectedPromisePreservesItsReasonUntilCaught(): void
	{
		$reason = new RuntimeException('custom task failed');
		$promise = Promise::rejected($reason);
		$recovered = $promise->catch(static fn (\Throwable $exception): string => $exception->getMessage());

		self::assertTrue($promise->isRejected());
		self::assertSame($reason, $promise->getReason());
		self::assertSame('custom task failed', $recovered->await(new FiberTaskRunner(new MockHttpClient())));
	}

	public function testRunnerAwaitsCustomPromiseInstancesAsAConcurrentGroup(): void
	{
		$requested = [];
		$http = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
			$requested[] = $url;

			return new MockResponse($url);
		});
		$runner = new FiberTaskRunner($http);

		$results = $runner->runPromises([
			'footer' => Promise::async(static fn (FiberHttpClient $client): string =>
				$client->request('GET', 'https://example.test/footer')->getContent()),
			'header' => Promise::async(static fn (FiberHttpClient $client): string =>
				$client->request('GET', 'https://example.test/header')->getContent()),
		]);

		self::assertSame(['footer', 'header'], array_keys($results));
		self::assertCount(2, $requested);
		self::assertSame('https://example.test/header', $results['header']);
	}

	public function testStaticCombinatorsResolveExpectedValues(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient());
		$all = Promise::all([Promise::resolve('a'), 'b']);
		$settled = Promise::allSettled([Promise::resolve('ok'), Promise::reject('no')]);
		$allKeyed = Promise::allKeyed(['first' => Promise::resolve(1), 'second' => 2]);
		$allSettledKeyed = Promise::allSettledKeyed(['ok' => Promise::resolve(true), 'no' => Promise::reject('failure')]);
		$any = Promise::any([Promise::reject('no'), Promise::resolve('winner')]);
		$race = Promise::race([Promise::resolve('first'), Promise::resolve('second')]);

		self::assertSame(['a', 'b'], $all->await($runner));
		self::assertSame([
			['status' => 'fulfilled', 'value' => 'ok'],
			['status' => 'rejected', 'reason' => 'no'],
		], $settled->await($runner));
		self::assertSame(['first' => 1, 'second' => 2], $allKeyed->await($runner));
		self::assertSame([
			'ok' => ['status' => 'fulfilled', 'value' => true],
			'no' => ['status' => 'rejected', 'reason' => 'failure'],
		], $allSettledKeyed->await($runner));
		self::assertSame('winner', $any->await($runner));
		self::assertSame('first', $race->await($runner));
	}

	public function testAnyRejectsWithAllInputReasonsAndTryWrapsSynchronousExceptions(): void
	{
		$runner = new FiberTaskRunner(new MockHttpClient());
		$rejected = Promise::any([Promise::reject('first'), Promise::reject('second')]);
		try {
			$rejected->await($runner);
			self::fail('Promise::any must reject when all inputs reject.');
		} catch (PromiseAggregateError $exception) {
			self::assertSame(['first', 'second'], $exception->errors);
		}

		$tryCalled = false;
		$tryPromise = Promise::try(static function (int $value) use (&$tryCalled): int {
			$tryCalled = true;

			return $value * 2;
		}, 21);
		self::assertTrue($tryCalled);
		self::assertSame(42, $tryPromise->await($runner));
		$failure = Promise::try(static function (): never {
			throw new RuntimeException('sync failure');
		});
		self::assertSame('sync failure', $failure->catch(static fn (Throwable $exception): string => $exception->getMessage())->await($runner));
	}

	public function testWithResolversSettlesAPromiseBeforeAwait(): void
	{
		$resolvers = Promise::withResolvers();
		self::assertTrue($resolvers->promise->isPending());
		$resolvers->resolve('external value');

		self::assertSame('external value', $resolvers->promise->await(new FiberTaskRunner(new MockHttpClient())));
	}

	public function testDeferredPromiseCanBeResolvedByAnotherFiberTask(): void
	{
		$resolvers = Promise::withResolvers();
		$runner = new FiberTaskRunner(new MockHttpClient());
		$results = $runner->runPromises([
			'consumer' => Promise::async(static fn (FiberHttpClient $http): mixed =>
				$resolvers->promise->then(static fn (string $value): string => $value.'!')->execute($http)),
			'producer' => Promise::async(static function (FiberHttpClient $http) use ($resolvers): string {
				$resolvers->resolve('settled');

				return 'producer complete';
			}),
		]);

		self::assertSame('settled!', $results['consumer']);
		self::assertSame('producer complete', $results['producer']);
	}
}
