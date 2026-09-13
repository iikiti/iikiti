<?php

namespace iikiti\CMS\Workflow;

use Symfony\Contracts\EventDispatcher\Event;

class WorkflowStepEvent extends Event
{
    private WorkflowInterface $workflow;
    private ?WorkflowStepInterface $step;
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(WorkflowInterface $workflow, ?WorkflowStepInterface $step)
    {
        $this->workflow = $workflow;
        $this->step = $step;
    }

    public function getWorkflow(): WorkflowInterface
    {
        return $this->workflow;
    }

    public function getStep(): ?WorkflowStepInterface
    {
        return $this->step;
    }

    /**
     * @return array<string,mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }
}