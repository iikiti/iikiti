<?php

namespace iikiti\CMS\Workflow\Step\Mfa;

use iikiti\CMS\Workflow\Step\AbstractFormWorkflowStep;

/**
 * Shared behaviour for multi-factor challenge steps.
 */
abstract class AbstractMfaStep extends AbstractFormWorkflowStep
{
	/**
	 * Reads the submitted code from the workflow context.
	 *
	 * The form fields are all named "code", and the submitted data is merged
	 * into the validation context before the step is asked to validate.
	 *
	 * @param array<string,mixed> $context
	 */
	protected function extractCode(array $context, string $field = 'code'): ?string
	{
		$code = $context[$field] ?? null;
		if (!is_string($code)) {
			return null;
		}

		$code = trim($code);

		return '' !== $code ? $code : null;
	}
}
