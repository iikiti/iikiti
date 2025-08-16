<?php

namespace iikiti\CMS\Workflow;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event class for workflow-related events.
 */
class WorkflowEvent extends Event
{
    private WorkflowInterface $workflow;
    private mixed $context;

    public function __construct(WorkflowInterface $workflow, mixed $context = null)
    {
        $this->workflow = $workflow;
        $this->context = $context;
    }

    public function getWorkflow(): WorkflowInterface
    {
        return $this->workflow;
    }

    public function getContext(): mixed
    {
        return $this->context;
    }
}