<?php

declare(strict_types=1);

namespace iikiti\CMS\Scheduler;

use iikiti\CMS\Batch\BatchScheduleRunner;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SchedulerAdapterRegistry
{
	/** @var array<string,SchedulerAdapterInterface> */
	private array $adapters = [];

	/** @param iterable<SchedulerAdapterInterface> $adapters */
	public function __construct(
		#[AutowireIterator('iikiti.scheduler_adapter')] iterable $adapters = [],
		private readonly string $selected = 'symfony',
	) {
		foreach ($adapters as $adapter) {
			$key = $adapter->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key) || isset($this->adapters[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate scheduler adapter key "%s".', $key));
			}
			$this->adapters[$key] = $adapter;
		}
		if (!isset($this->adapters[$selected])) {
			throw new \LogicException(sprintf('Selected scheduler adapter "%s" is not registered.', $selected));
		}
	}

	public function getSelectedKey(): string
	{
		return $this->selected;
	}

	public function getSelected(): SchedulerAdapterInterface
	{
		return $this->adapters[$this->selected];
	}

	/** @return list<string> */
	public function getKeys(): array
	{
		return array_keys($this->adapters);
	}

	public function runDueSchedules(BatchScheduleRunner $runner): int
	{
		return $this->getSelected()->runDueSchedules($runner);
	}
}
