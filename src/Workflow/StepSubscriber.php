<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StepSubscriber implements EventSubscriberInterface
{
    private array $stepProviders = [];

    public function __construct(iterable $stepProviders = [])
    {
        foreach ($stepProviders as $provider) {
            $this->addStepProvider($provider);
        }
    }

    public function addStepProvider(StepProviderInterface $provider): void
    {
        $this->stepProviders[$provider->getWorkflowName()][] = $provider;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkflowEvents::WORKFLOW_INITIALIZED => 'onWorkflowInitialized',
        ];
    }

    public function onWorkflowInitialized(WorkflowEvent $event): void
    {
        $workflow = $event->getWorkflow();
        $workflowName = $workflow->getName();
        
        if (!isset($this->stepProviders[$workflowName])) {
            return;
        }

        $context = $workflow->getContext();
        
        foreach ($this->stepProviders[$workflowName] as $provider) {
            if ($provider->supports($context)) {
                $steps = $provider->provideSteps($workflow, $context);
                foreach ($steps as $step) {
                    $workflow->addStep($step);
                }
            }
        }
    }
}