<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Batch;

use iikiti\CMS\Entity\Batch\BatchSchedule;
use PHPUnit\Framework\TestCase;

final class BatchScheduleTest extends TestCase
{
	public function testScheduleComputesNextRunInConfiguredTimezone(): void
	{
		$schedule = new BatchSchedule('Nightly', 'vendor.task', '0 3 * * *', 'Europe/London', 'site', 5, [], true, false, 10);

		self::assertSame('Europe/London', $schedule->getTimezone());
		self::assertGreaterThan(new \DateTimeImmutable(), $schedule->getNextRunAt());
	}

	public function testRejectsSiteScopeWithoutSiteId(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new BatchSchedule('Invalid', 'vendor.task', '0 * * * *', 'UTC', 'site', null, [], true, false, 1);
	}

	public function testRejectsInvalidCronExpression(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new BatchSchedule('Invalid', 'vendor.task', 'every day', 'UTC', 'global', null, [], true, false, 1);
	}
}
