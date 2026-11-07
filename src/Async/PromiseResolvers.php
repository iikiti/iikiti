<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use Closure;

/** Equivalent to the object returned by JavaScript Promise.withResolvers(). */
final readonly class PromiseResolvers
{
	/** @param Closure(mixed):void $resolve @param Closure(mixed):void $reject */
	public function __construct(
		public Promise $promise,
		public Closure $resolve,
		public Closure $reject,
	) {
	}

	public function resolve(mixed $value): void
	{
		($this->resolve)($value);
	}

	public function reject(mixed $reason): void
	{
		($this->reject)($reason);
	}
}
