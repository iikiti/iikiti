<?php

declare(strict_types=1);

namespace iikiti\CMS\Scheduler;

use iikiti\CMS\Batch\BatchScheduleRunner;

final class SymfonySchedulerAdapter implements SchedulerAdapterInterface
{
	public function getKey(): string
	{
		return 'symfony';
	}

	public function runDueSchedules(BatchScheduleRunner $runner): int
	{
		return $runner->runDue();
	}
}
