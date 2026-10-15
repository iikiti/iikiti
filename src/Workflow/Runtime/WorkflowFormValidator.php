<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Form\Type\DynamicFormType;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use Symfony\Component\Form\FormFactoryInterface;

final class WorkflowFormValidator
{
	public function __construct(
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly FormFactoryInterface $formFactory,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function validateTransitionData(WorkflowInstance $instance, string $transitionName, array $data): array
	{
		$graph = $this->graphCompiler->compile($instance->getDefinitionVersion()->getGraph());
		$transition = null;
		foreach ($graph->transitions as $candidate) {
			if ($candidate->name === $transitionName) {
				$transition = $candidate;
				break;
			}
		}
		if (null === $transition) {
			throw new \InvalidArgumentException(sprintf('Workflow transition "%s" is not defined.', $transitionName));
		}

		$formPlaces = array_values(array_filter(
			$transition->from,
			fn (string $place): bool => is_array($graph->placeMetadata($place)['form'] ?? null),
		));
		if ([] === $formPlaces) {
			if ([] !== $data) {
				throw new \InvalidArgumentException('This workflow transition does not accept form data.');
			}

			return [];
		}
		if (1 !== count($formPlaces)) {
			throw new \InvalidArgumentException('A form transition must have exactly one form source place.');
		}

		$fields = $graph->placeMetadata($formPlaces[0])['form']['fields'];
		$formFields = [];
		foreach ($fields as $field) {
			$options = $field['options'] ?? [];
			if (isset($field['label'])) {
				$options['label'] = $field['label'];
			}
			if (isset($field['required'])) {
				$options['required'] = $field['required'];
			}
			$formFields[] = [
				'name' => $field['name'],
				'type' => $field['type'] ?? 'text',
				'options' => $options,
			];
		}

		$form = $this->formFactory->create(DynamicFormType::class, null, [
			'fields' => $formFields,
			'csrf_protection' => false,
		]);
		$form->submit($data);
		if (!$form->isValid()) {
			throw new \InvalidArgumentException('Workflow form data is invalid.');
		}
		$processed = $form->getData();
		if (!is_array($processed)) {
			throw new \LogicException('Workflow form did not return an object of field values.');
		}

		return $processed;
	}
}
