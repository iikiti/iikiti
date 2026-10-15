<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StepSubscriber implements EventSubscriberInterface
{
	/** @var array<string,array<int,StepProviderInterface>> */
	private array $stepProviders = [];

	/**
	 * @param iterable<StepProviderInterface> $stepProviders
	 */
	public function __construct(
		#[AutowireIterator('workflow.step_provider')]
		iterable $stepProviders = [],
	) {
		foreach ($stepProviders as $provider) {
			$this->addStepProvider($provider);
		}
	}

	public function addStepProvider(StepProviderInterface $provider): void
	{
		$this->stepProviders[$provider->getWorkflowName()][] = $provider;
	}

	public static function getSubscribedEvents(): array
	{
		return [
			WorkflowEvents::WORKFLOW_INITIALIZED => 'onWorkflowInitialized',
		];
	}

	public function onWorkflowInitialized(WorkflowEvent $event): void
	{
		$workflow = $event->getWorkflow();
		$workflowName = $workflow->getName();

		if (!isset($this->stepProviders[$workflowName])) {
			return;
		}

		$context = $workflow->getContext();
		$matchingProviders = array_filter(
			$this->stepProviders[$workflowName],
			static fn (StepProviderInterface $provider): bool => $provider->supports($context)
		);
		$graphProviders = array_values(array_filter(
			$matchingProviders,
			static fn (StepProviderInterface $provider): bool => $provider instanceof WorkflowGraphProviderInterface
		));

		if ([] !== $graphProviders) {
			if (1 !== count($graphProviders) || 1 !== count($matchingProviders)) {
				throw new \LogicException(sprintf('Workflow "%s" must have exactly one matching graph provider.', $workflowName));
			}
			$provider = $graphProviders[0];
			foreach ($provider->provideSteps($workflow, $context) as $step) {
				$workflow->addStep($step);
			}
			if (!$workflow instanceof RuntimeWorkflowInterface) {
				throw new \LogicException(sprintf('Workflow "%s" cannot install a Symfony Workflow graph.', $workflowName));
			}
			$workflow->setGraph($provider->provideGraph($workflow, $context));

			return;
		}

		foreach ($matchingProviders as $provider) {
			/** @var array<WorkflowStepInterface> $steps */
			$steps = $provider->provideSteps($workflow, $context);
			foreach ($steps as $step) {
				$workflow->addStep($step);
			}
		}
	}
}
