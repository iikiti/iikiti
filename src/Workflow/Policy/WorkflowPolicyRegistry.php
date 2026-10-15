<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WorkflowPolicyRegistry
{
	/** @var array<string,WorkflowPolicyInterface> */
	private array $policies = [];

	/** @param iterable<WorkflowPolicyInterface> $policies */
	public function __construct(
		#[AutowireIterator('workflow.policy')]
		iterable $policies = [],
	) {
		foreach ($policies as $policy) {
			$key = $policy->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key)) {
				throw new \LogicException(sprintf('Workflow policy has invalid key "%s".', $key));
			}
			if (isset($this->policies[$key])) {
				throw new \LogicException(sprintf('Workflow policy key "%s" is registered more than once.', $key));
			}
			$this->policies[$key] = $policy;
		}
	}

	public function get(string $key): ?WorkflowPolicyInterface
	{
		return $this->policies[$key] ?? null;
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->policies);
	}
}
