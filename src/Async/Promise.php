<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use Closure;
use Fiber;
use LogicException;
use Throwable;

/**
 * Native-PHP Promise API for operations scheduled by FiberTaskRunner.
 *
 * The executor passed to the constructor runs immediately, like JavaScript's
 * Promise constructor. Fiber-backed work is created with Promise::async() and
 * starts when awaited by a runner. Chained handlers also run when the returned
 * Promise is awaited; PHP has no JavaScript-style microtask queue.
 */
final class Promise
{
	private const PENDING = 0;
	private const FULFILLED = 1;
	private const REJECTED = 2;

	private int $state = self::PENDING;
	private mixed $value = null;
	private mixed $reason = null;
	private ?Closure $operation = null;
	private bool $executing = false;
	/** @var array<int,true> */
	private array $resolvingThenables = [];

	/** @param callable(callable(mixed):void,callable(mixed):void):void $executor */
	public function __construct(callable $executor)
	{
		$settled = false;
		$resolve = function (mixed $value) use (&$settled): void {
			if ($settled) {
				return;
			}
			$settled = true;
			$this->resolveValue($value);
		};
		$reject = function (mixed $reason) use (&$settled): void {
			if ($settled) {
				return;
			}
			$settled = true;
			$this->settleRejected($reason);
		};

		try {
			$executor($resolve, $reject);
		} catch (Throwable $exception) {
			$reject($exception);
		}
	}

	/** Create a lazy operation that can yield through the supported Fiber adapters. */
	public static function async(callable $operation): self
	{
		$promise = self::pending();
		$promise->operation = Closure::fromCallable($operation);

		return $promise;
	}

	public static function resolve(mixed $value = null): self
	{
		if ($value instanceof self) {
			return $value;
		}

		return new self(static function (callable $resolve) use ($value): void {
			$resolve($value);
		});
	}

	public static function resolved(mixed $value = null): self
	{
		return self::resolve($value);
	}

	public static function reject(mixed $reason): self
	{
		return new self(static function (callable $_resolve, callable $reject) use ($reason): void {
			$reject($reason);
		});
	}

	public static function rejected(mixed $reason): self
	{
		return self::reject($reason);
	}

	/** @param iterable<mixed> $values */
	public static function all(iterable $values): self
	{
		$promises = self::normalize($values);
		if ([] === $promises) {
			return self::resolve([]);
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): array {
			$outcomes = self::settleGroup($promises, $http, static function (array $results): bool {
				foreach ($results as $outcome) {
					if ('rejected' === $outcome['status']) {
						return true;
					}
				}

				return false;
			});
			$firstRejection = null;
			foreach ($outcomes as $outcome) {
				if ('rejected' === $outcome['status'] &&
					(null === $firstRejection || $outcome['completion'] < $firstRejection['completion'])) {
					$firstRejection = $outcome;
				}
			}
			if (null !== $firstRejection) {
				throw self::exceptionFor($firstRejection['reason']);
			}

			return array_map(static fn (array $outcome): mixed => $outcome['value'], $outcomes);
		});
	}

	/** @param iterable<mixed> $values */
	public static function allSettled(iterable $values): self
	{
		$promises = self::normalize($values);
		if ([] === $promises) {
			return self::resolve([]);
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): array {
			$outcomes = self::settleGroup($promises, $http);
			return array_map(static function (array $outcome): array {
				return 'fulfilled' === $outcome['status']
					? ['status' => 'fulfilled', 'value' => $outcome['value']]
					: ['status' => 'rejected', 'reason' => $outcome['reason']];
			}, $outcomes);
		});
	}

	/** @param iterable<array-key,mixed> $values */
	public static function allKeyed(iterable $values): self
	{
		$promises = self::normalizeKeyed($values);
		if ([] === $promises) {
			return self::resolve([]);
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): array {
			$outcomes = self::settleGroupKeyed($promises, $http, static function (array $results): bool {
				foreach ($results as $outcome) {
					if ('rejected' === $outcome['status']) {
						return true;
					}
				}

				return false;
			});
			$firstRejection = null;
			foreach ($outcomes as $outcome) {
				if ('rejected' === $outcome['status'] &&
					(null === $firstRejection || $outcome['completion'] < $firstRejection['completion'])) {
					$firstRejection = $outcome;
				}
			}
			if (null !== $firstRejection) {
				throw self::exceptionFor($firstRejection['reason']);
			}

			$values = [];
			foreach ($promises as $key => $_promise) {
				$values[$key] = $outcomes[$key]['value'];
			}

			return $values;
		});
	}

	/** @param iterable<array-key,mixed> $values */
	public static function allSettledKeyed(iterable $values): self
	{
		$promises = self::normalizeKeyed($values);
		if ([] === $promises) {
			return self::resolve([]);
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): array {
			$outcomes = self::settleGroupKeyed($promises, $http);
			$settled = [];
			foreach ($outcomes as $key => $outcome) {
				$settled[$key] = 'fulfilled' === $outcome['status']
					? ['status' => 'fulfilled', 'value' => $outcome['value']]
					: ['status' => 'rejected', 'reason' => $outcome['reason']];
			}

			return $settled;
		});
	}

	/** @param iterable<mixed> $values */
	public static function any(iterable $values): self
	{
		$promises = self::normalize($values);
		if ([] === $promises) {
			return self::reject(new PromiseAggregateError([]));
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): mixed {
			$outcomes = self::settleGroup($promises, $http, static function (array $results) use ($promises): bool {
				foreach ($results as $outcome) {
					if ('fulfilled' === $outcome['status']) {
						return true;
					}
				}

				return count($results) === count($promises);
			});
			$completed = $outcomes;
			usort($completed, static fn (array $left, array $right): int => $left['completion'] <=> $right['completion']);
			foreach ($completed as $outcome) {
				if ('fulfilled' === $outcome['status']) {
					return $outcome['value'];
				}
			}

			$reasons = [];
			foreach ($outcomes as $outcome) {
				$reasons[] = $outcome['reason'];
			}

			throw new PromiseAggregateError($reasons);
		});
	}

	/** @param iterable<mixed> $values */
	public static function race(iterable $values): self
	{
		$promises = self::normalize($values);
		if ([] === $promises) {
			return self::pending();
		}

		return self::async(static function (FiberHttpClient $http) use ($promises): mixed {
			$outcomes = self::settleGroup($promises, $http, static fn (array $results): bool => [] !== $results);
			usort($outcomes, static fn (array $left, array $right): int => $left['completion'] <=> $right['completion']);
			$winner = $outcomes[0];
			if ('rejected' === $winner['status']) {
				throw self::exceptionFor($winner['reason']);
			}

			return $winner['value'];
		});
	}

	public static function try(callable $callback, mixed ...$arguments): self
	{
		try {
			return self::resolve($callback(...$arguments));
		} catch (Throwable $exception) {
			return self::reject($exception);
		}
	}

	public static function withResolvers(): PromiseResolvers
	{
		$promise = self::pending();

		return new PromiseResolvers(
			$promise,
			static function (mixed $value) use ($promise): void {
				$promise->resolveValue($value);
			},
			static function (mixed $reason) use ($promise): void {
				$promise->settleRejected($reason);
			},
		);
	}

	/** @param callable(mixed):mixed|null $onFulfilled @param callable(mixed):mixed|null $onRejected */
	public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
	{
		$parent = $this;
		$onFulfilled = null === $onFulfilled ? null : Closure::fromCallable($onFulfilled);
		$onRejected = null === $onRejected ? null : Closure::fromCallable($onRejected);

		return self::async(static function (FiberHttpClient $http) use ($parent, $onFulfilled, $onRejected): mixed {
			try {
				$value = $parent->execute($http);
			} catch (Throwable $exception) {
				if (null === $onRejected) {
					throw $exception;
				}

				$reason = $parent->isRejected() ? $parent->getReason() : $exception;
				return self::unwrap($onRejected($reason), $http);
			}

			return null === $onFulfilled ? $value : self::unwrap($onFulfilled($value), $http);
		});
	}

	/** @param callable(mixed):mixed $onRejected */
	public function catch(callable $onRejected): self
	{
		return $this->then(null, $onRejected);
	}

	/** @param callable():mixed $onFinally */
	public function finally(callable $onFinally): self
	{
		$parent = $this;
		$onFinally = Closure::fromCallable($onFinally);

		return self::async(static function (FiberHttpClient $http) use ($parent, $onFinally): mixed {
			try {
				$value = $parent->execute($http);
			} catch (Throwable $exception) {
				self::unwrap($onFinally(), $http);
				$reason = $parent->isRejected() ? $parent->getReason() : $exception;
				throw self::exceptionFor($reason);
			}

			self::unwrap($onFinally(), $http);
			return $value;
		});
	}

	public function await(FiberTaskRunner $runner, float $timeoutSeconds = 5.0): mixed
	{
		return $runner->await($this, $timeoutSeconds);
	}

	public function isPending(): bool
	{
		return self::PENDING === $this->state;
	}

	public function isFulfilled(): bool
	{
		return self::FULFILLED === $this->state;
	}

	public function isRejected(): bool
	{
		return self::REJECTED === $this->state;
	}

	public function getValue(): mixed
	{
		if ($this->isPending()) {
			throw new LogicException('A pending Promise has no value until it is awaited.');
		}
		if ($this->isRejected()) {
			throw self::exceptionFor($this->reason);
		}

		return $this->value;
	}

	public function getReason(): mixed
	{
		return $this->reason;
	}

	/** Internal execution entry used by FiberTaskRunner. */
	public function execute(FiberHttpClient $http): mixed
	{
		if ($this->isFulfilled()) {
			return $this->value;
		}
		if ($this->isRejected()) {
			throw self::exceptionFor($this->reason);
		}
		if ($this->executing) {
			if (null === Fiber::getCurrent()) {
				throw new LogicException('A Promise already executing in another Fiber must be awaited inside FiberTaskRunner.');
			}

			Fiber::suspend($this);
			return $this->getValue();
		}

		$this->executing = true;
		try {
			if (null === $this->operation) {
				if (null === Fiber::getCurrent()) {
					throw new LogicException('A pending Promise must be awaited inside FiberTaskRunner.');
				}

				Fiber::suspend($this);
				return $this->getValue();
			}

			$value = self::unwrap(($this->operation)($http), $http);
			if ($this->isPending()) {
				$this->settleFulfilled($value);
			}

			return $this->getValue();
		} catch (Throwable $exception) {
			if ($this->isPending()) {
				$this->settleRejected($exception instanceof PromiseRejectedException ? $exception->reason : $exception);
			}

			throw $exception;
		} finally {
			$this->executing = false;
		}
	}

	/**
	 * @param list<Promise> $promises
	 * @param Closure(array<string,array<string,mixed>>):bool|null $stopWhen
	 * @return list<array{status:'fulfilled',value:mixed,completion:int}|array{status:'rejected',reason:mixed,completion:int}>
	 */
	private static function settleGroup(array $promises, FiberHttpClient $http, ?Closure $stopWhen = null): array
	{
		return array_values(self::settleGroupKeyed($promises, $http, $stopWhen));
	}

	/**
	 * @param array<array-key,Promise> $promises
	 * @param Closure(array<string,array<string,mixed>>):bool|null $stopWhen
	 * @return array<array-key,array{status:'fulfilled',value:mixed,completion:int}|array{status:'rejected',reason:mixed,completion:int}>
	 */
	private static function settleGroupKeyed(array $promises, FiberHttpClient $http, ?Closure $stopWhen = null): array
	{
		$completion = 0;
		$operations = [];
		$taskKeys = [];
		foreach ($promises as $index => $promise) {
			$taskKey = 'promise-'.$index;
			$taskKeys[$taskKey] = $index;
			$operations[$taskKey] = self::async(static function (FiberHttpClient $client) use ($promise, &$completion): array {
				try {
					$value = $promise->execute($client);
					return ['status' => 'fulfilled', 'value' => $value, 'completion' => $completion++];
				} catch (Throwable $exception) {
					$reason = $promise->isRejected() ? $promise->getReason() : $exception;
					return ['status' => 'rejected', 'reason' => $reason, 'completion' => $completion++];
				}
			});
		}

		$results = null === $stopWhen
			? $http->runPromises($operations)
			: $http->runPromisesUntil($operations, static fn (array $results, array $errors): bool => $stopWhen($results));

		$ordered = [];
		foreach ($taskKeys as $taskKey => $originalKey) {
			if (array_key_exists($taskKey, $results)) {
				$ordered[$originalKey] = $results[$taskKey];
			}
		}

		return $ordered;
	}

	/**
	 * @param iterable<mixed> $values
	 * @return list<Promise>
	 */
	private static function normalize(iterable $values): array
	{
		$promises = [];
		foreach ($values as $value) {
			$promises[] = self::resolve($value);
		}

		return $promises;
	}

	/**
	 * @param iterable<array-key,mixed> $values
	 * @return array<array-key,Promise>
	 */
	private static function normalizeKeyed(iterable $values): array
	{
		$promises = [];
		foreach ($values as $key => $value) {
			$promises[$key] = self::resolve($value);
		}

		return $promises;
	}

	private static function unwrap(mixed $value, FiberHttpClient $http): mixed
	{
		return $value instanceof self || (is_object($value) && is_callable([$value, 'then']))
			? self::resolve($value)->execute($http)
			: $value;
	}

	private function resolveValue(mixed $value): void
	{
		if (!$this->isPending()) {
			return;
		}
		if ($value === $this) {
			$this->settleRejected(new LogicException('A Promise cannot be resolved with itself.'));

			return;
		}
		if ($value instanceof self) {
			if ($value->isFulfilled()) {
				$this->settleFulfilled($value->getValue());
			} elseif ($value->isRejected()) {
				$this->settleRejected($value->getReason());
			} else {
				$this->operation = static fn (FiberHttpClient $http): mixed => $value->execute($http);
			}

			return;
		}
		if (is_object($value) && is_callable([$value, 'then'])) {
			$thenableId = spl_object_id($value);
			if (isset($this->resolvingThenables[$thenableId])) {
				$this->settleRejected(new LogicException('A Promise cannot resolve a thenable cycle.'));

				return;
			}
			$this->resolvingThenables[$thenableId] = true;
			$settled = false;
			$resolve = function (mixed $resolved) use (&$settled): void {
				if ($settled) {
					return;
				}
				$settled = true;
				$this->resolveValue($resolved);
			};
			$reject = function (mixed $rejected) use (&$settled): void {
				if ($settled) {
					return;
				}
				$settled = true;
				$this->settleRejected($rejected);
			};
			try {
				$value->then($resolve, $reject);
			} catch (Throwable $exception) {
				$reject($exception);
			}

			return;
		}

		$this->settleFulfilled($value);
	}

	private function settleFulfilled(mixed $value): void
	{
		if (!$this->isPending()) {
			return;
		}
		$this->state = self::FULFILLED;
		$this->value = $value;
		$this->resolvingThenables = [];
	}

	private function settleRejected(mixed $reason): void
	{
		if (!$this->isPending()) {
			return;
		}
		$this->state = self::REJECTED;
		$this->reason = $reason;
		$this->resolvingThenables = [];
	}

	private static function exceptionFor(mixed $reason): Throwable
	{
		return $reason instanceof Throwable ? $reason : new PromiseRejectedException($reason);
	}

	private static function pending(): self
	{
		return new self(static function (callable $_resolve, callable $_reject): void {
		});
	}
}
