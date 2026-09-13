<?php

namespace iikiti\CMS\Event\Subscriber;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Handles setting a cache on response headers for caching.
 */
class ResponseCachingSubscriber implements EventSubscriberInterface
{
	public const DEFAULT_CACHE_MAX_AGE = 3600;

	/**
	 * Identifies subscribed events.
	 */
	#[Override]
	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.response' => 'onKernelResponse',
		];
	}

	/**
	 * Fired on kernel response.
	 * Handles adding caching values when necessary.
	 */
	public function onKernelResponse(ResponseEvent $event): void
	{
		if ($event->isPropagationStopped()) {
			return;
		}

		if (false == $event->isMainRequest()) {
			return;
		}
	}
}
