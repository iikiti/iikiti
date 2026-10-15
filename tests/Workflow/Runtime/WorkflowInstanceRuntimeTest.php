<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow\Runtime;

use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceRuntime;
use iikiti\CMS\Workflow\RuntimeWorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class WorkflowInstanceRuntimeTest extends TestCase
{
	public function testTransitionsPersistMultiPlaceMarkingsOnTheInstance(): void
	{
		$instance = new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('plugin.approval', 'Approval', 'plugin', 'vendor/plugin'),
			1,
			[
				'places' => ['start', 'left', 'right', 'complete'],
				'initialPlaces' => ['start'],
				'finalPlaces' => ['complete'],
				'transitions' => [
					['name' => 'fork', 'from' => ['start'], 'to' => ['left', 'right']],
					['name' => 'join', 'from' => ['left', 'right'], 'to' => ['complete']],
				],
			],
		));
		$runtime = new WorkflowInstanceRuntime(new WorkflowGraphCompiler(), new RuntimeWorkflowFactory(new EventDispatcher()));
		$workflow = $runtime->create($instance);

		self::assertTrue($workflow->can($instance, 'fork'));
		$workflow->apply($instance, 'fork');
		self::assertEquals(['left' => 1, 'right' => 1], $instance->getMarking());
		self::assertTrue($workflow->can($instance, 'join'));

		$workflow->apply($instance, 'join');
		self::assertSame(['complete' => 1], $instance->getMarking());
	}

	public function testAutomaticTransitionsCannotBeInvokedDirectly(): void
	{
		$instance = new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('core.auto', 'Automatic', 'core', 'core'),
			1,
			[
				'places' => ['pending', 'complete'],
				'initialPlaces' => ['pending'],
				'finalPlaces' => ['complete'],
				'transitions' => [['name' => 'auto_finish', 'from' => 'pending', 'to' => 'complete', 'metadata' => ['automatic' => true]]],
			],
		));
		$runtime = new WorkflowInstanceRuntime(new WorkflowGraphCompiler(), new RuntimeWorkflowFactory(new EventDispatcher()));

		$this->expectException(\LogicException::class);
		$runtime->applyTransition($instance, 'auto_finish');
	}
}
