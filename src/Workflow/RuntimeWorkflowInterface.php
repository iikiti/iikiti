<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow;

use iikiti\CMS\Workflow\Graph\WorkflowGraph;

interface RuntimeWorkflowInterface extends WorkflowInterface
{
	/** @return list<string> */
	public function getActivePlaceIds(): array;

	/** @return list<string> */
	public function getEnabledTransitionNames(): array;

	/** @return list<string> */
	public function getEnabledTransitionNamesForPlace(string $place): array;

	public function getStepById(string $stepId): ?WorkflowStepInterface;

	/** @return array<string,int> */
	public function getWorkflowMarking(): array;

	/** @return list<string> */
	public function getFinalPlaceIds(): array;

	public function isRuntimeAutomaticTransition(): bool;

	/** @param array<string,mixed> $marking */
	public function restoreMarking(array $marking): void;

	public function restoreLegacyPosition(?string $stepId, bool $complete): void;

	public function setGraph(WorkflowGraph $graph): void;

	/** @param array<string,mixed> $data */
	public function submitCurrentStepWithTransition(array $data, ?string $transitionName): bool;
}
