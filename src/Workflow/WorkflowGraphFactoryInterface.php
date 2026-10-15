<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

interface WorkflowGraphFactoryInterface extends WorkflowFactoryInterface
{
	public const CONTEXT_GRAPH_KEY = '_workflow_graph';

	/** @param array<string,mixed> $context
	 * @param array<string,mixed> $document
	 */
	public function createFromDocument(string $name, array $context, array $document): RuntimeWorkflowInterface;
}
