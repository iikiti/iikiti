<?php

declare(strict_types=1);

namespace iikiti\CMS\Workflow\Policy;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\WorkflowEvents;

final class WorkflowGuardSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly WorkflowPolicyRegistry $policies,
		private readonly ?Security $security = null,
		private readonly ?SiteRegistry $siteRegistry = null,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [WorkflowEvents::GUARD => 'onGuard'];
	}

	public function onGuard(GuardEvent $event): void
	{
		$subject = $event->getSubject();
		$instance = $subject instanceof WorkflowInstance ? $subject : null;
		$interactive = $subject instanceof RuntimeWorkflowInterface ? $subject : null;
		if (null === $instance && null === $interactive) {
			return;
		}

		$policyKeys = [];
		$policyKey = $event->getMetadata('policyKey', null);
		if (!is_string($policyKey)) {
			if (null !== $instance || MfaStepProvider::WORKFLOW_NAME === $interactive->getName()) {
				$event->setBlocked(true, 'Workflow definition has no registered policy key.');

				return;
			}
		} else {
			$policyKeys[] = $policyKey;
		}
		$guardKeys = $event->getMetadata('guardKeys', $event->getTransition());
		if (is_array($guardKeys)) {
			foreach ($guardKeys as $key) {
				if (is_string($key)) {
					$policyKeys[] = $key;
				}
			}
		}
		if ([] === $policyKeys) {
			return;
		}
		$automaticMetadata = true === $event->getMetadata('automatic', $event->getTransition());
		$runtimeAutomatic = $instance?->isRuntimeAutomaticTransition() ?? $interactive?->isRuntimeAutomaticTransition() ?? false;
		if ($automaticMetadata && !$runtimeAutomatic) {
			$event->setBlocked(true, 'Automatic workflow transitions cannot be invoked directly.');

			return;
		}
		$targetPlaces = $event->getTransition()->getTos();
		$targetFinal = [] !== $targetPlaces;
		foreach ($targetPlaces as $place) {
			if (true !== $event->getMetadata('final', $place)) {
				$targetFinal = false;
				break;
			}
		}
		$workflowContext = $instance?->getContext() ?? $interactive?->getContext() ?? [];
		$actor = $instance?->getRuntimeActor();
		if (null === $actor && null !== $interactive) {
			$currentActor = $this->security?->getUser();
			$actor = $currentActor instanceof User ? $currentActor : null;
		}
		$site = $instance?->getSite();
		if (null === $site && null !== $interactive && null !== $this->siteRegistry) {
			try {
				$site = $this->siteRegistry::getCurrent();
			} catch (\Throwable) {
				$site = null;
			}
		}
		$fullyAuthenticated = null !== $instance ?
			null !== $actor :
			($this->security?->isGranted('IS_AUTHENTICATED_FULLY') ?? false);
		foreach (array_unique($policyKeys) as $key) {
			$policy = $this->policies->get($key);
			if (null === $policy || !$policy->isAllowed(new WorkflowPolicyContext(
				definitionVersion: $instance?->getDefinitionVersion(),
				instance: $instance,
				interactiveWorkflow: $interactive,
				site: $site instanceof Site ? $site : null,
				actor: $actor,
				fullyAuthenticated: $fullyAuthenticated,
				subject: $instance?->getRuntimeSubject(),
				transition: $event->getTransition(),
				automatic: $automaticMetadata,
				targetFinal: $targetFinal,
				workflowName: $event->getWorkflowName(),
				workflowContext: $workflowContext,
			))) {
				$event->setBlocked(true, 'Workflow transition is not permitted by its registered policy.');

				return;
			}
		}
	}
}
