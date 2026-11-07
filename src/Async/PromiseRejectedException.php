<?php

declare(strict_types=1);

namespace iikiti\CMS\Async;

use RuntimeException;

final class PromiseRejectedException extends RuntimeException
{
	public function __construct(public readonly mixed $reason)
	{
		$message = is_string($reason) ? $reason : 'Promise was rejected with a non-Throwable reason.';
		parent::__construct($message, 0, $reason instanceof \Throwable ? $reason : null);
	}
}
