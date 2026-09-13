<?php

namespace iikiti\CMS\Cache;

use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\CacheState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reads a caching strategy name from the current site's configuration and
 * applies it as a runtime override.
 *
 * This allows an administrator to switch caching strategies through a database
 * configuration value (e.g. stored as a property on the current site) without
 * changing configuration files or the environment.
 */
class DatabaseConfigStrategyResolver implements EventSubscriberInterface
{
	public function __construct(
		private readonly CacheState $cacheState,
		private readonly string $configKey = 'cache_strategy',
	) {
	}

	public static function getSubscribedEvents(): array
	{
		// Runs after SiteRegistry has been populated by the Initializer.
		return [KernelEvents::REQUEST => ['onKernelRequest', -100]];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest() || !SiteRegistry::hasCurrent()) {
			return;
		}

		$site = SiteRegistry::getCurrent();

		$strategy = $site->getConfiguration()->get($this->configKey);
		if (is_string($strategy) && '' !== $strategy) {
			$this->cacheState->setOverrideStrategy($strategy);
		}
	}
}
