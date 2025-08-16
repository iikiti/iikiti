<?php

namespace iikiti\CMS\Workflow;

interface WorkflowInterface
{
    /**
     * Get the unique identifier for this workflow
     */
    public function getName(): string;

    /**
     * Get the current step in the workflow
     */
    public function getCurrentStep(): ?WorkflowStepInterface;

    /**
     * Get all steps in the workflow
     * 
     * @return WorkflowStepInterface[]
     */
    public function getSteps(): array;

    /**
     * Check if the workflow is complete
     */
    public function isComplete(): bool;

    /**
     * Get the workflow context/data
     */
    public function getContext(): array;

    /**
     * Set workflow context/data
     */
    public function setContext(array $context): void;

    /**
     * Move to the next step
     */
    public function nextStep(): void;

    /**
     * Move to the previous step
     */
    public function previousStep(): void;

    /**
     * Jump to a specific step
     */
    public function goToStep(string $stepId): void;

    /**
     * Validate the current step
     */
    public function validateCurrentStep(): bool;

    /**
     * Complete the workflow
     */
    public function complete(): void;
}