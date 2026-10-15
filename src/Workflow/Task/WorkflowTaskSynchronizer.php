<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Task;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Role;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use iikiti\CMS\Workflow\Graph\WorkflowGraph;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\CMS\Workflow\Runtime\WorkflowTransitionResult;

final class WorkflowTaskSynchronizer
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly WorkflowTaskAssignmentRegistry $assignmentResolvers,
		private readonly WorkflowAccessPolicy $accessPolicy,
	) {
	}

	public function hasAssignmentResolver(string $key): bool
	{
		return null !== $this->assignmentResolvers->get($key);
	}

	public function createInitialTasks(WorkflowInstance $instance, ?User $actor = null, ?object $subject = null): int
	{
		$graph = $this->graph($instance);
		$count = 0;
		foreach ($instance->getMarking() as $place => $tokens) {
			if (!$this->isHumanTask($graph, $place)) {
				continue;
			}
			for ($tokenIndex = 0; $tokenIndex < $tokens; ++$tokenIndex) {
				$this->createTask($instance, $graph, $place, $tokenIndex, $actor, $subject);
				++$count;
			}
		}

		return $count;
	}

	public function synchronize(WorkflowInstance $instance, WorkflowTransitionResult $result, ?User $actor = null, ?object $subject = null): int
	{
		$graph = $this->graph($instance);
		$tasks = $this->entityManager->getRepository(WorkflowTask::class)->findBy(['instance' => $instance]);
		$nextTokenIndexes = [];
		foreach ($tasks as $task) {
			$place = $task->getPlaceKey();
			$nextTokenIndexes[$place] = max($nextTokenIndexes[$place] ?? 0, $task->getTokenIndex() + 1);
		}

		$created = 0;
		foreach ($result->transitions as $transition) {
			foreach (array_count_values($transition->from) as $place => $tokenCount) {
				if (!$this->isHumanTask($graph, $place)) {
					continue;
				}
				$remaining = $tokenCount;
				foreach ($tasks as $task) {
					if ($task->getPlaceKey() !== $place || !in_array($task->getStatus(), ['open', 'claimed'], true)) {
						continue;
					}
					$task->complete();
					--$remaining;
					if (0 === $remaining) {
						break;
					}
				}
				if ($remaining > 0) {
					throw new \LogicException(sprintf('Workflow instance is missing %d active task(s) for place "%s".', $remaining, $place));
				}
			}

			foreach (array_count_values($transition->to) as $place => $tokenCount) {
				if (!$this->isHumanTask($graph, $place)) {
					continue;
				}
				for ($token = 0; $token < $tokenCount; ++$token) {
					$tokenIndex = $nextTokenIndexes[$place] ?? 0;
					$nextTokenIndexes[$place] = $tokenIndex + 1;
					$task = $this->createTask($instance, $graph, $place, $tokenIndex, $actor, $subject);
					$tasks[] = $task;
					++$created;
				}
			}
		}

		return $created;
	}

	private function graph(WorkflowInstance $instance): WorkflowGraph
	{
		return $this->graphCompiler->compile($instance->getDefinitionVersion()->getGraph());
	}

	private function isHumanTask(WorkflowGraph $graph, string $place): bool
	{
		return is_array($graph->placeMetadata($place)['humanTask'] ?? null);
	}

	private function createTask(
		WorkflowInstance $instance,
		WorkflowGraph $graph,
		string $place,
		int $tokenIndex,
		?User $actor,
		?object $subject,
	): WorkflowTask {
		$metadata = $graph->placeMetadata($place);
		$humanTask = $metadata['humanTask'];
		$context = new WorkflowTaskAssignmentContext($instance, $place, $metadata, $instance->getSite(), $actor, $subject);
		$assignmentKey = $humanTask['assignmentKey'] ?? null;
		if (is_string($assignmentKey)) {
			$resolver = $this->assignmentResolvers->get($assignmentKey);
			if (null === $resolver) {
				throw new \LogicException(sprintf('Workflow task assignment resolver "%s" is not registered.', $assignmentKey));
			}
			$assignment = $resolver->resolve($context);
		} elseif (isset($humanTask['userId'])) {
			$user = $this->entityManager->find(User::class, $humanTask['userId']);
			if (!$user instanceof User) {
				throw new \InvalidArgumentException(sprintf('Workflow task user "%s" does not exist.', (string) $humanTask['userId']));
			}
			$assignment = new WorkflowTaskAssignment(user: $user);
		} else {
			$roleKey = $humanTask['roleKey'] ?? null;
			$role = is_string($roleKey) ? $this->entityManager->getRepository(Role::class)->findOneBy(['value' => $roleKey]) : null;
			if (!$role instanceof Role) {
				throw new \InvalidArgumentException(sprintf('Workflow task role "%s" does not exist.', is_string($roleKey) ? $roleKey : ''));
			}
			$assignment = new WorkflowTaskAssignment(role: $role);
		}
		if (null !== $assignment->user && null !== $instance->getSite() && !$this->accessPolicy->isGlobalAdmin($assignment->user) && !$assignment->user->registeredToSite($instance->getSite()->getId())) {
			throw new \InvalidArgumentException('Workflow task user is not registered to the workflow site.');
		}

		$label = $humanTask['label'] ?? $metadata['label'] ?? $place;
		if (!is_string($label)) {
			throw new \LogicException(sprintf('Workflow task label for place "%s" is invalid.', $place));
		}
		$task = new WorkflowTask(
			$instance,
			$place,
			$tokenIndex,
			$label,
			$instance->getSite(),
			$assignment->user,
			$assignment->role,
		);
		$this->entityManager->persist($task);

		return $task;
	}
}
