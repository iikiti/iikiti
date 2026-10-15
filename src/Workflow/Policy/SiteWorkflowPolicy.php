<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowTask;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use Symfony\Component\Workflow\Transition;

final class SiteWorkflowPolicy implements WorkflowPolicyInterface
{
	public const KEY = 'core.site_workflow';

	public function __construct(
		private readonly WorkflowAccessPolicy $accessPolicy,
		private readonly EntityManagerInterface $entityManager,
	) {
	}

	public function getKey(): string
	{
		return self::KEY;
	}

	public function isAllowed(WorkflowPolicyContext $context): bool
	{
		if ($context->automatic) {
			return true;
		}
		$actor = $context->actor;
		if (null === $actor) {
			return false;
		}
		if (null !== $context->interactiveWorkflow) {
			$site = $context->site;
			if (null === $site) {
				return false;
			}
			if (MfaStepProvider::WORKFLOW_NAME === $context->workflowName) {
				$workflowUserId = $context->workflowContext['user_id'] ?? null;
				if ((string) $workflowUserId !== (string) $actor->getId()) {
					return false;
				}

				return $this->accessPolicy->isGlobalAdmin($actor) || $actor->registeredToSite($site->getId());
			}
			if (!$context->fullyAuthenticated) {
				return false;
			}

			return $this->accessPolicy->isGlobalAdmin($actor) || $actor->registeredToSite($site->getId());
		}

		$site = $context->instance?->getSite();
		if (null === $site) {
			if ($this->accessPolicy->isGlobalAdmin($actor)) {
				return true;
			}
			if (null !== $context->instance && null !== $context->transition) {
				return $this->actorOwnsGlobalTask($actor, $context->instance, $context->transition);
			}

			return false;
		}
		if ($this->accessPolicy->isGlobalAdmin($actor) || $this->accessPolicy->canManageSite($actor, $site)) {
			return true;
		}
		if (null === $context->transition) {
			return $actor->registeredToSite($site->getId());
		}

		return $this->actorOwnsActiveTask($actor, $site, $context->instance, $context->transition);
	}

	private function actorOwnsActiveTask(User $actor, Site $site, \iikiti\CMS\Entity\Workflow\WorkflowInstance $instance, Transition $transition): bool
	{
		$siteId = $site->getId();
		if (null === $siteId) {
			return false;
		}
		$sourcePlaces = $transition->getFroms();
		$tasks = $this->entityManager->getRepository(WorkflowTask::class)->findBy([
			'instance' => $instance,
			'site' => $site,
			'status' => ['open', 'claimed'],
		]);
		foreach ($tasks as $task) {
			if (!in_array($task->getPlaceKey(), $sourcePlaces, true)) {
				continue;
			}
			if ($task->getAssigneeUser()?->getId() === $actor->getId()) {
				if ('open' === $task->getStatus() && $actor->registeredToSite($siteId)) {
					return true;
				}
			}
			if (null !== $task->getAssigneeRole() &&
				$task->getClaimedBy()?->getId() === $actor->getId() &&
				'claimed' === $task->getStatus() &&
				$this->accessPolicy->canActAsSiteRole($actor, $site, (string) $task->getAssigneeRoleKey())) {
				return true;
			}
		}

		return false;
	}

	private function actorOwnsGlobalTask(User $actor, \iikiti\CMS\Entity\Workflow\WorkflowInstance $instance, Transition $transition): bool
	{
		$sourcePlaces = $transition->getFroms();
		foreach ($this->entityManager->getRepository(WorkflowTask::class)->findBy([
			'instance' => $instance,
			'site' => null,
			'status' => ['open', 'claimed'],
		]) as $task) {
			if (in_array($task->getPlaceKey(), $sourcePlaces, true) &&
				'open' === $task->getStatus() &&
				$task->getAssigneeUser()?->getId() === $actor->getId()) {
				return true;
			}
		}

		return false;
	}
}
