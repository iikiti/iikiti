<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Event\WorkflowInstanceResumedEvent;
use iikiti\CMS\Message\ProcessDigitalAsset;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class DigitalAssetWorkflowResumeSubscriber implements EventSubscriberInterface
{
	public function __construct(private readonly MessageBusInterface $messageBus)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return [WorkflowInstanceResumedEvent::class => 'onWorkflowResumed'];
	}

	public function onWorkflowResumed(WorkflowInstanceResumedEvent $event): void
	{
		$instance = $event->instance;
		if ('digital_asset' !== $instance->getSubjectType() || null === $instance->getSubjectId()) {
			return;
		}

		$subjectId = ctype_digit($instance->getSubjectId()) ? (int) $instance->getSubjectId() : $instance->getSubjectId();
		$this->messageBus->dispatch(new ProcessDigitalAsset($subjectId));
	}
}
