<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

interface WorkflowTransitionCommittedStepInterface
{
	public function onTransitionCommitted(): void;
}
