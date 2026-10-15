<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Runtime;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;
use iikiti\CMS\Entity\Workflow\WorkflowDefinitionVersion;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Entity\Workflow\WorkflowInstanceEvent;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use iikiti\CMS\Event\WorkflowInstanceResumedEvent;
use iikiti\CMS\Service\DigitalAssetProcessorRegistry;
use iikiti\CMS\Workflow\Action\WorkflowActionContext;
use iikiti\CMS\Workflow\Action\WorkflowTransitionActionRegistry;
use iikiti\CMS\Workflow\Graph\WorkflowGraphCompiler;
use iikiti\CMS\Workflow\Policy\WorkflowAccessPolicy;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyContext;
use iikiti\CMS\Workflow\Policy\WorkflowPolicyRegistry;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use iikiti\CMS\Workflow\Subject\WorkflowSubjectResolverRegistry;
use iikiti\CMS\Workflow\Task\WorkflowTaskSynchronizer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class WorkflowInstanceManager implements WorkflowInstanceManagerInterface
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly WorkflowInstanceRuntime $runtime,
		private readonly WorkflowGraphCompiler $graphCompiler,
		private readonly WorkflowTaskSynchronizer $tasks,
		private readonly WorkflowTransitionActionRegistry $actions,
		private readonly WorkflowPolicyRegistry $policies,
		private readonly WorkflowSubjectResolverRegistry $subjects,
		private readonly WorkflowAccessPolicy $accessPolicy,
		private readonly DigitalAssetProcessorRegistry $assetProcessors,
		private readonly EventDispatcherInterface $eventDispatcher,
	) {
	}

	public function findByIdempotencyKey(string $key): ?WorkflowInstance
	{
		return $this->entityManager->getRepository(WorkflowInstance::class)->findByIdempotencyKey($key);
	}

	public function find(int|string $id): ?WorkflowInstance
	{
		$instance = $this->entityManager->find(WorkflowInstance::class, $id);

		return $instance instanceof WorkflowInstance ? $instance : null;
	}

	/** @return list<WorkflowInstance> */
	public function findBySubject(string $type, int|string $id): array
	{
		return $this->entityManager->getRepository(WorkflowInstance::class)->findBySubject($type, $id);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function start(
		WorkflowDefinitionVersion $version,
		?Site $site,
		?string $subjectType,
		int|string|null $subjectId,
		array $context,
		?User $actor,
		?string $idempotencyKey = null,
	): WorkflowInstance {
		$definition = $version->getDefinition();
		$this->assertStartableDefinition($definition, $version, $site);
		if (strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 131072) {
			throw new \InvalidArgumentException('Workflow context exceeds the 128 KiB limit.');
		}
		if (null !== $idempotencyKey) {
			$existing = $this->entityManager->getRepository(WorkflowInstance::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
			if ($existing instanceof WorkflowInstance) {
				if ($existing->getDefinitionVersion() !== $version || $existing->getSite() !== $site || $existing->getSubjectType() !== $subjectType || $existing->getSubjectId() !== (null === $subjectId ? null : (string) $subjectId)) {
					throw new \LogicException('Workflow idempotency key is already used for a different start request.');
				}

				return $existing;
			}
		}

		$subject = $this->resolveSubject($subjectType, $subjectId, $site);
		$instance = new WorkflowInstance($version, $site, $subjectType, $subjectId, $context, $idempotencyKey);
		$graph = $this->graphCompiler->compile($version->getGraph());
		$this->assertPolicyAllows($graph->workflowMetadata['policyKey'] ?? null, new WorkflowPolicyContext(
			definitionVersion: $version,
			instance: $instance,
			site: $site,
			actor: $actor,
			fullyAuthenticated: null !== $actor,
			subject: $subject,
			workflowName: $definition->getKey(),
			workflowContext: $context,
		));
		$missingExtension = $this->extensionsAvailable($graph);
		if (null !== $missingExtension) {
			throw new \LogicException(sprintf('Workflow extension "%s" is unavailable.', $missingExtension));
		}

		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		$instance->setRuntimeContext($actor, $subject);
		try {
			$this->entityManager->persist($instance);
			$this->tasks->createInitialTasks($instance, $actor, $subject);
			$this->entityManager->flush();

			$result = $this->runtime->settleInitialMarking($instance, actor: $actor, subject: $subject);
			$this->applyTransitionBookkeeping($instance, $result, null);
			$this->tasks->synchronize($instance, $result, $actor, $subject);
			$this->executeActions($version, $instance, $result, $actor, $subject);
			$this->recordEvent($instance, 'started', $actor, null, [], $result->toMarking);
			$this->recordTransitions($instance, $result, $actor);
			$this->entityManager->flush();
			$connection->commit();

			return $instance;
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		} finally {
			$instance->setRuntimeContext(null, null);
		}
	}

	/** @return list<string> */
	public function getEnabledTransitions(WorkflowInstance $instance, ?User $actor): array
	{
		if (null === $actor || !$this->canAccessInstance($actor, $instance) || !$this->pluginAvailableForInstance($instance) || !$this->resumePausedPluginInstance($instance)) {
			return [];
		}
		$version = $instance->getDefinitionVersion();
		$graph = $this->graphCompiler->compile($version->getGraph());
		if (null === $this->extensionsAvailable($graph)) {
			return [];
		}
		$subject = $this->resolveSubject($instance->getSubjectType(), $instance->getSubjectId(), $instance->getSite());
		$transitions = $this->runtime->getEnabledTransitions($instance, $actor, $subject);

		return array_values(array_unique(array_map(static fn ($transition): string => $transition->getName(), $transitions)));
	}

	/** @return list<array{name:string,metadata:array<string,mixed>}> */
	public function getEnabledTransitionMetadata(WorkflowInstance $instance, ?User $actor, ?object $subject = null): array
	{
		if ('active' !== $instance->getExecutionState() || (null !== $actor && !$this->canAccessInstance($actor, $instance)) || !$this->pluginAvailableForInstance($instance) || !$this->resumePausedPluginInstance($instance)) {
			return [];
		}
		$resolvedSubject = $subject;
		if (null === $resolvedSubject && null !== $instance->getSubjectType()) {
			$resolvedSubject = $this->resolveSubject($instance->getSubjectType(), $instance->getSubjectId(), $instance->getSite());
		}
		$transitions = $this->runtime->getEnabledTransitions($instance, $actor, $resolvedSubject);
		$metadata = [];
		foreach ($transitions as $transition) {
			$metadata[] = ['name' => $transition->getName(), 'metadata' => $this->runtime->getTransitionMetadata($instance, $transition)];
		}

		return $metadata;
	}

	/** @return array<string,list<string>> */
	public function getEnabledTransitionsByPlace(WorkflowInstance $instance, User $actor): array
	{
		if ('active' !== $instance->getExecutionState() || !$this->canAccessInstance($actor, $instance) || !$this->pluginAvailableForInstance($instance) || !$this->resumePausedPluginInstance($instance)) {
			return [];
		}
		$subject = $this->resolveSubject($instance->getSubjectType(), $instance->getSubjectId(), $instance->getSite());
		$transitions = $this->runtime->getEnabledTransitions($instance, $actor, $subject);
		$byPlace = [];
		foreach ($transitions as $transition) {
			foreach ($transition->getFroms() as $place) {
				$byPlace[$place][] = $transition->getName();
			}
		}

		return $byPlace;
	}

	public function getInstanceForUser(int|string $id, User $actor): WorkflowInstance
	{
		$instance = $this->entityManager->find(WorkflowInstance::class, $id);
		if (!$instance instanceof WorkflowInstance || !$this->canAccessInstance($actor, $instance)) {
			throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Workflow instance not found.');
		}

		return $instance;
	}

	/** @return list<WorkflowTask> */
	public function getInbox(User $actor, ?Site $site, int $limit = 100): array
	{
		if (null === $site && !$this->accessPolicy->isGlobalAdmin($actor)) {
			throw new AccessDeniedHttpException('A site context is required for workflow tasks.');
		}
		if (null !== $site && !$this->accessPolicy->isGlobalAdmin($actor) && !$actor->registeredToSite($site->getId())) {
			throw new AccessDeniedHttpException('You cannot access workflow tasks for this site.');
		}
		$repository = $this->entityManager->getRepository(WorkflowTask::class);
		$criteria = ['status' => ['open', 'claimed']];
		if (null !== $site) {
			$siteTasks = $repository->findBy($criteria + ['site' => $site], ['createdAt' => 'ASC'], max(1, min(500, $limit)));
			$globalTasks = $repository->findBy($criteria + ['site' => null], ['createdAt' => 'ASC'], max(1, min(500, $limit)));
			$tasks = [...$siteTasks, ...array_filter($globalTasks, static fn (WorkflowTask $task): bool => $task->getAssigneeUser()?->getId() === $actor->getId() || $task->getClaimedBy()?->getId() === $actor->getId()
			)];
		} else {
			$tasks = $repository->findBy($criteria, ['createdAt' => 'ASC'], max(1, min(500, $limit)));
		}
		$visible = [];
		foreach ($tasks as $task) {
			if ($this->canAccessInstance($actor, $task->getInstance())) {
				$visible[] = $task;
			}
		}

		return $visible;
	}

	/** @return list<WorkflowTask> */
	public function getTasksForInstance(WorkflowInstance $instance, User $actor): array
	{
		if (!$this->canAccessInstance($actor, $instance)) {
			throw new AccessDeniedHttpException('You cannot access this workflow instance.');
		}

		return $this->entityManager->getRepository(WorkflowTask::class)->findBy(['instance' => $instance], ['createdAt' => 'ASC']);
	}

	/**
	 * Returns null when a missing plugin extension pauses the instance; otherwise
	 * returns the applied marking/transition result.
	 *
	 * @param array<string,mixed> $context
	 */
	public function applyTransition(WorkflowInstance $instance, string $transitionName, ?User $actor, array $context = [], ?string $errorMessage = null): ?WorkflowTransitionResult
	{
		if (null !== $actor && !$this->canAccessInstance($actor, $instance)) {
			throw new AccessDeniedHttpException('You cannot access this workflow instance.');
		}
		if (strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 131072) {
			throw new \InvalidArgumentException('Workflow transition context exceeds the 128 KiB limit.');
		}
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$this->entityManager->lock($instance, LockMode::OPTIMISTIC, $instance->getLockVersion());
			if (!$this->pluginAvailableForInstance($instance)) {
				if ('active' === $instance->getExecutionState()) {
					$marking = $instance->getMarking();
					$instance->pause('Plugin is disabled for this workflow site.', 'plugin');
					$this->recordEvent($instance, 'paused', $actor, null, $marking, $marking, ['plugin' => $instance->getDefinitionVersion()->getDefinition()->getOwnerKey()]);
					$this->entityManager->flush();
				}
				$connection->commit();

				return null;
			}
			if (!$this->resumePausedPluginInstance($instance, false)) {
				$connection->commit();

				return null;
			}
			$version = $instance->getDefinitionVersion();
			$graph = $this->graphCompiler->compile($version->getGraph());
			$missingExtension = $this->extensionsAvailable($graph);
			if (null !== $missingExtension) {
				$instance->pause(sprintf('Workflow extension "%s" is unavailable.', $missingExtension), 'plugin');
				$this->recordEvent($instance, 'paused', $actor, null, $instance->getMarking(), $instance->getMarking(), ['extension' => $missingExtension]);
				$this->entityManager->flush();
				$connection->commit();

				return null;
			}

			$subject = $this->resolveSubject($instance->getSubjectType(), $instance->getSubjectId(), $instance->getSite());
			$instance->setContext(array_merge($instance->getContext(), $context));
			$instance->setRuntimeContext($actor, $subject);
			$result = $this->runtime->applyTransition($instance, $transitionName, $context, $actor, $subject);
			$this->applyTransitionBookkeeping($instance, $result, $errorMessage);
			$this->tasks->synchronize($instance, $result, $actor, $subject);
			$this->executeActions($version, $instance, $result, $actor, $subject);
			$this->recordTransitions($instance, $result, $actor);
			$this->entityManager->flush();
			$connection->commit();

			return $result;
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		} finally {
			$instance->setRuntimeContext(null, null);
		}
	}

	public function claimTask(WorkflowTask $task, User $actor): void
	{
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$this->entityManager->lock($task, LockMode::OPTIMISTIC, $task->getLockVersion());
			$site = $task->getSite();
			$roleKey = $task->getAssigneeRoleKey();
			if ('active' !== $task->getInstance()->getExecutionState() || null === $site || null === $roleKey || (!$this->accessPolicy->isGlobalAdmin($actor) && !$this->accessPolicy->canActAsSiteRole($actor, $site, $roleKey))) {
				throw new AccessDeniedHttpException('You cannot claim this workflow task.');
			}
			$task->claim($actor);
			$this->recordEvent($task->getInstance(), 'task_claimed', $actor, null, null, null, ['taskId' => $task->getId(), 'place' => $task->getPlaceKey()]);
			$this->entityManager->flush();
			$connection->commit();
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
	}

	public function releaseTask(WorkflowTask $task, User $actor): void
	{
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$this->entityManager->lock($task, LockMode::OPTIMISTIC, $task->getLockVersion());
			$instance = $task->getInstance();
			if ('active' !== $instance->getExecutionState() || $task->getClaimedBy()?->getId() !== $actor->getId()) {
				throw new AccessDeniedHttpException('Only the current claimant may release an active workflow task.');
			}
			$task->unclaim($actor);
			$this->recordEvent($instance, 'task_released', $actor, null, null, null, ['taskId' => $task->getId(), 'place' => $task->getPlaceKey()]);
			$this->entityManager->flush();
			$connection->commit();
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
	}

	public function pausePluginInstances(string $pluginOwnerKey, ?Site $site, string $reason): int
	{
		$connection = $this->entityManager->getConnection();
		$connection->beginTransaction();
		try {
			$instances = $this->entityManager->getRepository(WorkflowInstance::class)->findBy(['executionState' => 'active']);
			$paused = 0;
			foreach ($instances as $instance) {
				if ('plugin' !== $instance->getDefinitionVersion()->getDefinition()->getOwnerType() || $instance->getDefinitionVersion()->getDefinition()->getOwnerKey() !== $pluginOwnerKey) {
					continue;
				}
				if ($this->isFinalMarking($instance)) {
					continue;
				}
				if (null !== $site && $instance->getSite()?->getId() !== $site->getId()) {
					continue;
				}
				$this->entityManager->lock($instance, LockMode::OPTIMISTIC, $instance->getLockVersion());
				$from = $instance->getMarking();
				$instance->pause($reason, 'plugin');
				$this->recordEvent($instance, 'paused', null, null, $from, $from, ['plugin' => $pluginOwnerKey]);
				++$paused;
			}
			$this->entityManager->flush();
			$connection->commit();

			return $paused;
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
	}

	public function resumePluginInstances(string $pluginOwnerKey, ?Site $site): int
	{
		$connection = $this->entityManager->getConnection();
		$resumedInstances = [];
		$resumed = 0;
		$connection->beginTransaction();
		try {
			$instances = $this->entityManager->getRepository(WorkflowInstance::class)->findBy(['executionState' => 'paused']);
			foreach ($instances as $instance) {
				if ('plugin' !== $instance->getDefinitionVersion()->getDefinition()->getOwnerType() || $instance->getDefinitionVersion()->getDefinition()->getOwnerKey() !== $pluginOwnerKey) {
					continue;
				}
				if ('plugin' !== $instance->getPauseType() || (null !== $site && $instance->getSite()?->getId() !== $site->getId())) {
					continue;
				}
				$graph = $this->graphCompiler->compile($instance->getDefinitionVersion()->getGraph());
				if (!$this->pluginAvailableForInstance($instance) || null !== $this->extensionsAvailable($graph)) {
					continue;
				}
				$this->entityManager->lock($instance, LockMode::OPTIMISTIC, $instance->getLockVersion());
				$instance->resume();
				$this->recordEvent($instance, 'resumed', null, null, $instance->getMarking(), $instance->getMarking(), ['plugin' => $pluginOwnerKey]);
				$resumedInstances[] = $instance;
				++$resumed;
			}
			$this->entityManager->flush();
			$connection->commit();
		} catch (\Throwable $exception) {
			$connection->rollBack();
			$this->entityManager->clear();

			throw $exception;
		}
		foreach ($resumedInstances as $instance) {
			$this->eventDispatcher->dispatch(new WorkflowInstanceResumedEvent($instance));
		}

		return $resumed;
	}

	private function assertStartableDefinition(WorkflowDefinition $definition, WorkflowDefinitionVersion $version, ?Site $site): void
	{
		$workflowGraph = $this->graphCompiler->compile($version->getGraph());
		if ('session' === ($workflowGraph->workflowMetadata['mode'] ?? null) || MfaStepProvider::WORKFLOW_NAME === $definition->getKey()) {
			throw new \LogicException('Session-backed workflows cannot be started as durable workflow instances.');
		}
		if (!$definition->isEnabled() || $version->getVersion() !== $definition->getCurrentVersion()) {
			throw new \LogicException('Only the enabled current workflow definition version can start new instances.');
		}
		if ('plugin' === $definition->getOwnerType() &&
			(null === $site || !$site->getConfiguration()->isPluginActive($definition->getOwnerKey()))) {
			throw new AccessDeniedHttpException('The workflow plugin is not active for this site.');
		}
		if (null === $site) {
			if ('site' === $definition->getOwnerType()) {
				throw new AccessDeniedHttpException('A site-owned workflow requires a site context.');
			}

			return;
		}
		if (!$definition->hasSite($site)) {
			throw new AccessDeniedHttpException('This workflow is not assigned to the requested site.');
		}
		if ('site' === $definition->getOwnerType() && $definition->getOwnerKey() !== (string) $site->getId()) {
			throw new AccessDeniedHttpException('This site-owned workflow cannot run in another site.');
		}
	}

	private function resolveSubject(?string $subjectType, ?string $subjectId, ?Site $site): ?object
	{
		if (null === $subjectType) {
			return null;
		}
		if (null === $subjectId || null === ($resolver = $this->subjects->get($subjectType))) {
			throw new \InvalidArgumentException(sprintf('Workflow subject type "%s" is not registered.', $subjectType));
		}
		$subject = $resolver->resolve($subjectId);
		if (null === $subject) {
			throw new \InvalidArgumentException(sprintf('Workflow subject "%s:%s" does not exist.', $subjectType, $subjectId));
		}
		if (null !== $site && !$resolver->isAllowedInSite($subject, $site)) {
			throw new AccessDeniedHttpException('Workflow subject is not accessible in the requested site.');
		}

		return $subject;
	}

	private function canAccessInstance(User $actor, WorkflowInstance $instance): bool
	{
		$site = $instance->getSite();
		if (null === $site) {
			if ($this->accessPolicy->isGlobalAdmin($actor)) {
				return true;
			}
			foreach ($this->entityManager->getRepository(WorkflowTask::class)->findBy(['instance' => $instance, 'site' => null]) as $task) {
				if ($task->getAssigneeUser()?->getId() === $actor->getId() || $task->getClaimedBy()?->getId() === $actor->getId()) {
					return true;
				}
			}

			return false;
		}
		if ($this->accessPolicy->isGlobalAdmin($actor) || $this->accessPolicy->canManageSite($actor, $site)) {
			return true;
		}
		foreach ($this->entityManager->getRepository(WorkflowTask::class)->findBy(['instance' => $instance]) as $task) {
			if ($task->getAssigneeUser()?->getId() === $actor->getId() &&
				$actor->registeredToSite($site->getId()) &&
				'cancelled' !== $task->getStatus()) {
				return true;
			}
			if ($task->getClaimedBy()?->getId() === $actor->getId() && $actor->registeredToSite($site->getId())) {
				return true;
			}
			$roleKey = $task->getAssigneeRoleKey();
			if ('open' === $task->getStatus() && null !== $roleKey && $this->accessPolicy->canActAsSiteRole($actor, $site, $roleKey)) {
				return true;
			}
		}

		return false;
	}

	private function resumePausedPluginInstance(WorkflowInstance $instance, bool $manageTransaction = true): bool
	{
		if ('active' === $instance->getExecutionState()) {
			return true;
		}
		if ('paused' !== $instance->getExecutionState() || 'plugin' !== $instance->getPauseType() || !$this->pluginAvailableForInstance($instance)) {
			return false;
		}
		$graph = $this->graphCompiler->compile($instance->getDefinitionVersion()->getGraph());
		if (null !== $this->extensionsAvailable($graph)) {
			return false;
		}
		if ($manageTransaction) {
			$connection = $this->entityManager->getConnection();
			$connection->beginTransaction();
		}
		try {
			$this->entityManager->lock($instance, LockMode::OPTIMISTIC, $instance->getLockVersion());
			$marking = $instance->getMarking();
			$instance->resume();
			$this->recordEvent($instance, 'resumed', null, null, $marking, $marking, ['plugin' => $instance->getDefinitionVersion()->getDefinition()->getOwnerKey()]);
			$this->entityManager->flush();
			if ($manageTransaction) {
				$connection->commit();
			}
		} catch (\Throwable $exception) {
			if ($manageTransaction) {
				$connection->rollBack();
				$this->entityManager->clear();
			}
			throw $exception;
		}
		if ($manageTransaction) {
			$this->eventDispatcher->dispatch(new WorkflowInstanceResumedEvent($instance));
		}

		return true;
	}

	private function pluginAvailableForInstance(WorkflowInstance $instance): bool
	{
		$definition = $instance->getDefinitionVersion()->getDefinition();
		if ('plugin' !== $definition->getOwnerType()) {
			return true;
		}
		$site = $instance->getSite();

		return null !== $site &&
			$site->getConfiguration()->isPluginActive($definition->getOwnerKey());
	}

	private function isFinalMarking(WorkflowInstance $instance): bool
	{
		$graph = $this->graphCompiler->compile($instance->getDefinitionVersion()->getGraph());
		$marking = $instance->getMarking();
		if ([] === $marking) {
			return false;
		}
		$finalPlaces = array_fill_keys($graph->finalPlaces, true);
		foreach ($marking as $place => $tokens) {
			if ($tokens < 1 || !isset($finalPlaces[$place])) {
				return false;
			}
		}

		return true;
	}

	private function assertPolicyAllows(mixed $policyKey, WorkflowPolicyContext $context): void
	{
		if (!is_string($policyKey) || null === ($policy = $this->policies->get($policyKey)) || !$policy->isAllowed($context)) {
			throw new AccessDeniedHttpException('Workflow policy does not allow this operation.');
		}
	}

	private function extensionsAvailable(\iikiti\CMS\Workflow\Graph\WorkflowGraph $graph): ?string
	{
		$assetProcessor = $graph->workflowMetadata['assetProcessor'] ?? null;
		if (is_array($assetProcessor) && is_string($assetProcessor['processorKey'] ?? null) && !$this->assetProcessors->has($assetProcessor['processorKey'])) {
			return $assetProcessor['processorKey'];
		}
		$policyKey = $graph->workflowMetadata['policyKey'] ?? null;
		if (!is_string($policyKey) || null === $this->policies->get($policyKey)) {
			return is_string($policyKey) ? $policyKey : 'workflow-policy';
		}
		foreach ($graph->transitions as $transition) {
			$actionKey = $transition->metadata['actionKey'] ?? null;
			if (is_string($actionKey) && null === $this->actions->get($actionKey)) {
				return $actionKey;
			}
			foreach ($transition->metadata['guardKeys'] ?? [] as $guardKey) {
				if (is_string($guardKey) && null === $this->policies->get($guardKey)) {
					return $guardKey;
				}
			}
		}
		foreach ($graph->placesMetadata as $metadata) {
			$assignmentKey = $metadata['humanTask']['assignmentKey'] ?? null;
			if (is_string($assignmentKey) && !$this->tasks->hasAssignmentResolver($assignmentKey)) {
				return $assignmentKey;
			}
		}

		return null;
	}

	private function executeActions(WorkflowDefinitionVersion $version, WorkflowInstance $instance, WorkflowTransitionResult $result, ?User $actor, ?object $subject): void
	{
		foreach ($result->transitions as $transition) {
			$actionKey = $transition->metadata['actionKey'] ?? null;
			if (!is_string($actionKey)) {
				continue;
			}
			$action = $this->actions->get($actionKey);
			if (null === $action) {
				throw new \LogicException(sprintf('Workflow action "%s" is unavailable.', $actionKey));
			}
			$action->execute(new WorkflowActionContext($version, $instance, $transition, $actor, $subject));
		}
	}

	private function applyTransitionBookkeeping(WorkflowInstance $instance, WorkflowTransitionResult $result, ?string $errorMessage): void
	{
		foreach ($result->transitions as $transition) {
			$assetOperation = $transition->metadata['assetOperation'] ?? null;
			if (true === ($transition->metadata['incrementAttempts'] ?? false) || 'start' === $assetOperation) {
				$instance->recordAttempt();
			}
			if (true === ($transition->metadata['clearLastError'] ?? false)) {
				$instance->setLastError(null);
			}
			if (true === ($transition->metadata['setLastError'] ?? false) || 'fail' === $assetOperation) {
				$instance->setLastError(mb_substr($errorMessage ?? 'Workflow transition failed.', 0, 2000));
			}
		}
	}

	private function recordTransitions(WorkflowInstance $instance, WorkflowTransitionResult $result, ?User $actor): void
	{
		foreach ($result->transitions as $transition) {
			$this->recordEvent(
				$instance,
				true === ($transition->metadata['automatic'] ?? false) ? 'automatic_transition' : 'transition',
				$actor,
				$transition->name,
				$transition->fromMarking,
				$transition->toMarking,
				[],
			);
		}
	}

	/** @param array<string,int>|null $fromMarking
	 * @param array<string,int>|null $toMarking
	 * @param array<string,mixed>    $details
	 */
	private function recordEvent(
		WorkflowInstance $instance,
		string $eventType,
		?User $actor,
		?string $transitionName,
		?array $fromMarking,
		?array $toMarking,
		array $details = [],
	): void {
		$this->entityManager->persist(new WorkflowInstanceEvent($instance, $eventType, $actor, $transitionName, $fromMarking, $toMarking, $details));
	}
}
