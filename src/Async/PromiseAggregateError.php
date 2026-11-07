<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use RuntimeException;

final class PromiseAggregateError extends RuntimeException
{
	/** @param list<mixed> $errors */
	public function __construct(public readonly array $errors)
	{
		parent::__construct(sprintf('All %d Promises were rejected.', count($errors)));
	}
}
