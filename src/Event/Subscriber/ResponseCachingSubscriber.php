<?php

namespace iikiti\CMS\Event\Subscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class ResponseCachingSubscriber implements EventSubscriberInterface
{
	public const DEFAULT_CACHE_MAX_AGE = 3600;

	public function __construct(
		private Security $security
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.response' => 'onKernelResponse',
		];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		if (false == $event->isMainRequest() || $event->isPropagationStopped()) {
			return;
		}
	}
}
