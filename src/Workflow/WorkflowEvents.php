<?php

namespace iikiti\CMS\Workflow;

/**
 * Contains all workflow-related events.
 */
final class WorkflowEvents
{
    /**
     * The WORKFLOW_INITIALIZED event occurs when a workflow is initialized.
     *
     * This event allows you to add steps to the workflow based on context.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowEvent")
     */
    public const WORKFLOW_INITIALIZED = 'workflow.initialized';

    /**
     * The STEP_NEXT event occurs when the workflow advances to the next step.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_NEXT = 'workflow.step.next';

    /**
     * The STEP_PREVIOUS event occurs when the workflow returns to a previous step.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_PREVIOUS = 'workflow.step.previous';

    /**
     * The STEP_JUMP event occurs when the workflow jumps to a specific step.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_JUMP = 'workflow.step.jump';

    /**
     * The STEP_VALIDATED event occurs when the current step validates successfully.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_VALIDATED = 'workflow.step.validated';

    /**
     * The STEP_VALIDATION_FAILED event occurs when the current step fails validation.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_VALIDATION_FAILED = 'workflow.step.validation_failed';

    /**
     * The STEP_ADDED event occurs when a step is added to the workflow.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowStepEvent")
     */
    public const STEP_ADDED = 'workflow.step.added';

    /**
     * The WORKFLOW_COMPLETED event occurs when the workflow is completed.
     *
     * @Event("iikiti\CMS\Workflow\WorkflowEvent")
     */
    public const WORKFLOW_COMPLETED = 'workflow.completed';
}