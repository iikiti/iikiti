<?php

namespace iikiti\CMS\Workflow\Step;

use iikiti\CMS\Form\Type\DynamicFormType;

/**
 * A workflow step whose fields are described at runtime.
 *
 * The field list is supplied when the step is created, which is what allows a
 * user-built form definition to be rendered without a dedicated step class.
 */
class DynamicFormStep extends AbstractFormWorkflowStep
{
	/**
	 * @param array<int,array<mixed>> $fields
	 * @param array<string,mixed>     $configuration
	 */
	public function __construct(
		string $id,
		string $name,
		private readonly array $fields,
		array $configuration = [],
	) {
		parent::__construct($id, $name, DynamicFormType::class, true, false, $configuration);
	}

	/**
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function getFormOptions(array $context): array
	{
		return ['fields' => $this->fields];
	}
}
