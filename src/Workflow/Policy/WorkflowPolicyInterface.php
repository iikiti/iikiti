<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workflow.policy')]
interface WorkflowPolicyInterface
{
	public function getKey(): string;

	public function isAllowed(WorkflowPolicyContext $context): bool;
}
