<?php

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Service\CacheState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Disables database query caching for batch contexts.
 *
 * Caching is disabled when a request carries the '_disable_db_cache' attribute
 * or when its route name matches the configured batch route pattern. A
 * '_cache_strategy' request attribute can additionally override the active
 * caching strategy at runtime.
 */
class BatchCacheDisablerSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly CacheState $cacheState,
		private readonly string $batchRoutePattern = 'batch_',
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [KernelEvents::REQUEST => 'onKernelRequest'];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();

		if ($request->attributes->get('_disable_db_cache')) {
			$this->cacheState->disable();
		}

		$route = $request->attributes->get('_route');
		if ('' !== $this->batchRoutePattern &&
			is_string($route) &&
			str_starts_with($route, $this->batchRoutePattern)
		) {
			$this->cacheState->disable();
		}

		$strategyOverride = $request->attributes->get('_cache_strategy');
		if (is_string($strategyOverride) && '' !== $strategyOverride) {
			$this->cacheState->setOverrideStrategy($strategyOverride);
		}
	}
}
