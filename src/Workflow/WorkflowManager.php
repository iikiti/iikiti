<?php

namespace iikiti\CMS\Workflow;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class WorkflowManager
{
    private EventDispatcherInterface $eventDispatcher;
    private array $workflows = [];
    private array $stepProviders = [];

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Create a new workflow instance
     */
    public function createWorkflow(string $name, array $context = []): WorkflowInterface
    {
        $workflow = new Workflow($name, $this->eventDispatcher);
        $workflow->setContext($context);

        // Dispatch initialization event
        $this->eventDispatcher->dispatch(
            new WorkflowEvent($workflow),
            WorkflowEvents::WORKFLOW_INITIALIZED
        );

        return $workflow;
    }

    /**
     * Add a step provider to dynamically add steps to workflows
     */
    public function addStepProvider(string $workflowName, callable $provider): void
    {
        if (!isset($this->stepProviders[$workflowName])) {
            $this->stepProviders[$workflowName] = [];
        }

        $this->stepProviders[$workflowName][] = $provider;
    }

    /**
     * Build a workflow with steps from providers
     */
    public function buildWorkflow(string $name, array $context = []): WorkflowInterface
    {
        $workflow = $this->createWorkflow($name, $context);

        // Add steps from providers
        if (isset($this->stepProviders[$name])) {
            foreach ($this->stepProviders[$name] as $provider) {
                $steps = $provider($workflow, $context);
                if (is_array($steps)) {
                    foreach ($steps as $step) {
                        if ($step instanceof WorkflowStepInterface) {
                            $workflow->addStep($step);
                        }
                    }
                }
            }
        }

        return $workflow;
    }

    /**
     * Get a workflow by name
     */
    public function getWorkflow(string $name): ?WorkflowInterface
    {
        return $this->workflows[$name] ?? null;
    }

    /**
     * Store a workflow instance
     */
    public function storeWorkflow(WorkflowInterface $workflow): void
    {
        $this->workflows[$workflow->getName()] = $workflow;
    }

    /**
     * Remove a workflow instance
     */
    public function removeWorkflow(string $name): void
    {
        unset($this->workflows[$name]);
    }

    /**
     * Get all registered workflows
     */
    public function getWorkflows(): array
    {
        return $this->workflows;
    }
}