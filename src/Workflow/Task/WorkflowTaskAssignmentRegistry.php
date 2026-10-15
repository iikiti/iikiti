<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Task;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WorkflowTaskAssignmentRegistry
{
	/** @var array<string,WorkflowTaskAssignmentResolverInterface> */
	private array $resolvers = [];

	/** @param iterable<WorkflowTaskAssignmentResolverInterface> $resolvers */
	public function __construct(
		#[AutowireIterator('workflow.task_assignment_resolver')]
		iterable $resolvers = [],
	) {
		foreach ($resolvers as $resolver) {
			$key = $resolver->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key) || isset($this->resolvers[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate workflow task assignment key "%s".', $key));
			}
			$this->resolvers[$key] = $resolver;
		}
	}

	public function get(string $key): ?WorkflowTaskAssignmentResolverInterface
	{
		return $this->resolvers[$key] ?? null;
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->resolvers);
	}
}
