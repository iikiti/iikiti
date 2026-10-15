<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Graph;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Validator\WorkflowValidator;

final readonly class WorkflowGraph
{
	/**
	 * @param list<string>                      $places
	 * @param list<WorkflowGraphTransition>     $transitions
	 * @param list<string>                      $initialPlaces
	 * @param list<string>                      $finalPlaces
	 * @param array<string,array<string,mixed>> $placesMetadata
	 * @param array<string,mixed>               $workflowMetadata
	 */
	public function __construct(
		public array $places,
		public array $transitions,
		public array $initialPlaces,
		public array $finalPlaces,
		public array $placesMetadata = [],
		public array $workflowMetadata = [],
	) {
		$this->validate();
	}

	/**
	 * Validates the compiled graph with Symfony's own workflow validator after
	 * every construction, keeping runtime instances consistent with it.
	 */
	public function toValidatedDefinition(string $name): Definition
	{
		$definition = $this->toDefinition();
		(new WorkflowValidator())->validate($definition, $name);

		return $definition;
	}

	public function toDefinition(): Definition
	{
		$transitions = [];
		/** @var \SplObjectStorage<Transition, array<array-key,mixed>> $transitionMetadata */
		$transitionMetadata = new \SplObjectStorage();
		foreach ($this->transitions as $transition) {
			$symfonyTransition = new Transition($transition->name, $transition->from, $transition->to);
			$transitions[] = $symfonyTransition;
			if ([] !== $transition->metadata) {
				$transitionMetadata[$symfonyTransition] = $transition->metadata;
			}
		}
		$placesMetadata = $this->placesMetadata;
		foreach ($this->finalPlaces as $place) {
			$placesMetadata[$place]['final'] = true;
		}

		return new Definition(
			$this->places,
			$transitions,
			$this->initialPlaces,
			new InMemoryMetadataStore($this->workflowMetadata, $placesMetadata, $transitionMetadata),
		);
	}

	/** @return array<string,mixed> */
	public function toDocument(): array
	{
		$places = [];
		foreach ($this->places as $place) {
			$metadata = $this->placeMetadata($place);
			$places[] = [] === $metadata ? $place : ['id' => $place, 'metadata' => $metadata];
		}
		$transitions = [];
		foreach ($this->transitions as $transition) {
			$transitions[] = [
				'name' => $transition->name,
				'from' => $transition->from,
				'to' => $transition->to,
				'metadata' => $transition->metadata,
			];
		}

		return [
			'type' => 'workflow',
			'places' => $places,
			'transitions' => $transitions,
			'initialPlaces' => $this->initialPlaces,
			'finalPlaces' => $this->finalPlaces,
			'metadata' => $this->workflowMetadata,
		];
	}

	/** @return array<string,true> */
	public function placeSet(): array
	{
		return array_fill_keys($this->places, true);
	}

	/** @return array<string,mixed> */
	public function placeMetadata(string $place): array
	{
		return $this->placesMetadata[$place] ?? [];
	}

	private function validate(): void
	{
		if ([] === $this->places || [] === $this->initialPlaces || [] === $this->finalPlaces) {
			throw new \InvalidArgumentException('A workflow graph requires places, initial places, and final places.');
		}

		if (count($this->places) !== count(array_unique($this->places))) {
			throw new \InvalidArgumentException('Workflow graph place IDs must be unique.');
		}

		$places = $this->placeSet();
		foreach (array_merge($this->initialPlaces, $this->finalPlaces) as $place) {
			if (!isset($places[$place])) {
				throw new \InvalidArgumentException(sprintf('Workflow graph place "%s" is not declared.', $place));
			}
		}
		foreach ($this->placesMetadata as $place => $metadata) {
			if (!isset($places[$place])) {
				throw new \InvalidArgumentException(sprintf('Workflow graph metadata references undeclared place "%s".', $place));
			}
		}

		$transitionNames = [];
		$outgoingPlaces = [];
		foreach ($this->transitions as $transition) {
			if ('' === trim($transition->name) || [] === $transition->from || [] === $transition->to) {
				throw new \InvalidArgumentException('Workflow graph transitions require a name, at least one source, and at least one target.');
			}
			if (isset($transitionNames[$transition->name])) {
				throw new \InvalidArgumentException(sprintf('Workflow graph transition names must be globally unique; "%s" is duplicated.', $transition->name));
			}
			$transitionNames[$transition->name] = true;

			foreach (array_merge($transition->from, $transition->to) as $place) {
				if (!isset($places[$place])) {
					throw new \InvalidArgumentException(sprintf('Workflow graph transition "%s" references undeclared place "%s".', $transition->name, $place));
				}
			}
			foreach ($transition->from as $place) {
				$outgoingPlaces[$place] = true;
			}
		}

		foreach ($this->finalPlaces as $place) {
			if (isset($outgoingPlaces[$place])) {
				throw new \InvalidArgumentException(sprintf('Final workflow place "%s" cannot have outgoing transitions.', $place));
			}
		}

		$reachable = array_fill_keys($this->initialPlaces, true);
		$changed = true;
		while ($changed) {
			$changed = false;
			foreach ($this->transitions as $transition) {
				if ([] !== array_diff($transition->from, array_keys($reachable))) {
					continue;
				}
				foreach ($transition->to as $place) {
					if (!isset($reachable[$place])) {
						$reachable[$place] = true;
						$changed = true;
					}
				}
			}
		}
		$unreachable = array_values(array_diff($this->places, array_keys($reachable)));
		if ([] !== $unreachable) {
			throw new \InvalidArgumentException(sprintf('Workflow graph contains unreachable place "%s".', $unreachable[0]));
		}

		$canReachFinal = array_fill_keys($this->finalPlaces, true);
		$changed = true;
		while ($changed) {
			$changed = false;
			foreach ($this->transitions as $transition) {
				if ([] !== array_diff($transition->to, array_keys($canReachFinal))) {
					continue;
				}
				foreach ($transition->from as $place) {
					if (!isset($canReachFinal[$place])) {
						$canReachFinal[$place] = true;
						$changed = true;
					}
				}
			}
		}
		$nonTerminating = array_values(array_diff($this->places, array_keys($canReachFinal)));
		if ([] !== $nonTerminating) {
			throw new \InvalidArgumentException(sprintf('Workflow graph place "%s" cannot reach a final place.', $nonTerminating[0]));
		}
	}
}
