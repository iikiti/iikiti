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
}