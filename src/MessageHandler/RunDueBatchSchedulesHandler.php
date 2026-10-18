<?php

declare(strict_types=1);

namespace iikiti\CMS\MessageHandler;

use iikiti\CMS\Batch\BatchScheduleRunner;
use iikiti\CMS\Message\RunDueBatchSchedules;
use iikiti\CMS\Scheduler\SchedulerAdapterRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunDueBatchSchedulesHandler
{
	public function __construct(private readonly SchedulerAdapterRegistry $adapters, private readonly BatchScheduleRunner $runner)
	{
	}

	public function __invoke(RunDueBatchSchedules $message): void
	{
		$this->adapters->runDueSchedules($this->runner);
	}
}
