<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Step\DynamicFormStep;

final class WorkflowGraphStepFactory
{
	/** @return list<WorkflowStepInterface> */
	public function createSteps(WorkflowGraph $graph): array
	{
		$steps = [];
		foreach ($graph->places as $place) {
			$metadata = $graph->placeMetadata($place);
			$form = $metadata['form'] ?? null;
			if (!is_array($form) || !is_array($form['fields'] ?? null)) {
				continue;
			}

			$fields = [];
			foreach ($form['fields'] as $field) {
				$options = $field['options'] ?? [];
				if (isset($field['label'])) {
					$options['label'] = $field['label'];
				}
				if (isset($field['required'])) {
					$options['required'] = $field['required'];
				}
				$fields[] = ['name' => $field['name'], 'type' => $field['type'] ?? 'text', 'options' => $options];
			}
			$steps[] = new DynamicFormStep($place, (string) ($form['label'] ?? $metadata['label'] ?? $place), $fields);
		}

		return $steps;
	}
}
