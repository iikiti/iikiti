<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

final readonly class WorkflowTransitionResult
{
	/**
	 * @param array<string,int>               $fromMarking
	 * @param array<string,int>               $toMarking
	 * @param list<AppliedWorkflowTransition> $transitions
	 */
	public function __construct(
		public array $fromMarking,
		public array $toMarking,
		public array $transitions,
		public bool $complete,
	) {
	}
}
