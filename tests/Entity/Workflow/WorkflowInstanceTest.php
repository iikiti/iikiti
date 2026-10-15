<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Entity\Workflow;

use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use PHPUnit\Framework\TestCase;

final class WorkflowInstanceTest extends TestCase
{
	public function testInitialMarkingAndPauseLifecycle(): void
	{
		$definition = new WorkflowDefinition('approval.review', 'Approval', 'core', 'core');
		$version = new WorkflowDefinitionVersion($definition, 1, [
			'places' => ['draft', 'complete'],
			'initialPlaces' => ['draft'],
			'finalPlaces' => ['complete'],
			'transitions' => [],
		]);
		$instance = new WorkflowInstance($version, context: ['request_id' => 'r-1']);

		self::assertSame(['draft' => 1], $instance->getMarking());
		self::assertSame(['request_id' => 'r-1'], $instance->getContext());
		self::assertSame('active', $instance->getExecutionState());

		$instance->pause('Plugin handler unavailable');
		self::assertTrue($instance->isPaused());
		self::assertSame('Plugin handler unavailable', $instance->getPauseReason());

		$instance->resume();
		self::assertFalse($instance->isPaused());
		self::assertNull($instance->getPauseReason());

		$instance->setMarking(['complete' => 1]);
		self::assertSame(['complete' => 1], $instance->getMarking());
	}

	public function testSubjectReferenceMustBeComplete(): void
	{
		$version = new WorkflowDefinitionVersion(
			new WorkflowDefinition('approval.review', 'Approval', 'core', 'core'),
			1,
			['initialPlaces' => ['draft']],
		);

		$this->expectException(\InvalidArgumentException::class);
		new WorkflowInstance($version, subjectType: 'asset', subjectId: null);
	}

	public function testVersionNumbersArePositive(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new WorkflowDefinitionVersion(
			new WorkflowDefinition('approval.review', 'Approval', 'core', 'core'),
			0,
			['initialPlaces' => ['draft']],
		);
	}
}
