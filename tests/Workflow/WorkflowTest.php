<?php

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\Workflow;
use iikiti\CMS\Workflow\WorkflowStepInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class WorkflowTest extends TestCase
{
	public function testNextStepAdvancesAndCompletes(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a'), new StubWorkflowStep('b')]);

		$this->assertSame('a', $workflow->getCurrentStep()?->getId());
		$this->assertFalse($workflow->isComplete());

		$workflow->nextStep();

		$this->assertSame('b', $workflow->getCurrentStep()?->getId());
		$this->assertFalse($workflow->isComplete());

		$workflow->nextStep();

		$this->assertNull($workflow->getCurrentStep());
		$this->assertTrue($workflow->isComplete());
		$this->assertSame(2, $workflow->getCurrentStepIndex());
	}

	public function testPreviousStepMovesBack(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a'), new StubWorkflowStep('b')]);

		$workflow->nextStep();
		$workflow->previousStep();

		$this->assertSame('a', $workflow->getCurrentStep()?->getId());
	}

	public function testGoToStepMovesToMatchingStep(): void
	{
		$workflow = $this->createWorkflow([
			new StubWorkflowStep('a'),
			new StubWorkflowStep('b'),
			new StubWorkflowStep('c'),
		]);

		$workflow->goToStep('c');

		$this->assertSame('c', $workflow->getCurrentStep()?->getId());
		$this->assertSame(2, $workflow->getCurrentStepIndex());
	}

	public function testGoToUnknownStepIsIgnored(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a')]);

		$workflow->goToStep('missing');

		$this->assertSame('a', $workflow->getCurrentStep()?->getId());
	}

	public function testMergeContextCombinesValues(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a')]);

		$workflow->mergeContext(['first' => 'value']);
		$workflow->mergeContext(['second' => 'value']);

		$this->assertSame(['first' => 'value', 'second' => 'value'], $workflow->getContext());
	}

	public function testSubmitCurrentStepAdvancesAndMergesResult(): void
	{
		$workflow = $this->createWorkflow([
			new StubWorkflowStep('a', true, ['verified' => true]),
			new StubWorkflowStep('b'),
		]);

		$this->assertTrue($workflow->submitCurrentStep(['code' => '123456']));
		$this->assertSame('b', $workflow->getCurrentStep()?->getId());
		$this->assertSame(['verified' => true], $workflow->getContext());
	}

	public function testSubmitCurrentStepRejectsInvalidDataWithoutAdvancing(): void
	{
		$workflow = $this->createWorkflow([
			new StubWorkflowStep('a', false),
			new StubWorkflowStep('b'),
		]);

		$this->assertFalse($workflow->submitCurrentStep(['code' => '000000']));
		$this->assertSame('a', $workflow->getCurrentStep()?->getId());
		$this->assertSame([], $workflow->getContext());
	}

	public function testSubmitCurrentStepCompletesFinalStep(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('only')]);

		$this->assertTrue($workflow->submitCurrentStep([]));
		$this->assertTrue($workflow->isComplete());
	}

	public function testValidateCurrentStepReportsResult(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a', true)]);

		$this->assertTrue($workflow->validateCurrentStep());
	}

	public function testGetStepByIdAndIndex(): void
	{
		$workflow = $this->createWorkflow([new StubWorkflowStep('a'), new StubWorkflowStep('b')]);

		$this->assertInstanceOf(WorkflowStepInterface::class, $workflow->getStepById('b'));
		$this->assertSame(1, $workflow->getStepIndex('b'));
		$this->assertNull($workflow->getStepById('missing'));
		$this->assertSame(-1, $workflow->getStepIndex('missing'));
	}

	/**
	 * @param array<WorkflowStepInterface> $steps
	 */
	private function createWorkflow(array $steps): Workflow
	{
		return new Workflow('test_workflow', new EventDispatcher(), $steps);
	}
}
