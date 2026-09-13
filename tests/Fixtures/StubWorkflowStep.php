<?php

namespace iikiti\CMS\Tests\Fixtures;

use iikiti\CMS\Workflow\Step\AbstractWorkflowStep;

/**
 * Minimal workflow step used to exercise navigation and submission.
 */
final class StubWorkflowStep extends AbstractWorkflowStep
{
	/**
	 * @param array<string,mixed> $result
	 */
	public function __construct(
		string $id,
		private readonly bool $valid = true,
		private readonly array $result = [],
	) {
		parent::__construct($id, $id);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function validate(array $context): bool
	{
		return $this->valid;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 *
	 * @return array<string,mixed>
	 */
	public function process(array $data, array $context): array
	{
		return $this->result;
	}
}
