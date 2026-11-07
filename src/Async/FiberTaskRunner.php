<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use Fiber;
use LogicException;
use Closure;
use Throwable;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Runs request-local Fibers and multiplexes their suspended HTTP responses. */
final class FiberTaskRunner
{
	private const MAX_TASKS = 32;
	private const DEFAULT_TIMEOUT_SECONDS = 5.0;

	public function __construct(
		private readonly HttpClientInterface $httpClient,
		private readonly ?AsyncResponseDataCollector $profiler = null,
	)
	{
	}

	/** @param array<array-key,\Closure(FiberHttpClient):mixed> $operations
	 *  @return array<string,mixed> Results in input order
	 */
	public function run(array $operations, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
	{
		return $this->runDuring($operations, static fn (): null => null, $timeoutSeconds)['tasks'];
	}

	public function await(Promise $promise, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): mixed
	{
		if (!$promise->isPending()) {
			return $promise->getValue();
		}

		try {
			return $this->run([
				'promise' => static fn (FiberHttpClient $http): mixed => $promise->execute($http),
			], $timeoutSeconds)['promise'];
		} catch (FiberTaskException $exception) {
			throw $exception->getPrevious() ?? $exception;
		}
	}

	/**
	 * @param array<array-key,mixed> $promises
	 * @return array<string,mixed> Results in input order
	 */
	public function runPromises(array $promises, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
	{
		$operations = [];
		foreach ($promises as $key => $promise) {
			if (!is_string($key)) {
				throw new \InvalidArgumentException('Promise keys must be strings.');
			}
			if (!$promise instanceof Promise) {
				throw new \InvalidArgumentException(sprintf('Promise group item "%s" is not a Promise.', $key));
			}
			$operations[$key] = static fn (FiberHttpClient $http): mixed => $promise->execute($http);
		}

		return $this->run($operations, $timeoutSeconds);
	}

	/**
	 * @param array<array-key,mixed> $promises
	 * @param Closure(array<string,mixed>,array<string,Throwable>):bool $stopWhen
	 * @return array<string,mixed> Results completed before the stop condition
	 */
	public function runPromisesUntil(array $promises, Closure $stopWhen, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
	{
		$operations = [];
		foreach ($promises as $key => $promise) {
			if (!is_string($key)) {
				throw new \InvalidArgumentException('Promise keys must be strings.');
			}
			if (!$promise instanceof Promise) {
				throw new \InvalidArgumentException(sprintf('Promise group item "%s" is not a Promise.', $key));
			}
			$operations[$key] = static fn (FiberHttpClient $http): mixed => $promise->execute($http);
		}

		return $this->runUntil($operations, $stopWhen, $timeoutSeconds);
	}

	/**
	 * Starts all cooperative operations, runs local work while their HTTP I/O is
	 * outstanding, then waits for and returns the task results.
	 * Synchronous operations in either the tasks or callback still block the PHP
	 * process until they return.
	 *
	 * @param array<array-key,\Closure(FiberHttpClient):mixed> $operations
	 * @return array{tasks:array<string,mixed>,work:mixed}
	 */
	public function runDuring(array $operations, Closure $work, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
	{
		return $this->executeGroup($operations, $work, $timeoutSeconds, null);
	}

	/**
	 * Runs operations until the completion predicate is true, cancelling remaining
	 * HTTP responses. Used for Promise.race() and Promise.any().
	 *
	 * @param array<array-key,\Closure(FiberHttpClient):mixed> $operations
	 * @param Closure(array<string,mixed>,array<string,Throwable>):bool $stopWhen
	 * @return array<string,mixed> Results completed before the stop condition
	 */
	public function runUntil(array $operations, Closure $stopWhen, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
	{
		return $this->executeGroup($operations, static fn (): null => null, $timeoutSeconds, $stopWhen)['tasks'];
	}

	/**
	 * @param array<array-key,\Closure(FiberHttpClient):mixed> $operations
	 * @param Closure(array<string,mixed>,array<string,Throwable>):bool|null $stopWhen
	 * @return array{tasks:array<string,mixed>,work:mixed}
	 */
	private function executeGroup(array $operations, Closure $work, float $timeoutSeconds, ?Closure $stopWhen): array
	{
		if ([] === $operations) {
			return ['tasks' => [], 'work' => $work()];
		}

		$tasks = [];
		foreach ($operations as $key => $operation) {
			if (!is_string($key)) {
				throw new \InvalidArgumentException('Fiber task keys must be strings.');
			}
			$tasks[$key] = $operation;
		}
		if (count($tasks) > self::MAX_TASKS) {
			throw new \InvalidArgumentException(sprintf('A Fiber task group may contain at most %d operations.', self::MAX_TASKS));
		}
		if ($timeoutSeconds <= 0) {
			throw new \InvalidArgumentException('The Fiber task group timeout must be positive.');
		}
		$deadline = microtime(true) + $timeoutSeconds;
		$client = new FiberHttpClient($this->httpClient, $deadline, $this, $this->profiler);
		/** @var array<string,\Fiber<mixed,mixed,mixed,mixed>> $fibers */
		$fibers = [];
		/** @var array<string,ResponseInterface> $pending */
		$pending = [];
		/** @var array<string,Promise> $pendingPromises */
		$pendingPromises = [];
		/** @var array<string,mixed> $results */
		$results = [];
		/** @var array<string,Throwable> $errors */
		$errors = [];

		$advance = function (string $key, ?Throwable $failure = null) use (&$fibers, &$pending, &$pendingPromises, &$results, &$errors): void {
			$fiber = $fibers[$key];
			try {
				if (!$fiber->isStarted()) {
					$suspended = $fiber->start();
				} elseif (null !== $failure) {
					$suspended = $fiber->throw($failure);
				} else {
					$suspended = $fiber->resume();
				}
			} catch (Throwable $exception) {
				unset($pending[$key]);
				$errors[$key] = $exception;

				return;
			}

			if ($fiber->isTerminated()) {
				unset($pending[$key]);
				$results[$key] = $fiber->getReturn();

				return;
			}

			if (!$suspended instanceof ResponseInterface) {
				if ($suspended instanceof Promise && $suspended->isPending()) {
					$pendingPromises[$key] = $suspended;

					return;
				}

				$exception = new LogicException('A managed Fiber may only suspend on a FiberHttpClient response or pending Promise.');
				try {
					$fiber->throw($exception);
				} catch (Throwable $taskException) {
					$exception = $taskException;
				}
				$errors[$key] = $exception;

				return;
			}

			$pending[$key] = $suspended;
		};
		$resumeSettledPromises = function () use (&$pendingPromises, &$advance): void {
			do {
				$progressed = false;
				foreach ($pendingPromises as $key => $promise) {
					if (!$promise->isPending()) {
						unset($pendingPromises[$key]);
						$advance($key);
						$progressed = true;
					}
				}
			} while ($progressed && [] !== $pendingPromises);
		};

		foreach ($tasks as $key => $operation) {
			$fibers[$key] = new Fiber(static fn (): mixed => $operation($client));
		}
		foreach (array_keys($fibers) as $key) {
			$advance($key);
			$resumeSettledPromises();
		}
		$stopped = null !== $stopWhen && $stopWhen($results, $errors);

		$workStartedAt = hrtime(true);
		$workResult = null;
		$workException = null;
		if (!$stopped) {
			try {
				$workResult = $work();
		} catch (Throwable $exception) {
			$workException = $exception;
		}
		$resumeSettledPromises();
		}
		$workTimeMs = (hrtime(true) - $workStartedAt) / 1_000_000;
		$waitStartedAt = hrtime(true);

		while (([] !== $pending || [] !== $pendingPromises) && !$stopped) {
			if ([] === $pending) {
				foreach (array_keys($pendingPromises) as $key) {
					$advance($key, new LogicException('A Promise awaited by this task group has no active resolver.'));
				}
				$resumeSettledPromises();

				continue;
			}
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				$this->failPending($pending, $advance, new TimeoutException('The Fiber task group timed out.'));
				foreach (array_keys($pendingPromises) as $key) {
					$advance($key, new TimeoutException('The Fiber task group timed out.'));
				}
				$resumeSettledPromises();
				break;
			}

			try {
				foreach ($this->httpClient->stream($pending, $remaining) as $response => $chunk) {
					$key = array_search($response, $pending, true);
					if (false === $key) {
						continue;
					}

					try {
						if (null !== $chunk->getError()) {
							$chunk->isLast();
						}
						if (!$chunk->isLast()) {
							continue;
						}

						unset($pending[$key]);
						$advance($key);
						$resumeSettledPromises();
					} catch (Throwable $exception) {
						unset($pending[$key]);
						$advance($key, $exception);
						$resumeSettledPromises();
					}
					if (null !== $stopWhen && $stopWhen($results, $errors)) {
						$stopped = true;
						break;
					}
				}
			} catch (Throwable $exception) {
				$this->failPending($pending, $advance, $exception);
			}
		}
		if ($stopped) {
			foreach ($pending as $response) {
				$response->cancel();
			}
			$cancelled = new TimeoutException('The Promise group completed before this task.');
			foreach (array_keys($pending) as $key) {
				$advance($key, $cancelled);
			}
			foreach (array_keys($pendingPromises) as $key) {
				$advance($key, $cancelled);
			}
			$pending = [];
			$pendingPromises = [];
		}
		$this->profiler?->recordFiberGroup(count($tasks), $workTimeMs, (hrtime(true) - $waitStartedAt) / 1_000_000, count($errors));

		if (null !== $workException) {
			throw $workException;
		}

		foreach (array_keys($tasks) as $key) {
			if (isset($errors[$key])) {
				throw new FiberTaskException($key, $errors[$key]);
			}
		}

		$ordered = [];
		foreach (array_keys($tasks) as $key) {
			if (array_key_exists($key, $results)) {
				$ordered[$key] = $results[$key];
			}
		}

		return ['tasks' => $ordered, 'work' => $workResult];
	}

	/**
	 * @param array<string,ResponseInterface> $pending
	 * @param callable(string,?Throwable):void $advance
	 */
	private function failPending(array &$pending, callable $advance, Throwable $exception): void
	{
		foreach ($pending as $key => $response) {
			unset($pending[$key]);
			$response->cancel();
			$advance($key, $exception);
		}
	}
}
