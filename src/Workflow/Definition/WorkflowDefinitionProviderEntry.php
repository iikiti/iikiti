<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Definition;

final readonly class WorkflowDefinitionProviderEntry
{
	public function __construct(
		public string $ownerKey,
		public string $sourceVersion,
		public WorkflowDefinitionData $definition,
	) {
	}
}
