<?php

namespace iikiti\CMS\Workflow;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class Workflow implements WorkflowInterface
{
	private string $name;
	/** @var array<WorkflowStepInterface> */
	private array $steps = [];
	private int $currentStepIndex = 0;
	/** @var array<string,mixed> */
	private array $context = [];
	private bool $isComplete = false;
	private EventDispatcherInterface $eventDispatcher;

	/**
	 * @param array<WorkflowStepInterface> $steps
	 */
	public function __construct(
		string $name,
		EventDispatcherInterface $eventDispatcher,
		array $steps = [],
	) {
		$this->name = $name;
		$this->eventDispatcher = $eventDispatcher;

		foreach ($steps as $step) {
			$this->addStep($step);
		}
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getCurrentStep(): ?WorkflowStepInterface
	{
		if ($this->currentStepIndex < 0 || $this->currentStepIndex >= count($this->steps)) {
			return null;
		}

		return $this->steps[$this->currentStepIndex];
	}

	/**
	 * @return array<WorkflowStepInterface>
	 */
	public function getSteps(): array
	{
		return $this->steps;
	}

	public function getCurrentStepIndex(): int
	{
		return $this->currentStepIndex;
	}

	public function isComplete(): bool
	{
		return $this->isComplete;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getContext(): array
	{
		return $this->context;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function setContext(array $context): void
	{
		$this->context = $context;
	}

	public function nextStep(): void
	{
		if ($this->isComplete()) {
			return;
		}

		$this->eventDispatcher->dispatch(
			new WorkflowStepEvent($this, $this->getCurrentStep()),
			WorkflowEvents::STEP_NEXT
		);

		++$this->currentStepIndex;

		if ($this->currentStepIndex >= count($this->steps)) {
			$this->complete();
		}
	}

	public function previousStep(): void
	{
		if ($this->currentStepIndex > 0) {
			$this->eventDispatcher->dispatch(
				new WorkflowStepEvent($this, $this->getCurrentStep()),
				WorkflowEvents::STEP_PREVIOUS
			);

			--$this->currentStepIndex;
		}
	}

	public function goToStep(string $stepId): void
	{
		foreach ($this->steps as $index => $step) {
			if ($step->getId() === $stepId) {
				$this->eventDispatcher->dispatch(
					new WorkflowStepEvent($this, $this->getCurrentStep()),
					WorkflowEvents::STEP_JUMP
				);

				$this->currentStepIndex = $index;

				return;
			}
		}
	}

	public function validateCurrentStep(): bool
	{
		$currentStep = $this->getCurrentStep();
		if (!$currentStep) {
			return false;
		}

		$isValid = $currentStep->validate($this->context);
		$this->dispatchValidationResult($currentStep, $isValid);

		return $isValid;
	}

	public function complete(): void
	{
		$this->isComplete = true;

		$this->eventDispatcher->dispatch(
			new WorkflowEvent($this),
			WorkflowEvents::WORKFLOW_COMPLETED
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function mergeContext(array $data): void
	{
		$this->context = array_merge($this->context, $data);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function submitCurrentStep(array $data): bool
	{
		$currentStep = $this->getCurrentStep();
		if (null === $currentStep) {
			return false;
		}

		// The submitted data is validated against a copy of the context so a
		// rejected attempt does not leak into the persisted workflow state.
		$validationContext = array_merge($this->context, $data);
		$isValid = $currentStep->validate($validationContext);
		$this->dispatchValidationResult($currentStep, $isValid);

		if (false === $isValid) {
			return false;
		}

		$this->mergeContext($currentStep->process($data, $validationContext));
		$this->nextStep();

		return true;
	}

	/**
	 * Dispatches the validation outcome for a step.
	 */
	private function dispatchValidationResult(WorkflowStepInterface $step, bool $isValid): void
	{
		$event = new WorkflowStepEvent($this, $step);
		$event->setData(['valid' => $isValid]);

		$this->eventDispatcher->dispatch(
			$event,
			$isValid ? WorkflowEvents::STEP_VALIDATED : WorkflowEvents::STEP_VALIDATION_FAILED
		);
	}

	public function addStep(WorkflowStepInterface $step): void
	{
		$this->steps[] = $step;

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
}
