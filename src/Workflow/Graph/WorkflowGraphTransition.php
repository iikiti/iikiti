<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Graph;

final readonly class WorkflowGraphTransition
{
	/**
	 * @param list<string>        $from
	 * @param list<string>        $to
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		public string $name,
		public array $from,
		public array $to,
		public array $metadata = [],
	) {
	}
}
