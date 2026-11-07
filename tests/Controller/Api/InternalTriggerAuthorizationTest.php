<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api;

use iikiti\CMS\Batch\BatchManager;
use iikiti\CMS\Batch\BatchScheduleManager;
use iikiti\CMS\Controller\Api\Internal\BatchWorkerController;
use iikiti\CMS\Controller\Api\Internal\DigitalAssetWorkerController;
use iikiti\CMS\Controller\Api\Internal\BackgroundQueueController;
use iikiti\CMS\Maintenance\BackgroundQueueRunner;
use iikiti\CMS\Service\DigitalAssetWorkflowManager;
use iikiti\CMS\Controller\Api\Internal\SchedulerController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalTriggerAuthorizationTest extends TestCase
{
	public function testScheduleTriggerRejectsRequestsWithoutItsCredential(): void
	{
		$manager = (new \ReflectionClass(BatchScheduleManager::class))->newInstanceWithoutConstructor();
		$controller = new SchedulerController($manager, 'schedule-secret');

		$response = $controller->runDue(Request::create('/api/internal/schedules/run-due', 'POST'));

		self::assertSame(401, $response->getStatusCode());
	}

	public function testBatchWorkerRejectsRequestsWithoutItsCredential(): void
	{
		$manager = (new \ReflectionClass(BatchManager::class))->newInstanceWithoutConstructor();
		$controller = new BatchWorkerController($manager, 'batch-secret');

		$response = $controller->process(Request::create('/api/internal/batches/items/1/process', 'POST'), '1');

		self::assertSame(401, $response->getStatusCode());
	}

	public function testAssetWorkerRejectsRequestsWithoutItsCredential(): void
	{
		$manager = (new \ReflectionClass(DigitalAssetWorkflowManager::class))->newInstanceWithoutConstructor();
		$controller = new DigitalAssetWorkerController($manager, 'asset-secret');

		$response = $controller->process(Request::create('/api/internal/digital-assets/1/process', 'POST'), '1');

		self::assertSame(401, $response->getStatusCode());
	}

	public function testQueueTriggerRejectsRequestsWithoutItsCredential(): void
	{
		$runner = (new \ReflectionClass(BackgroundQueueRunner::class))->newInstanceWithoutConstructor();
		$controller = new BackgroundQueueController($runner, 'queue-secret');

		$response = $controller->run(Request::create('/api/internal/maintenance/run-background-queue', 'POST'));

		self::assertSame(401, $response->getStatusCode());
	}
}
