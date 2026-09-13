<?php

namespace iikiti\CMS\Workflow\StepProvider;

use iikiti\CMS\Workflow\Step\DynamicFormStep;
use iikiti\CMS\Workflow\StepProviderInterface;
use iikiti\CMS\Workflow\WorkflowInterface;
use iikiti\CMS\Workflow\WorkflowStepInterface;

/**
 * Builds dynamic form steps from a workflow context.
 *
 * The context is expected to contain a "steps" key holding step definitions.
 * A future storage-backed provider can populate the same context from the
 * database, so the rendering and navigation layers need no changes.
 */
class DynamicFormStepProvider implements StepProviderInterface
{
	public const WORKFLOW_NAME = 'dynamic_form';
	public const CONTEXT_KEY = 'steps';

	private const MAX_STEPS = 20;
	private const MAX_FIELDS_PER_STEP = 50;

	public function getWorkflowName(): string
	{
		return self::WORKFLOW_NAME;
	}

	public function supports(mixed $context): bool
	{
		return is_array($context) && is_array($context[self::CONTEXT_KEY] ?? null);
	}

	/**
	 * @return array<WorkflowStepInterface>
	 */
	public function provideSteps(WorkflowInterface $workflow, mixed $context): array
	{
		if (!is_array($context) || !is_array($context[self::CONTEXT_KEY] ?? null)) {
			return [];
		}

		$steps = [];
		foreach ($context[self::CONTEXT_KEY] as $definition) {
			if (count($steps) >= self::MAX_STEPS) {
				break;
			}

			$step = $this->createStep($definition);
			if (null !== $step) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	private function createStep(mixed $definition): ?WorkflowStepInterface
	{
		if (!is_array($definition)) {
			return null;
		}

		$id = $definition['id'] ?? null;
		$name = $definition['name'] ?? null;
		$fields = $definition['fields'] ?? null;

		if (!is_string($id) || '' === $id || !is_string($name) || !is_array($fields)) {
			return null;
		}

		$fields = array_slice(
			array_values(array_filter($fields, 'is_array')),
			0,
			self::MAX_FIELDS_PER_STEP
		);

		return new DynamicFormStep($id, $name, $fields);
	}
}
