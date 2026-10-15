<?php

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;

/**
 * Default workflow factory.
 *
 * Rebuilds any workflow through the {@see WorkflowManager}, which re-runs the
 * registered step providers for the workflow name. The persisted context,
 * position and completion flag are then restored.
 */
class WorkflowFactory implements WorkflowGraphFactoryInterface
{
	private readonly WorkflowGraphCompiler $graphCompiler;
	private readonly WorkflowGraphStepFactory $graphStepFactory;

	public function __construct(
		private readonly WorkflowManager $workflowManager,
		?WorkflowGraphCompiler $graphCompiler = null,
		?WorkflowGraphStepFactory $graphStepFactory = null,
	) {
		$this->graphCompiler = $graphCompiler ?? new WorkflowGraphCompiler();
		$this->graphStepFactory = $graphStepFactory ?? new WorkflowGraphStepFactory();
	}

	/**
	 * @param array<string,mixed> $state
	 */
	public function rebuild(string $name, array $state): WorkflowInterface
	{
		$context = is_array($state['context'] ?? null) ? $state['context'] : [];
		$document = $context[self::CONTEXT_GRAPH_KEY] ?? null;
		$workflow = is_array($document) ?
			$this->createFromDocument($name, $context, $document) :
			$this->workflowManager->buildWorkflow($name, $context);

		if ($workflow instanceof RuntimeWorkflowInterface) {
			$marking = $state['marking'] ?? null;
			if (is_array($marking) && [] !== $marking) {
				try {
					$workflow->restoreMarking($marking);
				} catch (\InvalidArgumentException) {
					$workflow->restoreLegacyPosition(
						is_string($state['current_step'] ?? null) ? $state['current_step'] : null,
						true === ($state['complete'] ?? false),
					);
				}
			} else {
				$workflow->restoreLegacyPosition(
					is_string($state['current_step'] ?? null) ? $state['current_step'] : null,
					true === ($state['complete'] ?? false),
				);
			}
		} else {
			$currentStepId = $state['current_step'] ?? null;
			if (is_string($currentStepId) && '' !== $currentStepId) {
				$workflow->goToStep($currentStepId);
			}
			if (true === ($state['complete'] ?? false)) {
				$workflow->complete();
			}
		}

		return $workflow;
	}

	public function createFromDocument(string $name, array $context, array $document): RuntimeWorkflowInterface
	{
		$graph = $this->graphCompiler->compile($document);
		$steps = $this->graphStepFactory->createSteps($graph);

		return $this->workflowManager->buildWorkflowFromGraph($name, $context, $graph, $steps);
	}
}
