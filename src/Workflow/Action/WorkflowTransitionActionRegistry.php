<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Action;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WorkflowTransitionActionRegistry
{
	/** @var array<string,WorkflowTransitionActionInterface> */
	private array $actions = [];

	/** @param iterable<WorkflowTransitionActionInterface> $actions */
	public function __construct(
		#[AutowireIterator('workflow.transition_action')]
		iterable $actions = [],
	) {
		foreach ($actions as $action) {
			$key = $action->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D', $key) || isset($this->actions[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate workflow action key "%s".', $key));
			}
			$this->actions[$key] = $action;
		}
	}

	public function get(string $key): ?WorkflowTransitionActionInterface
	{
		return $this->actions[$key] ?? null;
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->actions);
	}
}
