<?php

declare(strict_types=1);

namespace iikiti\CMS\Authentication\Mfa;

use iikiti\CMS\Workflow\RuntimeWorkflowInterface;
use iikiti\CMS\Workflow\StepProvider\MfaStepProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\WorkflowEvents;

final class MfaSessionFactorGuardSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [WorkflowEvents::GUARD => 'onGuard'];
	}

	public function onGuard(GuardEvent $event): void
	{
		$workflow = $event->getSubject();
		if (!$workflow instanceof RuntimeWorkflowInterface || MfaStepProvider::WORKFLOW_NAME !== $workflow->getName()) {
			return;
		}

		$context = $workflow->getContext();
		$enabledFactors = $context[MfaStepProvider::CONTEXT_METHODS] ?? [];
		if (!is_array($enabledFactors)) {
			$event->setBlocked(true, 'MFA factor policy is unavailable.');

			return;
		}
		foreach ($event->getTransition()->getTos() as $targetPlace) {
			$factorKey = $event->getMetadata('mfaFactorKey', $targetPlace);
			if (is_string($factorKey) && !in_array($factorKey, $enabledFactors, true)) {
				$event->setBlocked(true, 'Transition enters an MFA factor that is not enabled by the current policy.');

				return;
			}
		}
	}
}
