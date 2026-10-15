<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

final readonly class AppliedWorkflowTransition
{
	/**
	 * @param list<string>        $from
	 * @param list<string>        $to
	 * @param array<string,mixed> $metadata
	 * @param array<string,int>   $fromMarking
	 * @param array<string,int>   $toMarking
	 */
	public function __construct(
		public string $name,
		public array $from,
		public array $to,
		public array $metadata,
		public array $fromMarking,
		public array $toMarking,
	) {
	}
}
