<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support\Async;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Deterministic, zero-sleep HttpClientInterface for testing Fiber interleaving
 * and transport priming without timers or network sockets.
 */
final class ControllableHttpClient implements HttpClientInterface
{
	/** @var list<array{matcher:callable(string,string,array<string,mixed>):bool,factory:callable(string,string,array<string,mixed>):ControllableResponse}> */
	private array $rules = [];
	/** @var \Closure(string,string,array<string,mixed>):ControllableResponse|null */
	private ?\Closure $fallback = null;
	/** @var list<string> */
	private array $events = [];
	/** @var list<array{method:string,url:string,options:array<string,mixed>}> */
	private array $requests = [];
	private int $inFlight = 0;
	private int $maxConcurrentInFlight = 0;

	/** @param (callable(string,string,array<string,mixed>):ControllableResponse)|null $fallback */
	public function __construct(?callable $fallback = null)
	{
		if (null !== $fallback) {
			$this->fallback = \Closure::fromCallable($fallback);
		}
	}

	/**
	 * @param callable(string,string,array<string,mixed>):bool $matcher
	 * @param callable(string,string,array<string,mixed>):ControllableResponse $factory
	 */
	public function when(callable $matcher, callable $factory): self
	{
		$this->rules[] = ['matcher' => $matcher, 'factory' => $factory];

		return $this;
	}

	/** @param callable(string,string,array<string,mixed>):ControllableResponse $factory */
	public function whenUrlContains(string $needle, callable $factory): self
	{
		return $this->when(
			static fn (string $_method, string $url): bool => str_contains($url, $needle),
			$factory,
		);
	}

	public function recordEvent(string $event): void
	{
		$this->events[] = $event;
	}

	/** @return list<string> */
	public function getEvents(): array
	{
		return $this->events;
	}

	/** @return list<array{method:string,url:string,options:array<string,mixed>}> */
	public function getRequests(): array
	{
		return $this->requests;
	}

	public function getInFlightCount(): int
	{
		return $this->inFlight;
	}

	public function getMaxConcurrentInFlight(): int
	{
		return $this->maxConcurrentInFlight;
	}

	/** @param array<string,mixed> $options */
	public function request(string $method, string $url, array $options = []): ResponseInterface
	{
		if (is_array($options['query'] ?? null) && [] !== $options['query']) {
			$queryString = http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
			if ('' !== $queryString) {
				$url .= (str_contains($url, '?') ? '&' : '?').$queryString;
			}
		}

		$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
		$response = $this->createResponse($method, $url, $options);
		++$this->inFlight;
		if ($this->inFlight > $this->maxConcurrentInFlight) {
			$this->maxConcurrentInFlight = $this->inFlight;
		}
		$finished = false;
		$response->bindRequest($method, $url, $options['user_data'] ?? null, function () use (&$finished, $response): void {
			if (!$finished) {
				$finished = true;
				--$this->inFlight;
				if ($response->isCanceled()) {
					$this->events[] = 'cancel:'.$response->key();
				}
			}
		});
		$this->events[] = 'request:'.$response->key();

		return $response;
	}

	public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
	{
		$list = $responses instanceof ResponseInterface ? [$responses] : $responses;
		$active = [];
		foreach ($list as $response) {
			if (!$response instanceof ControllableResponse) {
				throw new \InvalidArgumentException('ControllableHttpClient can only stream ControllableResponse instances.');
			}
			$active[] = $response;
		}

		$isPrime = null !== $timeout && $timeout <= 0.01;
		$events = &$this->events;

		$generator = (static function () use ($active, $isPrime, &$events): \Generator {
			if ($isPrime) {
				foreach ($active as $response) {
					if ($response->isCanceled() || $response->isCompleted()) {
						continue;
					}
					$events[] = 'prime:'.$response->key();
					if ($response->canRelease()) {
						while (null !== ($chunk = $response->nextChunk())) {
							if ($chunk->isLast()) {
								$events[] = 'complete:'.$response->key();
							} elseif (!$chunk->isFirst()) {
								$events[] = 'chunk:'.$response->key();
							}
							yield $response => $chunk;
							if ($chunk->isLast() || null !== $chunk->getError()) {
								break;
							}
						}
					} elseif ($response->shouldPrimeTimeout()) {
						yield $response => ControllableChunk::timeout();
					}
				}

				return;
			}

			while ([] !== $active) {
				$progressed = false;
				foreach ($active as $index => $response) {
					if ($response->isCanceled() || $response->isCompleted()) {
						unset($active[$index]);
						continue;
					}
					if ($response->isForceTimeout()) {
						$progressed = true;
						$chunk = $response->nextChunk();
						unset($active[$index]);
						if (null !== $chunk) {
							yield $response => $chunk;
						}
						continue;
					}
					if (!$response->canRelease()) {
						continue;
					}
					$progressed = true;
					$chunk = $response->nextChunk();
					if (null === $chunk) {
						unset($active[$index]);
						continue;
					}
					if ($chunk->isLast()) {
						$events[] = 'complete:'.$response->key();
						unset($active[$index]);
					} elseif (!$chunk->isFirst() && null === $chunk->getError()) {
						$events[] = 'chunk:'.$response->key();
					} elseif (null !== $chunk->getError()) {
						$events[] = 'error:'.$response->key();
						unset($active[$index]);
					}
					yield $response => $chunk;
				}
				if (!$progressed && [] !== $active) {
					$keys = array_map(static fn (ControllableResponse $r): string => $r->key(), $active);
					throw new \LogicException('ControllableHttpClient detected a deadlock on held responses: '.implode(', ', $keys));
				}
			}
		})();

		return new ControllableResponseStream($generator);
	}

	/** @param array<string,mixed> $options */
	public function withOptions(array $options): static
	{
		return clone $this;
	}

	/** @param array<string,mixed> $options */
	private function createResponse(string $method, string $url, array $options): ControllableResponse
	{
		foreach ($this->rules as $rule) {
			if (($rule['matcher'])($method, $url, $options)) {
				return ($rule['factory'])($method, $url, $options);
			}
		}
		if (null !== $this->fallback) {
			return ($this->fallback)($method, $url, $options);
		}

		return ControllableResponse::ok('');
	}
}
