<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\Workflow;

use Symfony\Component\Form\FormConfigInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Turns a workflow step's Symfony Form into a JSON-serialisable field schema
 * the front-end can render. Mapping is coarse on purpose: the server always
 * re-validates via the real FormType, so client-side field types never relax
 * validation.
 */
final class WorkflowSchemaExtractor
{
	/**
	 * @param FormInterface<mixed> $form
	 *
	 * @return list<array<string,mixed>>
	 */
	public function extract(FormInterface $form): array
	{
		$fields = [];
		foreach ($form->all() as $child) {
			$fields[] = $this->extractField($child);
		}

		return $fields;
	}

	/**
	 * @param FormInterface<mixed> $form
	 * @param array<string,mixed> $data
	 */
	public function populate(FormInterface $form, array $data): bool
	{
		$accepted = false;
		foreach ($form->all() as $name => $child) {
			if (array_key_exists($name, $data)) {
				$child->submit($data[$name]);
				$accepted = true;
			}
		}

		return $accepted;
	}

	/**
	 * @param FormInterface<mixed> $field
	 *
	 * @return array<string,mixed>
	 */
	private function extractField(FormInterface $field): array
	{
		$config = $field->getConfig();
		$inner = $config->getType()->getInnerType();
		$fqcn = $inner::class;

		$out = [
			'key' => $field->getName(),
			'label' => $this->label($config),
			'type' => $this->mapType($fqcn),
			'required' => (bool) $config->getOption('required'),
			'disabled' => (bool) $config->getOption('disabled'),
		];

		$placeholder = $config->getOption('placeholder');
		if (is_string($placeholder) && $placeholder !== '') {
			$out['placeholder'] = $placeholder;
		}

		$choices = $this->resolveChoices($field);
		if ($choices !== null) {
			$out['options'] = $choices;
			$out['type'] = 'select';
		}

		return $out;
	}

	/**
	 * @param FormConfigInterface<mixed> $config
	 */
	private function label(FormConfigInterface $config): string
	{
		$label = $config->getOption('label');
		if (is_string($label) && $label !== '') {
			return $label;
		}
		$name = (string) $config->getName();

		return ucfirst(str_replace(['_', '.', '-'], ' ', $name));
	}

	/**
	 * @param FormInterface<mixed> $field
	 *
	 * @return list<array{value:string,label:string}>|null
	 */
	private function resolveChoices(FormInterface $field): ?array
	{
		$config = $field->getConfig();
		$choices = $config->getOption('choices');
		if (!is_array($choices)) {
			return null;
		}

		// MultipleType / expanded choice lists.
		$out = [];
		foreach ($choices as $value => $label) {
			$out[] = ['value' => (string) $value, 'label' => (string) ($label instanceof \Stringable ? $label : $label)];
		}

		return $out;
	}

	private function mapType(string $fqcn): string
	{
		$plain = strtolower((string) preg_replace('/^.*\\\\/', '', $fqcn));
		$map = [
			'texttype' => 'text',
			'textareatype' => 'textarea',
			'choicetype' => 'select',
			'checkboxtype' => 'toggle',
			'passwordtype' => 'text',
			'emailtype' => 'text',
			'numbertype' => 'number',
			'hidden_type' => 'hidden',
		];

		return $map[$plain] ?? 'text';
	}
}
