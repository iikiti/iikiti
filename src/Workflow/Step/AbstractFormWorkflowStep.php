<?php

namespace iikiti\CMS\Workflow\Step;

use iikiti\CMS\Workflow\FormStepInterface;
use Symfony\Component\Form\FormTypeInterface;

/**
 * Base class for workflow steps that render a Symfony form.
 *
 * Subclasses normally only need to provide the form type and override
 * {@see validate()} or {@see process()} when server-side checks are required.
 */
abstract class AbstractFormWorkflowStep extends AbstractWorkflowStep implements FormStepInterface
{
	/** @var class-string<FormTypeInterface<mixed>> */
	private readonly string $formType;

	/**
	 * @param class-string<FormTypeInterface<mixed>> $formType
	 * @param array<string,mixed>                    $configuration
	 */
	public function __construct(
		string $id,
		string $name,
		string $formType,
		bool $required = true,
		bool $skippable = false,
		array $configuration = [],
	) {
		parent::__construct($id, $name, $required, $skippable, $configuration);
		$this->formType = $formType;
	}

	/**
	 * @return class-string<FormTypeInterface<mixed>>
	 */
	public function getFormType(): string
	{
		return $this->formType;
	}

	/**
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function getFormOptions(array $context): array
	{
		return [];
	}

	/**
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function prepare(array $context): array
	{
		return [];
	}

	/**
	 * Form steps rely on Symfony form validation by default. Steps that must
	 * also verify submitted values against server-side state (for example a
	 * one-time code) override this method.
	 *
	 * @param array<string,mixed> $context
	 */
	public function validate(array $context): bool
	{
		return true;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function process(array $data, array $context): array
	{
		return $data;
	}
}
