<?php

namespace iikiti\CMS\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Builds a form from a field definition list.
 *
 * Each field is an array with a "name", an optional form type class and
 * optional type options. This is the rendering half of the dynamic form
 * system: a definition (built by an administrator or loaded from storage) is
 * turned into a real Symfony form.
 *
 * @extends AbstractType<array<string,mixed>>
 */
class DynamicFormType extends AbstractType
{
	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		foreach ($options['fields'] as $field) {
			if (!is_array($field)) {
				continue;
			}

			$name = $field['name'] ?? null;
			if (!is_string($name) || '' === $name) {
				continue;
			}

			$builder->add($name, $this->resolveType($field['type'] ?? null), $this->resolveOptions($field['options'] ?? null));
		}
	}

	public function configureOptions(OptionsResolver $resolver): void
	{
		$resolver->setDefaults([
			'data_class' => null,
			'fields' => [],
		]);
		$resolver->setAllowedTypes('fields', 'array');
	}

	/**
	 * @return class-string<FormTypeInterface<mixed>>
	 */
	private function resolveType(mixed $type): string
	{
		if (is_string($type) && class_exists($type) && is_a($type, FormTypeInterface::class, true)) {
			return $type;
		}

		return TextType::class;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolveOptions(mixed $options): array
	{
		return is_array($options) ? $options : [];
	}
}
