<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Task;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;

final readonly class WorkflowTaskAssignmentContext
{
	/** @param array<string,mixed> $placeMetadata */
	public function __construct(
		public WorkflowInstance $instance,
		public string $place,
		public array $placeMetadata,
		public ?Site $site,
		public ?User $actor,
		public ?object $subject,
	) {
	}
}
