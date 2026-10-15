<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;

interface WorkflowGraphProviderInterface extends StepProviderInterface
{
	public function provideGraph(WorkflowInterface $workflow, mixed $context): WorkflowGraph;
}
