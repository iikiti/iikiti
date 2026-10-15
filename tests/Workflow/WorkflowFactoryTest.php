<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Workflow;

use iikiti\CMS\Tests\Fixtures\StubWorkflowStep;
use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use iikiti\CMS\Workflow\StepProviderInterface;
use iikiti\CMS\Workflow\StepSubscriber;
use iikiti\CMS\Workflow\WorkflowFactory;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowManager;
use iikiti\CMS\Workflow\WorkflowStepInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class WorkflowFactoryTest extends TestCase
{
	public function testRebuildRestoresPersistedSymfonyMarking(): void
	{
		$factory = $this->createFactory();
		$workflow = $factory->rebuild('checkout', [
			'context' => ['cart_id' => 12],
			'marking' => ['shipping' => 1],
		]);

		self::assertInstanceOf(RuntimeWorkflowInterface::class, $workflow);
		self::assertSame('shipping', $workflow->getCurrentStep()?->getId());
		self::assertSame(['shipping' => 1], $workflow->getWorkflowMarking());
		self::assertSame(['cart_id' => 12], $workflow->getContext());
	}

	public function testRebuildMigratesLegacyCurrentStepAndCompleteState(): void
	{
		$factory = $this->createFactory();
		$workflow = $factory->rebuild('checkout', ['current_step' => 'payment']);

		self::assertSame('payment', $workflow->getCurrentStep()?->getId());

		$completed = $factory->rebuild('checkout', ['complete' => true]);
		self::assertTrue($completed->isComplete());
		self::assertNull($completed->getCurrentStep());
	}

	private function createFactory(): WorkflowFactory
	{
		$provider = new class implements StepProviderInterface {
			public function getWorkflowName(): string
			{
				return 'checkout';
			}

			public function supports(mixed $context): bool
			{
				return true;
			}

			/** @return array<WorkflowStepInterface> */
			public function provideSteps(WorkflowInterface $workflow, mixed $context): array
			{
				return [new StubWorkflowStep('cart'), new StubWorkflowStep('shipping'), new StubWorkflowStep('payment')];
			}
		};
		$dispatcher = new EventDispatcher();
		$dispatcher->addSubscriber(new StepSubscriber([$provider]));

		return new WorkflowFactory(new WorkflowManager($dispatcher));
	}
}
