<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\Form\FormTypeInterface;

/**
 * A workflow step that collects input through a Symfony form.
 *
 * The step describes the form used to render it, while validation and
 * processing of the submitted data remain the responsibility of the step
 * implementation. This keeps the workflow generic: the same navigation and
 * persistence logic drives any form-based workflow, from authentication
 * challenges to user-built multi-step forms.
 */
interface FormStepInterface extends WorkflowStepInterface
{
	/**
	 * Fully qualified class name of the form type rendered for this step.
	 *
	 * @return class-string<FormTypeInterface<mixed>>
	 */
	public function getFormType(): string;

	/**
	 * Options passed to the form type when it is created.
	 *
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function getFormOptions(array $context): array;

	/**
	 * Runs before the step is rendered so it can set up any state it needs,
	 * such as issuing a challenge.
	 *
	 * Implementations must be safe to call repeatedly, because the returned
	 * data is merged into the workflow context and may already be present.
	 *
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed> Context data produced by the preparation
	 */
	public function prepare(array $context): array;
}
