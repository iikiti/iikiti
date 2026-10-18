<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Scheduler;

use iikiti\CMS\Batch\BatchScheduleRunner;
use iikiti\CMS\Scheduler\SchedulerAdapterInterface;
use iikiti\CMS\Scheduler\SchedulerAdapterRegistry;
use PHPUnit\Framework\TestCase;

final class SchedulerAdapterRegistryTest extends TestCase
{
	public function testSelectsConfiguredAlternativeAdapter(): void
	{
		$adapter = new class implements SchedulerAdapterInterface {
			public function getKey(): string { return 'external'; }
			public function runDueSchedules(BatchScheduleRunner $runner): int { return 0; }
		};
		$registry = new SchedulerAdapterRegistry([$adapter], 'external');

		self::assertSame('external', $registry->getSelectedKey());
		self::assertSame($adapter, $registry->getSelected());
	}

	public function testRejectsUnregisteredConfiguredAdapter(): void
	{
		$this->expectException(\LogicException::class);
		new SchedulerAdapterRegistry([], 'missing');
	}
}
