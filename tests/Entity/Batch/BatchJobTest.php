<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Batch;

use iikiti\CMS\Entity\Batch\BatchJob;
use PHPUnit\Framework\TestCase;

final class BatchJobTest extends TestCase
{
	public function testProgressRemainsIndeterminateUntilTotalIsKnown(): void
	{
		$job = new BatchJob('Import', 'site.transfer.import', 1, 2, 3, false);
		$job->setCounts(20, 5, false);

		self::assertNull($job->getProgressPercent());
	}

	public function testProgressUsesTerminalItemCountAndHandlesEmptyJobs(): void
	{
		$job = new BatchJob('Batch', null, null, null, 3, true);
		$job->setCounts(4, 1);
		self::assertSame(25, $job->getProgressPercent());

		$job->setCounts(0, 0);
		self::assertSame(0, $job->getProgressPercent());
		$job->setStatus(BatchJob::STATUS_COMPLETED);
		self::assertSame(100, $job->getProgressPercent());
	}

	public function testAttemptCountIsBounded(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new BatchJob('Invalid', null, null, null, 26, true);
	}
}
