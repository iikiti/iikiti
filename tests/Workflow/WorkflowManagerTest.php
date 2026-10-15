<?php

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphTransition;
use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use iikiti\CMS\Workflow\StepProviderInterface;
use iikiti\CMS\Workflow\StepSubscriber;
use iikiti\CMS\Workflow\WorkflowGraphProviderInterface;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowManager;
use iikiti\CMS\Workflow\WorkflowStepInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class WorkflowManagerTest extends TestCase
{
	public function testBuildWorkflowAddsStepsFromProviders(): void
	{
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new StepSubscriber([$this->createProvider('demo', true, ['a', 'b'])]));
		$manager = new WorkflowManager($dispatcher);

		$workflow = $manager->buildWorkflow('demo', ['any' => 'context']);

		$this->assertSame('demo', $workflow->getName());
		$this->assertCount(2, $workflow->getSteps());
		$this->assertSame('a', $workflow->getCurrentStep()?->getId());
	}

	public function testProviderIsScopedToItsWorkflowName(): void
	{
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new StepSubscriber([$this->createProvider('demo', true, ['a'])]));
		$manager = new WorkflowManager($dispatcher);

		$workflow = $manager->buildWorkflow('other');

		$this->assertCount(0, $workflow->getSteps());
	}

	public function testProviderThatDoesNotSupportContextIsSkipped(): void
	{
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new StepSubscriber([$this->createProvider('demo', false, ['a'])]));
		$manager = new WorkflowManager($dispatcher);

		$workflow = $manager->buildWorkflow('demo');

		$this->assertCount(0, $workflow->getSteps());
	}

	public function testGraphProviderBuildsBranchedSymfonyWorkflow(): void
	{
		$provider = new class implements WorkflowGraphProviderInterface {
			public function getWorkflowName(): string
			{
				return 'branching';
			}

			public function supports(mixed $context): bool
			{
				return true;
			}

			/** @return array<WorkflowStepInterface> */
			public function provideSteps(WorkflowInterface $workflow, mixed $context): array
			{
				return [new StubWorkflowStep('choose'), new StubWorkflowStep('approve'), new StubWorkflowStep('reject')];
			}

			public function provideGraph(WorkflowInterface $workflow, mixed $context): WorkflowGraph
			{
				return new WorkflowGraph(
					['choose', 'approve', 'reject', 'complete'],
					[
						new WorkflowGraphTransition('approve', ['choose'], ['approve']),
						new WorkflowGraphTransition('reject', ['choose'], ['reject']),
						new WorkflowGraphTransition('finish_approval', ['approve'], ['complete']),
						new WorkflowGraphTransition('finish_rejection', ['reject'], ['complete']),
					],
					['choose'],
					['complete'],
				);
			}
		};
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new StepSubscriber([$provider]));
		$workflow = (new WorkflowManager($dispatcher))->buildWorkflow('branching');

		$this->assertInstanceOf(RuntimeWorkflowInterface::class, $workflow);
		$this->assertSame('choose', $workflow->getCurrentStep()?->getId());
		$this->assertSame(['approve', 'reject'], $workflow->getEnabledTransitionNames());
	}

	/**
	 * @param array<int,string> $stepIds
	 */
	private function createProvider(string $workflowName, bool $supports, array $stepIds): StepProviderInterface
	{
		return new class($workflowName, $supports, $stepIds) implements StepProviderInterface {
			/**
			 * @param array<int,string> $stepIds
			 */
			public function __construct(
				private readonly string $workflowName,
				private readonly bool $supports,
				private readonly array $stepIds,
			) {
			}

			public function getWorkflowName(): string
			{
				return $this->workflowName;
			}

			public function supports(mixed $context): bool
			{
				return $this->supports;
			}

			/**
			 * @return array<WorkflowStepInterface>
			 */
			public function provideSteps(WorkflowInterface $workflow, mixed $context): array
			{
				return array_map(
					static fn (string $id): WorkflowStepInterface => new StubWorkflowStep($id),
					$this->stepIds
				);
			}
		};
	}
}
