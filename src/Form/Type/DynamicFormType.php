<?php

namespace iikiti\CMS\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotBlank;

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
	private const ALLOWED_TYPES = [
		'checkbox' => CheckboxType::class,
		CheckboxType::class => CheckboxType::class,
		'choice' => ChoiceType::class,
		ChoiceType::class => ChoiceType::class,
		'email' => EmailType::class,
		EmailType::class => EmailType::class,
		'number' => NumberType::class,
		NumberType::class => NumberType::class,
		'text' => TextType::class,
		TextType::class => TextType::class,
		'textarea' => TextareaType::class,
		TextareaType::class => TextareaType::class,
	];

	public function buildForm(FormBuilderInterface $builder, array $options): void
	{
		foreach ($options['fields'] as $field) {
			if (!is_array($field)) {
				continue;
			}

			$name = $field['name'] ?? null;
			if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $name) || '_token' === $name) {
				continue;
			}

			$type = $this->resolveType($field['type'] ?? null);
			$options = $this->resolveOptions($field['options'] ?? null, $type);
			if ($options['required'] ?? true) {
				$options['constraints'] = CheckboxType::class === $type ? [new IsTrue()] : [new NotBlank()];
			}
			$builder->add($name, $type, $options);
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

	private function resolveType(mixed $type): string
	{
		return is_string($type) ? (self::ALLOWED_TYPES[$type] ?? TextType::class) : TextType::class;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolveOptions(mixed $options, string $type): array
	{
		if (!is_array($options)) {
			return [];
		}

		$resolved = [];
		foreach (['label', 'placeholder', 'help'] as $option) {
			$value = $options[$option] ?? null;
			if (is_string($value) && mb_strlen($value) <= 512) {
				$resolved[$option] = $value;
			}
		}
		foreach (['required', 'disabled'] as $option) {
			if (is_bool($options[$option] ?? null)) {
				$resolved[$option] = $options[$option];
			}
		}

		if (ChoiceType::class === $type) {
			$choices = $options['choices'] ?? null;
			if (is_array($choices) && count($choices) <= 100) {
				$safeChoices = [];
				foreach ($choices as $value => $label) {
					if (is_string($label) && mb_strlen($label) <= 256) {
						$safeChoices[$value] = $label;
					}
				}
				$resolved['choices'] = $safeChoices;
			}
			foreach (['multiple', 'expanded'] as $option) {
				if (is_bool($options[$option] ?? null)) {
					$resolved[$option] = $options[$option];
				}
			}
		}

		return $resolved;
	}
}
