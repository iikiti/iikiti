<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Batch;

use iikiti\CMS\Entity\Batch\BatchEvent;
use iikiti\CMS\Entity\Batch\BatchJob;
use PHPUnit\Framework\TestCase;

final class BatchEventTest extends TestCase
{
	public function testRedactsCredentialFieldsFromEvents(): void
	{
		$job = new BatchJob('Transfer', 'site.transfer.import', 1, 2, 3, true);
		$event = new BatchEvent($job, 'message', 'Source token=hidden', ['token' => 'hidden', 'itemKey' => 'page-1', 'attempt' => 2]);

		self::assertSame('Source token=[redacted]', $event->getSummary());
		self::assertSame(['itemKey' => 'page-1', 'attempt' => 2], $event->getDetails());
	}
}
