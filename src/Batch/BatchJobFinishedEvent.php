<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Symfony\Contracts\EventDispatcher\Event;

final class BatchJobFinishedEvent extends Event
{
	public function __construct(public readonly int|string $jobId, public readonly string $status)
	{
	}
}
