<?php

namespace iikiti\CMS\Workflow;

/**
 * Rebuilds a workflow and its steps from state persisted between requests.
 *
 * Steps are rebuilt rather than unserialized so they may depend on services
 * and request-scoped data that cannot be stored in the session.
 */
interface WorkflowFactoryInterface
{
	/**
	 * Rebuild a workflow from previously persisted state.
	 *
	 * @param array<string,mixed> $state State produced by {@see WorkflowSessionStorage::extractState()}
	 */
	public function rebuild(string $name, array $state): WorkflowInterface;
}
