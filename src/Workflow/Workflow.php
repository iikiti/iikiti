<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphTransition;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface as SymfonyWorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class Workflow implements RuntimeWorkflowInterface
{
	private const COMPLETE_PLACE = '__workflow_complete';

	private string $name;
	/** @var array<WorkflowStepInterface> */
	private array $steps = [];
	/** @var array<string,mixed> */
	private array $context = [];
	/**
	 * Symfony's MethodMarkingStore reads/writes this public property for the
	 * multi-place marking. Session persistence is handled separately.
	 *
	 * @var array<string,int>
	 */
	public array $marking = [];
	private ?WorkflowGraph $graph = null;
	private ?SymfonyWorkflowInterface $runtimeWorkflow = null;
	private bool $explicitGraph = false;
	private bool $completionEventDispatched = false;
	private bool $advancingAutomaticTransitions = false;
	private bool $runtimeAutomaticTransition = false;
	private EventDispatcherInterface $eventDispatcher;

	/**
	 * @param list<WorkflowStepInterface> $steps
	 * @param array<string,mixed>         $context
	 */
	public function __construct(
		string $name,
		EventDispatcherInterface $eventDispatcher,
		array $steps = [],
		?WorkflowGraph $graph = null,
		array $context = [],
	) {
		$this->name = $name;
		$this->eventDispatcher = $eventDispatcher;
		$this->context = $context;

		if (null === $graph) {
			foreach ($steps as $step) {
				$this->addStep($step);
			}

			return;
		}

		$this->explicitGraph = true;
		foreach ($steps as $step) {
			$this->steps[] = $step;
			$this->eventDispatcher->dispatch(new WorkflowStepEvent($this, $step), WorkflowEvents::STEP_ADDED);
		}
		$this->installGraph($graph);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getCurrentStep(): ?WorkflowStepInterface
	{
		$activePlaces = array_fill_keys($this->getActivePlaceIds(), true);
		foreach ($this->steps as $step) {
			if (isset($activePlaces[$step->getId()])) {
				return $step;
			}
		}

		return null;
	}

	/** @return list<WorkflowStepInterface> */
	public function getSteps(): array
	{
		return $this->steps;
	}

	public function getCurrentStepIndex(): int
	{
		if ($this->isComplete()) {
			return count($this->steps);
		}

		$currentStep = $this->getCurrentStep();
		if (null === $currentStep) {
			return 0;
		}

		return $this->getStepIndex($currentStep->getId());
	}

	public function isComplete(): bool
	{
		$activePlaces = $this->getActivePlaceIds();
		if ([] === $activePlaces || null === $this->graph) {
			return false;
		}

		$finalPlaces = array_fill_keys($this->graph->finalPlaces, true);

		foreach ($activePlaces as $place) {
			if (!isset($finalPlaces[$place])) {
				return false;
			}
		}

		return true;
	}

	/** @return list<string> */
	public function getActivePlaceIds(): array
	{
		if (null === $this->runtimeWorkflow) {
			return [];
		}
		$this->advanceAutomaticTransitions();

		$active = $this->runtimeWorkflow->getMarking($this)->getPlaces();
		$order = array_flip($this->graph->places);
		$places = array_keys($active);
		usort($places, static fn (string $left, string $right): int => ($order[$left] ?? PHP_INT_MAX) <=> ($order[$right] ?? PHP_INT_MAX));

		return $places;
	}

	/** @return list<string> */
	public function getEnabledTransitionNames(): array
	{
		if (null === $this->runtimeWorkflow) {
			return [];
		}
		$this->advanceAutomaticTransitions();

		$names = [];
		foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
			if (!$this->isAutomaticTransition($transition)) {
				$names[$transition->getName()] = true;
			}
		}

		return array_keys($names);
	}

	/** @return list<string> */
	public function getEnabledTransitionNamesForPlace(string $place): array
	{
		if (null === $this->runtimeWorkflow) {
			return [];
		}
		$this->advanceAutomaticTransitions();

		$names = [];
		foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
			if (!$this->isAutomaticTransition($transition) &&
				'back' !== $this->transitionNavigation($transition) &&
				in_array($place, $transition->getFroms(), true)) {
				$names[$transition->getName()] = true;
			}
		}

		return array_keys($names);
	}

	/** @return array<string,int> */
	public function getWorkflowMarking(): array
	{
		if (null === $this->runtimeWorkflow) {
			return [];
		}
		$this->advanceAutomaticTransitions();

		return $this->runtimeWorkflow->getMarking($this)->getPlaces();
	}

	/** @return list<string> */
	public function getFinalPlaceIds(): array
	{
		return $this->graph->finalPlaces;
	}

	public function isRuntimeAutomaticTransition(): bool
	{
		return $this->runtimeAutomaticTransition;
	}

	/** @param array<string,mixed> $marking */
	public function restoreMarking(array $marking): void
	{
		if (null === $this->graph) {
			if ([] !== $marking) {
				throw new \InvalidArgumentException('Cannot restore a marking without a workflow graph.');
			}

			return;
		}

		$places = $this->graph->placeSet();
		$restoredMarking = [];
		foreach ($marking as $place => $tokens) {
			if (!isset($places[$place]) || !is_int($tokens) || $tokens < 1) {
				throw new \InvalidArgumentException(sprintf('Invalid persisted workflow marking at place "%s".', $place));
			}
			$restoredMarking[$place] = $tokens;
		}

		$this->marking = $restoredMarking;
	}

	public function restoreLegacyPosition(?string $stepId, bool $complete): void
	{
		if (null === $this->graph) {
			return;
		}

		if ($complete) {
			$this->restoreMarking([$this->graph->finalPlaces[0] => 1]);

			return;
		}

		if (null !== $stepId && isset($this->graph->placeSet()[$stepId])) {
			$this->restoreMarking([$stepId => 1]);

			return;
		}

		$this->restoreMarking([$this->graph->initialPlaces[0] => 1]);
	}

	public function setGraph(WorkflowGraph $graph): void
	{
		$this->explicitGraph = true;
		$this->installGraph($graph);
	}

	/** @return array<string,mixed> */
	public function getContext(): array
	{
		return $this->context;
	}

	/** @param array<string,mixed> $context */
	public function setContext(array $context): void
	{
		$this->context = $context;
	}

	public function nextStep(): void
	{
		if ($this->isComplete()) {
			return;
		}

		$currentStep = $this->getCurrentStep();
		if (null === $currentStep) {
			return;
		}

		$transition = $this->selectDefaultSubmissionTransition($currentStep->getId());
		if (null !== $transition) {
			$this->applySelectedTransition($transition, WorkflowEvents::STEP_NEXT, $currentStep);
		}
	}

	public function previousStep(): void
	{
		$transition = $this->selectBackwardTransition();
		if (null !== $transition) {
			$this->applySelectedTransition($transition, WorkflowEvents::STEP_PREVIOUS, $this->getCurrentStep());
		}
	}

	public function goToStep(string $stepId): void
	{
		$targetIndex = $this->getStepIndex($stepId);
		if ($targetIndex < 0 || $targetIndex >= $this->getCurrentStepIndex()) {
			return;
		}

		while ($this->getCurrentStepIndex() > $targetIndex) {
			$currentIndex = $this->getCurrentStepIndex();
			$this->previousStep();
			if ($currentIndex === $this->getCurrentStepIndex()) {
				return;
			}
		}
	}

	public function validateCurrentStep(): bool
	{
		$currentStep = $this->getCurrentStep();
		if (null === $currentStep) {
			return false;
		}

		$isValid = $currentStep->validate($this->context);
		$this->dispatchValidationResult($currentStep, $isValid);

		return $isValid;
	}

	public function complete(): void
	{
		if (!$this->isComplete()) {
			throw new \LogicException('A workflow can only complete through an enabled transition to a final place.');
		}

		$this->dispatchCompletionEvent();
	}

	/** @param array<string,mixed> $data */
	public function mergeContext(array $data): void
	{
		$this->context = array_merge($this->context, $data);
	}

	/** @param array<string,mixed> $data */
	public function submitCurrentStep(array $data): bool
	{
		return $this->submitCurrentStepWithTransition($data, null);
	}

	/** @param array<string,mixed> $data */
	public function submitCurrentStepWithTransition(array $data, ?string $transitionName): bool
	{
		$currentStep = $this->getCurrentStep();
		if (null === $currentStep || null === $this->runtimeWorkflow) {
			return false;
		}

		$validationContext = array_merge($this->context, $data);
		$isValid = $currentStep->validate($validationContext);
		$this->dispatchValidationResult($currentStep, $isValid);
		if (!$isValid) {
			return false;
		}

		$processed = $currentStep->process($data, $validationContext);
		$previousContext = $this->context;
		$this->context = array_merge($this->context, $processed);
		$transition = null === $transitionName ?
			$this->selectDefaultSubmissionTransition($currentStep->getId()) :
			$this->selectTransition($currentStep->getId(), $transitionName);
		if (null === $transition) {
			$this->context = $previousContext;

			return false;
		}

		try {
			$this->applySelectedTransition($transition, WorkflowEvents::STEP_NEXT, $currentStep, true);
		} catch (NotEnabledTransitionException) {
			$this->context = $previousContext;

			return false;
		}

		return true;
	}

	public function addStep(WorkflowStepInterface $step): void
	{
		if ($this->explicitGraph) {
			throw new \LogicException('Steps cannot be appended after installing an explicit workflow graph.');
		}

		$this->steps[] = $step;
		$this->rebuildLinearGraph();
		$this->eventDispatcher->dispatch(
			new WorkflowStepEvent($this, $step),
			WorkflowEvents::STEP_ADDED
		);
	}

	public function getStepById(string $stepId): ?WorkflowStepInterface
	{
		foreach ($this->steps as $step) {
			if ($step->getId() === $stepId) {
				return $step;
			}
		}

		return null;
	}

	public function getStepIndex(string $stepId): int
	{
		foreach ($this->steps as $index => $step) {
			if ($step->getId() === $stepId) {
				return $index;
			}
		}

		return -1;
	}

	private function rebuildLinearGraph(): void
	{
		if ([] === $this->steps) {
			$this->graph = null;
			$this->runtimeWorkflow = null;
			$this->marking = [];

			return;
		}

		$stepIds = array_map(static fn (WorkflowStepInterface $step): string => $step->getId(), $this->steps);
		if (in_array(self::COMPLETE_PLACE, $stepIds, true)) {
			throw new \InvalidArgumentException(sprintf('Workflow step ID "%s" is reserved.', self::COMPLETE_PLACE));
		}

		$places = [...$stepIds, self::COMPLETE_PLACE];
		$transitions = [];
		foreach ($stepIds as $index => $stepId) {
			$nextPlace = $stepIds[$index + 1] ?? self::COMPLETE_PLACE;
			$transitions[] = new WorkflowGraphTransition('next_'.$index, [$stepId], [$nextPlace], ['navigation' => 'forward']);

			if ($index > 0) {
				$transitions[] = new WorkflowGraphTransition('back_'.$index, [$stepId], [$stepIds[$index - 1]], ['navigation' => 'back']);
			}
		}
		$this->installGraph(new WorkflowGraph($places, $transitions, [$stepIds[0]], [self::COMPLETE_PLACE]));
	}

	private function installGraph(WorkflowGraph $graph): void
	{
		$places = $graph->placeSet();
		foreach ($this->steps as $step) {
			if (!isset($places[$step->getId()])) {
				throw new \InvalidArgumentException(sprintf('Workflow graph has no place for step "%s".', $step->getId()));
			}
		}

		$previousMarking = $this->marking;
		$this->graph = $graph;
		$this->runtimeWorkflow = (new RuntimeWorkflowFactory($this->eventDispatcher))->create(
			$this->name,
			$graph,
			new MethodMarkingStore(false, 'marking'),
		);
		$this->marking = [];
		if ([] !== $previousMarking) {
			$this->restoreMarking(array_intersect_key($previousMarking, $places));
		}
	}

	private function selectTransition(?string $fromPlace, ?string $transitionName): ?Transition
	{
		if (null === $this->runtimeWorkflow) {
			return null;
		}

		$matches = [];
		foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
			if ($this->isAutomaticTransition($transition)) {
				continue;
			}
			if (null !== $transitionName && $transition->getName() !== $transitionName) {
				continue;
			}
			if (null !== $fromPlace && !in_array($fromPlace, $transition->getFroms(), true)) {
				continue;
			}
			$matches[] = $transition;
		}

		return 1 === count($matches) ? $matches[0] : null;
	}

	private function selectDefaultSubmissionTransition(string $fromPlace): ?Transition
	{
		if (null === $this->runtimeWorkflow) {
			return null;
		}

		$matches = [];
		foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
			if ($this->isAutomaticTransition($transition) || !in_array($fromPlace, $transition->getFroms(), true) || 'back' === $this->transitionNavigation($transition)) {
				continue;
			}

			$matches[] = $transition;
		}

		return 1 === count($matches) ? $matches[0] : null;
	}

	private function selectBackwardTransition(): ?Transition
	{
		if (null === $this->runtimeWorkflow) {
			return null;
		}

		$matches = [];
		foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
			if (!$this->isAutomaticTransition($transition) && 'back' === $this->transitionNavigation($transition)) {
				$matches[] = $transition;
			}
		}

		return 1 === count($matches) ? $matches[0] : null;
	}

	private function transitionNavigation(Transition $transition): ?string
	{
		$metadata = $this->runtimeWorkflow?->getDefinition()->getMetadataStore()->getTransitionMetadata($transition) ?? [];

		return is_string($metadata['navigation'] ?? null) ? $metadata['navigation'] : null;
	}

	private function applySelectedTransition(Transition $transition, string $eventName, ?WorkflowStepInterface $step, bool $commitStep = false): void
	{
		if (null === $this->runtimeWorkflow) {
			return;
		}

		$wasComplete = $this->isComplete();
		$this->eventDispatcher->dispatch(new WorkflowStepEvent($this, $step), $eventName);
		$this->runtimeWorkflow->apply($this, $transition->getName());
		if ($commitStep && $step instanceof WorkflowTransitionCommittedStepInterface) {
			$step->onTransitionCommitted();
		}
		$this->advanceAutomaticTransitions();
		$isComplete = $this->isComplete();
		if ($wasComplete && !$isComplete) {
			$this->completionEventDispatched = false;
		}
		if ($isComplete) {
			$this->dispatchCompletionEvent();
		}
	}

	private function advanceAutomaticTransitions(): void
	{
		if (null === $this->runtimeWorkflow || $this->advancingAutomaticTransitions) {
			return;
		}

		$this->advancingAutomaticTransitions = true;
		$wasComplete = $this->isMarkingComplete($this->runtimeWorkflow->getMarking($this)->getPlaces());
		$applied = false;
		try {
			for ($count = 0; $count < 32; ++$count) {
				$automaticTransition = null;
				foreach ($this->runtimeWorkflow->getEnabledTransitions($this) as $transition) {
					if ($this->isAutomaticTransition($transition)) {
						$automaticTransition = $transition;
						break;
					}
				}
				if (null === $automaticTransition) {
					$isComplete = $this->isMarkingComplete($this->runtimeWorkflow->getMarking($this)->getPlaces());
					if ($wasComplete && !$isComplete) {
						$this->completionEventDispatched = false;
					} elseif (!$wasComplete && $isComplete && $applied) {
						$this->dispatchCompletionEvent();
					}

					return;
				}

				$this->runtimeAutomaticTransition = true;
				try {
					$this->runtimeWorkflow->apply($this, $automaticTransition->getName());
				} finally {
					$this->runtimeAutomaticTransition = false;
				}
				$applied = true;
			}

			throw new \LogicException(sprintf('Workflow "%s" exceeded the automatic-transition limit.', $this->name));
		} finally {
			$this->advancingAutomaticTransitions = false;
		}
	}

	private function isAutomaticTransition(Transition $transition): bool
	{
		if (null === $this->runtimeWorkflow) {
			return false;
		}

		$metadata = $this->runtimeWorkflow->getDefinition()->getMetadataStore()->getTransitionMetadata($transition);

		return true === ($metadata['automatic'] ?? false);
	}

	/** @param array<string,int> $marking */
	private function isMarkingComplete(array $marking): bool
	{
		if ([] === $marking || null === $this->graph) {
			return false;
		}

		$finalPlaces = array_fill_keys($this->graph->finalPlaces, true);

		foreach ($marking as $place => $tokens) {
			if ($tokens < 1 || !isset($finalPlaces[$place])) {
				return false;
			}
		}

		return true;
	}

	private function dispatchCompletionEvent(): void
	{
		if ($this->completionEventDispatched) {
			return;
		}

		$this->completionEventDispatched = true;
		$this->eventDispatcher->dispatch(new WorkflowEvent($this), WorkflowEvents::WORKFLOW_COMPLETED);
	}

	private function dispatchValidationResult(WorkflowStepInterface $step, bool $isValid): void
	{
		$event = new WorkflowStepEvent($this, $step);
		$event->setData(['valid' => $isValid]);

		$this->eventDispatcher->dispatch(
			$event,
			$isValid ? WorkflowEvents::STEP_VALIDATED : WorkflowEvents::STEP_VALIDATION_FAILED
		);
	}
}
