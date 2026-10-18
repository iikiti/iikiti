<?php

declare(strict_types=1);

namespace iikiti\CMS\Batch;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ScheduledTaskProviderRegistry
{
	/** @var array<string,ScheduledTaskProviderInterface> */
	private array $providers = [];

	/** @param iterable<ScheduledTaskProviderInterface> $providers */
	public function __construct(#[AutowireIterator('iikiti.scheduled_task_provider')] iterable $providers = [])
	{
		foreach ($providers as $provider) {
			$key = $provider->getKey();
			if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key) || isset($this->providers[$key])) {
				throw new \LogicException(sprintf('Invalid or duplicate scheduled task provider key "%s".', $key));
			}
			if (!in_array($provider->getScope(), ['site', 'global'], true)) {
				throw new \LogicException(sprintf('Scheduled task provider "%s" has an invalid scope.', $key));
			}
			$this->providers[$key] = $provider;
		}
	}

	public function get(string $key): ScheduledTaskProviderInterface
	{
		return $this->providers[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown scheduled task provider "%s".', $key));
	}

	/** @return list<array{key:string,name:string,scope:string,allowOverlap:bool,options:array<string,array{type:string,label:string,required?:bool,default?:mixed}>}> */
	public function describe(): array
	{
		$definitions = [];
		foreach ($this->providers as $key => $provider) {
			$definitions[] = [
				'key' => $key,
				'name' => $provider->getName(),
				'scope' => $provider->getScope(),
				'allowOverlap' => $provider->allowsOverlap(),
				'options' => $provider->getOptionsSchema(),
			];
		}

		return $definitions;
	}
}
