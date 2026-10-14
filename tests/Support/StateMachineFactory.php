<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Support;

use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\DependencyInjection\AttributeReader;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Wires a class declaring a state machine with #[AsWorkflow] the same way the
 * container does: the machine is rebuilt from its own attribute definitions,
 * receives the matching StateMachine over a MethodMarkingStore, and exposes
 * the WorkflowTrait methods without needing the full container in unit tests.
 */
final class StateMachineFactory
{
	public static function wire(object $machine): object
	{
		if (!method_exists($machine, 'setWorkflow')) {
			throw new \InvalidArgumentException($machine::class.' does not use the WorkflowTrait.');
		}

		$class = new \ReflectionClass($machine);
		$attributes = $class->getAttributes(AsWorkflow::class, \ReflectionAttribute::IS_INSTANCEOF);
		$attribute = $attributes[0] ?? throw new \LogicException($class->name.' is missing #[AsWorkflow].');
		$configs = (new AttributeReader())->read($attribute->newInstance(), $class);
		\assert(1 === \count($configs));
		$config = $configs[array_key_first($configs)];

		$transitionConfigs = $config['transitions'];
		\assert(\is_array($transitionConfigs));
		$transitions = [];
		foreach ($transitionConfigs as $transitionConfig) {
			\assert(\is_array($transitionConfig));
			$transitionName = $transitionConfig['name'];
			\assert(\is_string($transitionName));
			$froms = $transitionConfig['from'];
			$tos = $transitionConfig['to'];
			\assert(\is_array($froms) && \is_array($tos));
			foreach (array_column($froms, 'place') as $from) {
				foreach (array_column($tos, 'place') as $to) {
					\assert(\is_string($from) && \is_string($to));
					$transitions[] = new Transition($transitionName, [$from], [$to]);
				}
			}
		}

		$places = $config['places'];
		\assert(\is_array($places));
		$markingStore = $config['marking_store'];
		\assert(\is_array($markingStore) && \is_string($markingStore['property']));
		$type = $config['type'];
		\assert(\is_string($type));

		$workflow = new StateMachine(
			new Definition(array_column($places, 'name'), $transitions),
			new MethodMarkingStore('state_machine' === $type, $markingStore['property']),
		);

		$machine->setWorkflow($workflow);

		return $machine;
	}
}
