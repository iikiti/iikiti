<?php

namespace iikiti\CMS\Workflow;

/**
 * Default workflow factory.
 *
 * Rebuilds any workflow through the {@see WorkflowManager}, which re-runs the
 * registered step providers for the workflow name. The persisted context,
 * position and completion flag are then restored.
 */
class WorkflowFactory implements WorkflowFactoryInterface
{
	public function __construct(private readonly WorkflowManager $workflowManager)
	{
	}

	/**
	 * @param array<string,mixed> $state
	 */
	public function rebuild(string $name, array $state): WorkflowInterface
	{
		$context = is_array($state['context'] ?? null) ? $state['context'] : [];
		$workflow = $this->workflowManager->buildWorkflow($name, $context);

		$currentStepId = $state['current_step'] ?? null;
		if (is_string($currentStepId) && '' !== $currentStepId) {
			$workflow->goToStep($currentStepId);
		}

		if (true === ($state['complete'] ?? false)) {
			$workflow->complete();
		}

		return $workflow;
	}
}
