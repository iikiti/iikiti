<?php

declare(strict_types=1);

namespace iikiti\CMS\Maintenance;

use Symfony\Component\Messenger\Worker;

final class BoundedWorkerMessageCounter
{
	private ?Worker $worker = null;
	private int $processed = 0;

	public function __construct(private readonly int $messageLimit)
	{
	}

	public function setWorker(Worker $worker): void
	{
		$this->worker = $worker;
	}

	public function onMessage(object $event): void
	{
		++$this->processed;
		if (null !== $this->worker && $this->processed >= $this->messageLimit) {
			$this->worker->stop();
		}
	}

	public function getProcessedCount(): int
	{
		return $this->processed;
	}
}
