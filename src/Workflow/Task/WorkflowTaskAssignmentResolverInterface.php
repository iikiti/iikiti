<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Task;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workflow.task_assignment_resolver')]
interface WorkflowTaskAssignmentResolverInterface
{
	public function getKey(): string;

	public function resolve(WorkflowTaskAssignmentContext $context): WorkflowTaskAssignment;
}
