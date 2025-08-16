<?php

namespace iikiti\CMS\Workflow;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class Workflow implements WorkflowInterface
{
    private string $name;
    private array $steps = [];
    private int $currentStepIndex = 0;
    private array $context = [];
    private bool $isComplete = false;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        string $name,
        EventDispatcherInterface $eventDispatcher,
        array $steps = []
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

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function isComplete(): bool
    {
        return $this->isComplete;
    }

    public function getContext(): array
    {
        return $this->context;
    }

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

        $this->currentStepIndex++;
        
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
            
            $this->currentStepIndex--;
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
        
        $event = new WorkflowStepEvent($this, $currentStep);
        $event->setData(['valid' => $isValid]);
        
        $this->eventDispatcher->dispatch(
            $event,
            $isValid ? WorkflowEvents::STEP_VALIDATED : WorkflowEvents::STEP_VALIDATION_FAILED
        );

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