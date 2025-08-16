<?php

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\WorkflowInterface;

/**
 * Interface for step providers that can provide workflow steps based on context.
 */
interface StepProviderInterface
{
    /**
     * Returns the name of the workflow this provider supports.
     */
    public function getWorkflowName(): string;

    /**
     * Checks if this provider supports the given context.
     *
     * @param mixed $context The workflow context to check
     */
    public function supports(mixed $context): bool;

    /**
     * Provides steps for the given workflow and context.
     *
     * @param WorkflowInterface $workflow The workflow instance
     * @param mixed            $context  The workflow context
     *
     * @return WorkflowStepInterface[] Array of workflow steps
     */
    public function provideSteps(WorkflowInterface $workflow, mixed $context): array;
}