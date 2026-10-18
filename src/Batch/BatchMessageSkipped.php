<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

final class BatchMessageSkipped extends \RuntimeException
{
	private readonly string $safeReason;

	public function __construct(string $reason)
	{
		$this->safeReason = preg_match('/token|password|secret|credential/i', $reason) ? 'Message was skipped because required access was unavailable.' : mb_substr($reason, 0, 400);
		parent::__construct($this->safeReason);
	}

	public function getSafeReason(): string
	{
		return $this->safeReason;
	}
}
