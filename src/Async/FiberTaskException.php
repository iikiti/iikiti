<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use RuntimeException;
use Throwable;

final class FiberTaskException extends RuntimeException
{
	public function __construct(public readonly string $taskKey, Throwable $previous)
	{
		parent::__construct(sprintf('Fiber task "%s" failed.', $taskKey), 0, $previous);
	}
}
