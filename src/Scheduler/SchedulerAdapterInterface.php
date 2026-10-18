<?php

declare(strict_types=1);

namespace iikiti\CMS\Scheduler;

use iikiti\CMS\Batch\BatchScheduleRunner;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.scheduler_adapter')]
interface SchedulerAdapterInterface
{
	public function getKey(): string;

	public function runDueSchedules(BatchScheduleRunner $runner): int;
}
