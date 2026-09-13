<?php

namespace iikiti\CMS\Workflow;

interface WorkflowInterface
{
	/**
	 * Get the unique identifier for this workflow.
	 */
	public function getName(): string;

	/**
	 * Get the current step in the workflow.
	 */
	public function getCurrentStep(): ?WorkflowStepInterface;

	/**
	 * Get all steps in the workflow.
	 *
	 * @return WorkflowStepInterface[]
	 */
	public function getSteps(): array;

	/**
	 * Get the zero-based position of the current step.
	 */
	public function getCurrentStepIndex(): int;

	/**
	 * Add a step to the workflow.
	 */
	public function addStep(WorkflowStepInterface $step): void;

	/**
	 * Check if the workflow is complete.
	 */
	public function isComplete(): bool;

	/**
	 * Get the workflow context/data.
	 *
	 * @return array<string,mixed>
	 */
	public function getContext(): array;

	/**
	 * Set workflow context/data.
	 *
	 * @param array<string,mixed> $context
	 */
	public function setContext(array $context): void;

	/**
	 * Move to the next step.
	 */
	public function nextStep(): void;

	/**
	 * Move to the previous step.
	 */
	public function previousStep(): void;

	/**
	 * Jump to a specific step.
	 */
	public function goToStep(string $stepId): void;

	/**
	 * Validate the current step.
	 */
	public function validateCurrentStep(): bool;

	/**
	 * Complete the workflow.
	 */
	public function complete(): void;

	/**
	 * Merge data into the workflow context.
	 *
	 * @param array<string,mixed> $data
	 */
	public function mergeContext(array $data): void;

	/**
	 * Validate, process and advance the current step in one operation.
	 *
	 * @param array<string,mixed> $data Submitted step data
	 *
	 * @return bool True when the step was accepted and the workflow advanced
	 */
	public function submitCurrentStep(array $data): bool;
}
