<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Action;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workflow.transition_action')]
interface WorkflowTransitionActionInterface
{
	public function getKey(): string;

	public function execute(WorkflowActionContext $context): void;
}
