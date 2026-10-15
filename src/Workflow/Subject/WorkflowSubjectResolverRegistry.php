<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Subject;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WorkflowSubjectResolverRegistry
{
	/** @var array<string,WorkflowSubjectResolverInterface> */
	private array $resolvers = [];

	/** @param iterable<WorkflowSubjectResolverInterface> $resolvers */
	public function __construct(
		#[AutowireIterator('workflow.subject_resolver')]
		iterable $resolvers = [],
	) {
		foreach ($resolvers as $resolver) {
			$key = $resolver->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $key) || isset($this->resolvers[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate workflow subject resolver key "%s".', $key));
			}
			$this->resolvers[$key] = $resolver;
		}
	}

	public function get(string $key): ?WorkflowSubjectResolverInterface
	{
		return $this->resolvers[$key] ?? null;
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->resolvers);
	}
}
