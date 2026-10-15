<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow\Policy;

use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowGuardSubscriber;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyContext;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyInterface;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceRuntime;
use iikiti\CMS\Workflow\RuntimeWorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

final class WorkflowGuardSubscriberTest extends TestCase
{
	public function testMissingPolicyFailsClosedBeforeTransition(): void
	{
		$instance = new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('core.review', 'Review', 'core', 'core'),
			1,
			[
				'places' => ['pending', 'complete'],
				'initialPlaces' => ['pending'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'missing.policy'],
				'transitions' => [['name' => 'finish', 'from' => 'pending', 'to' => 'complete']],
			],
		));
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new WorkflowGuardSubscriber(new WorkflowPolicyRegistry()));
		$runtime = new WorkflowInstanceRuntime(new WorkflowGraphCompiler(), new RuntimeWorkflowFactory($dispatcher));

		$this->expectException(NotEnabledTransitionException::class);
		$runtime->applyTransition($instance, 'finish');
	}

	public function testRegisteredPolicyCanBlockTransition(): void
	{
		$policy = new class implements WorkflowPolicyInterface {
			public function getKey(): string
			{
				return 'test.deny';
			}

			public function isAllowed(WorkflowPolicyContext $context): bool
			{
				return false;
			}
		};
		$instance = new WorkflowInstance(new WorkflowDefinitionVersion(
			new WorkflowDefinition('plugin.review', 'Review', 'plugin', 'vendor/plugin'),
			1,
			[
				'places' => ['pending', 'complete'],
				'initialPlaces' => ['pending'],
				'finalPlaces' => ['complete'],
				'metadata' => ['policyKey' => 'test.deny'],
				'transitions' => [['name' => 'finish', 'from' => 'pending', 'to' => 'complete', 'metadata' => ['guardKeys' => ['test.deny']]]],
			],
		));
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new WorkflowGuardSubscriber(new WorkflowPolicyRegistry([$policy])));
		$runtime = new WorkflowInstanceRuntime(new WorkflowGraphCompiler(), new RuntimeWorkflowFactory($dispatcher));

		try {
			$runtime->applyTransition($instance, 'finish');
			self::fail('The registered guard must block the transition.');
		} catch (NotEnabledTransitionException) {
			self::assertSame(['pending' => 1], $instance->getMarking());
		}
	}
}
