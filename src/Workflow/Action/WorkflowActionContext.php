<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Action;

use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Workflow\Runtime\AppliedWorkflowTransition;

final readonly class WorkflowActionContext
{
	public function __construct(
		public WorkflowDefinitionVersion $definitionVersion,
		public WorkflowInstance $instance,
		public AppliedWorkflowTransition $transition,
		public ?User $actor,
		public ?object $subject,
	) {
	}
}
