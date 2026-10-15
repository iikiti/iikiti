<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Workflow as SymfonyWorkflow;
use Symfony\Component\Workflow\WorkflowInterface as SymfonyWorkflowInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RuntimeWorkflowFactory
{
	public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
	{
	}

	public function create(
		string $name,
		WorkflowGraph $graph,
		?MarkingStoreInterface $markingStore = null,
	): SymfonyWorkflowInterface {
		return new SymfonyWorkflow(
			$graph->toValidatedDefinition($name),
			$markingStore ?? new MethodMarkingStore(false, 'marking'),
			$this->eventDispatcher,
			$name,
		);
	}
}
