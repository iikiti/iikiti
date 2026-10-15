<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\RuntimeWorkflowFactory;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class WorkflowInstanceRuntime
{
	public function __construct(
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly RuntimeWorkflowFactory $runtimeWorkflowFactory,
	) {
	}

	public function create(WorkflowInstance $instance): WorkflowInterface
	{
		return $this->createRuntime($instance)[1];
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function settleInitialMarking(WorkflowInstance $instance, array $context = [], ?User $actor = null, ?object $subject = null): WorkflowTransitionResult
	{
		$instance->setRuntimeContext($actor, $subject);
		try {
			[$graph, $workflow] = $this->createRuntime($instance);
			$fromMarking = $instance->getMarking();
			$transitions = [];
			$this->advanceAutomaticTransitions($workflow, $instance, $context, $transitions);
			$toMarking = $instance->getMarking();

			return new WorkflowTransitionResult($fromMarking, $toMarking, $transitions, $this->isComplete($toMarking, $graph->finalPlaces));
		} finally {
			$instance->setRuntimeContext(null, null);
		}
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function applyTransition(
		WorkflowInstance $instance,
		string $transitionName,
		array $context = [],
		?User $actor = null,
		?object $subject = null,
	): WorkflowTransitionResult {
		if ('active' !== $instance->getExecutionState()) {
			throw new \LogicException(sprintf('Paused or terminated workflow instance %s cannot transition.', (string) $instance->getId()));
		}
		$instance->setRuntimeContext($actor, $subject);
		try {
			[$graph, $workflow] = $this->createRuntime($instance);
			$fromMarking = $instance->getMarking();
			$transitions = [];
			$this->applyNamedTransition($workflow, $instance, $transitionName, $context, false, $transitions);

			$this->advanceAutomaticTransitions($workflow, $instance, $context, $transitions);
			$toMarking = $instance->getMarking();

			return new WorkflowTransitionResult(
				$fromMarking,
				$toMarking,
				$transitions,
				$this->isComplete($toMarking, $graph->finalPlaces),
			);
		} finally {
			$instance->setRuntimeContext(null, null);
		}
	}

	/** @return list<Transition> */
	public function getEnabledTransitions(WorkflowInstance $instance, ?User $actor = null, ?object $subject = null): array
	{
		if ('active' !== $instance->getExecutionState()) {
			return [];
		}
		$instance->setRuntimeContext($actor, $subject);
		try {
			$workflow = $this->create($instance);

			return array_values(array_filter(
				$workflow->getEnabledTransitions($instance),
				fn (Transition $transition): bool => !$this->isAutomatic($workflow, $transition),
			));
		} finally {
			$instance->setRuntimeContext(null, null);
		}
	}

	/** @return array<string,mixed> */
	public function getTransitionMetadata(WorkflowInstance $instance, Transition $transition): array
	{
		$workflow = $this->create($instance);

		return $workflow->getDefinition()->getMetadataStore()->getTransitionMetadata($transition);
	}

	private function findEnabledAutomaticTransition(WorkflowInterface $workflow, WorkflowInstance $instance): ?Transition
	{
		foreach ($workflow->getEnabledTransitions($instance) as $transition) {
			if ($this->isAutomatic($workflow, $transition)) {
				return $transition;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed>             $context
	 * @param list<AppliedWorkflowTransition> $applied
	 */
	private function advanceAutomaticTransitions(WorkflowInterface $workflow, WorkflowInstance $instance, array $context, array &$applied): void
	{
		for ($count = 0; $count < 32; ++$count) {
			$automatic = $this->findEnabledAutomaticTransition($workflow, $instance);
			if (null === $automatic) {
				return;
			}
			$this->applyNamedTransition($workflow, $instance, $automatic->getName(), $context, true, $applied, $automatic);
		}

		throw new \LogicException(sprintf('Workflow instance %s exceeded the automatic-transition limit.', (string) $instance->getId()));
	}

	/**
	 * @param array<string,mixed>             $context
	 * @param list<AppliedWorkflowTransition> $applied
	 */
	private function applyNamedTransition(
		WorkflowInterface $workflow,
		WorkflowInstance $instance,
		string $name,
		array $context,
		bool $automatic,
		array &$applied,
		?Transition $selected = null,
	): void {
		$transition = $selected;
		if (null === $transition) {
			foreach ($workflow->getEnabledTransitions($instance) as $candidate) {
				if ($candidate->getName() === $name && $this->isAutomatic($workflow, $candidate) === $automatic) {
					$transition = $candidate;
					break;
				}
			}
		}
		if (null === $transition) {
			if (!$automatic && $this->transitionIsAutomatic($workflow, $name)) {
				throw new \LogicException(sprintf('Automatic transition "%s" cannot be invoked directly.', $name));
			}
			$workflow->apply($instance, $name, $context);

			return;
		}

		$metadata = $workflow->getDefinition()->getMetadataStore()->getTransitionMetadata($transition);
		$fromMarking = $instance->getMarking();
		$actor = $instance->getRuntimeActor();
		$subject = $instance->getRuntimeSubject();
		$instance->setRuntimeContext($actor, $subject, $automatic);
		try {
			$workflow->apply($instance, $name, $context);
		} finally {
			$instance->setRuntimeContext($actor, $subject);
		}
		$applied[] = new AppliedWorkflowTransition($name, $transition->getFroms(), $transition->getTos(), $metadata, $fromMarking, $instance->getMarking());
	}

	/** @return array{\iikiti\CMS\Workflow\Graph\WorkflowGraph,WorkflowInterface} */
	private function createRuntime(WorkflowInstance $instance): array
	{
		$version = $instance->getDefinitionVersion();
		$graph = $this->graphCompiler->compile($version->getGraph());
		$workflow = $this->runtimeWorkflowFactory->create(
			sprintf('%s.v%d', $version->getDefinition()->getKey(), $version->getVersion()),
			$graph,
			new MethodMarkingStore(false, 'marking'),
		);

		return [$graph, $workflow];
	}

	private function isAutomatic(WorkflowInterface $workflow, Transition $transition): bool
	{
		$metadata = $workflow->getDefinition()->getMetadataStore()->getTransitionMetadata($transition);

		return true === ($metadata['automatic'] ?? false);
	}

	private function transitionIsAutomatic(WorkflowInterface $workflow, string $name): bool
	{
		foreach ($workflow->getDefinition()->getTransitions() as $transition) {
			if ($transition->getName() === $name && $this->isAutomatic($workflow, $transition)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,int> $marking
	 * @param list<string>      $finalPlaces
	 */
	private function isComplete(array $marking, array $finalPlaces): bool
	{
		if ([] === $marking) {
			return false;
		}
		$final = array_fill_keys($finalPlaces, true);
		foreach ($marking as $place => $tokens) {
			if ($tokens < 1 || !isset($final[$place])) {
				return false;
			}
		}

		return true;
	}
}
