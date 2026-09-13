<?php

namespace iikiti\CMS\Workflow;

use Symfony\Component\Form\FormInterface;

/**
 * Outcome of binding and submitting a request against the current step.
 */
final class FormSubmissionResult
{
	/**
	 * @param FormInterface<mixed> $form     The bound form, for re-rendering
	 * @param bool                 $accepted Whether the step accepted the data
	 */
	public function __construct(
		public readonly FormInterface $form,
		public readonly bool $accepted,
	) {
	}
}
