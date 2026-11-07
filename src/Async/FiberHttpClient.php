<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use iikiti\CMS\Profiler\AsyncResponseDataCollector;
use Closure;
use Fiber;
use LogicException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Creates lazy Symfony HTTP responses and suspends the current managed Fiber. */
final class FiberHttpClient
{
	public function __construct(
		private readonly HttpClientInterface $client,
		private readonly float $deadline,
		private readonly FiberTaskRunner $fiberTaskRunner,
		private readonly ?AsyncResponseDataCollector $profiler = null,
	) {
	}

	/** @param array<array-key,Promise> $promises
	 *  @return array<string,mixed>
	 */
	public function runPromises(array $promises): array
	{
		$remaining = $this->deadline - microtime(true);
		if ($remaining <= 0) {
			throw new \Symfony\Component\HttpClient\Exception\TimeoutException('The Fiber task group deadline has elapsed.');
		}

		try {
			return $this->fiberTaskRunner->runPromises($promises, $remaining);
		} catch (FiberTaskException $exception) {
			throw $exception->getPrevious() ?? $exception;
		}
	}

	/**
	 * @param array<array-key,Promise> $promises
	 * @param Closure(array<string,mixed>,array<string,\Throwable>):bool $stopWhen
	 * @return array<string,mixed>
	 */
	public function runPromisesUntil(array $promises, Closure $stopWhen): array
	{
		$remaining = $this->deadline - microtime(true);
		if ($remaining <= 0) {
			throw new \Symfony\Component\HttpClient\Exception\TimeoutException('The Fiber task group deadline has elapsed.');
		}

		try {
			return $this->fiberTaskRunner->runPromisesUntil($promises, $stopWhen, $remaining);
		} catch (FiberTaskException $exception) {
			throw $exception->getPrevious() ?? $exception;
		}
	}

	/** @param array<string,mixed> $options */
	public function request(string $method, string $url, array $options = []): ResponseInterface
	{
		if (null === Fiber::getCurrent()) {
			throw new LogicException('FiberHttpClient requests must run inside FiberTaskRunner.');
		}

		$remaining = $this->deadline - microtime(true);
		if ($remaining <= 0) {
			throw new \Symfony\Component\HttpClient\Exception\TimeoutException('The Fiber task group deadline has elapsed.');
		}

		$options += [
			'timeout' => $remaining,
			'max_duration' => $remaining,
		];
		$this->profiler?->recordHttpRequest();
		$startedAt = hrtime(true);
		$response = null;
		$status = null;
		$failed = false;

		try {
			$response = $this->client->request($method, $url, $options);
			$completion = Fiber::suspend($response);
			if ($completion instanceof \Throwable) {
				throw $completion;
			}

			return $response;
		} catch (\Throwable $exception) {
			$failed = true;
			throw $exception;
		} finally {
			if (null !== $response) {
				try {
					$httpCode = $response->getInfo('http_code');
					$status = is_numeric($httpCode) && (int) $httpCode >= 100 ? (int) $httpCode : null;
				} catch (\Throwable) {
					$status = null;
				}
			}
			try {
				$this->profiler?->recordAsyncRequest(
					$method,
					$url,
					$status,
					(hrtime(true) - $startedAt) / 1_000_000,
					$failed,
					is_array($options['query'] ?? null) ? $options['query'] : [],
				);
			} catch (\Throwable) {
			}
		}
	}
}
