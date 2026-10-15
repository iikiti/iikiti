<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;

interface WorkflowInstanceManagerInterface
{
	public function findByIdempotencyKey(string $key): ?WorkflowInstance;

	public function find(int|string $id): ?WorkflowInstance;

	/** @return list<WorkflowInstance> */
	public function findBySubject(string $type, int|string $id): array;

	/** @return list<array{name:string,metadata:array<string,mixed>}> */
	public function getEnabledTransitionMetadata(WorkflowInstance $instance, ?User $actor, ?object $subject = null): array;

	/** @param array<string,mixed> $context */
	public function start(
		WorkflowDefinitionVersion $version,
		?Site $site,
		?string $subjectType,
		int|string|null $subjectId,
		array $context,
		?User $actor,
		?string $idempotencyKey = null,
	): WorkflowInstance;

	/** @param array<string,mixed> $context */
	public function applyTransition(
		WorkflowInstance $instance,
		string $transitionName,
		?User $actor,
		array $context = [],
		?string $errorMessage = null,
	): ?WorkflowTransitionResult;
}
