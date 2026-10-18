<?php

declare(strict_types=1);

namespace iikiti\CMS\Scheduler;

use iikiti\CMS\Message\RunDueBatchSchedules;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class DefaultScheduleProvider implements ScheduleProviderInterface
{
	public function __construct(private readonly SchedulerAdapterRegistry $adapters)
	{
	}

	public function getSchedule(): Schedule
	{
		$schedule = new Schedule();
		if ('symfony' === $this->adapters->getSelectedKey()) {
			$schedule->add(RecurringMessage::every('1 minute', new RunDueBatchSchedules()));
		}

		return $schedule->processOnlyLastMissedRun(false);
	}
}
