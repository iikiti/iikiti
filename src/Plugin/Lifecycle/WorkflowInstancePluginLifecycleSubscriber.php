<?php

declare(strict_types=1);

namespace iikiti\CMS\Plugin\Lifecycle;

use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Workflow\Runtime\WorkflowInstanceManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class WorkflowInstancePluginLifecycleSubscriber implements EventSubscriberInterface
{
	public function __construct(
		private readonly WorkflowInstanceManager $instances,
		private readonly SiteRepository $sites,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			PluginEvents::DEACTIVATE => 'onDeactivate',
			PluginEvents::ACTIVATE => 'onActivate',
		];
	}

	public function onDeactivate(PluginEvent $event): void
	{
		$site = $this->siteForEvent($event);
		$this->instances->pausePluginInstances(
			$event->getSlug(),
			$site,
			null === $site ? 'Plugin disabled.' : sprintf('Plugin disabled for site %s.', (string) $site->getId()),
		);
	}

	public function onActivate(PluginEvent $event): void
	{
		$site = $this->siteForEvent($event);
		$this->instances->resumePluginInstances($event->getSlug(), $site);
	}

	private function siteForEvent(PluginEvent $event): ?Site
	{
		$siteId = $event->getSiteId();
		if (null === $siteId) {
			return null;
		}
		$site = $this->sites->find($siteId);
		if (!$site instanceof Site) {
			throw new \LogicException(sprintf('Plugin lifecycle event references unknown site "%s".', $siteId));
		}

		return $site;
	}
}
