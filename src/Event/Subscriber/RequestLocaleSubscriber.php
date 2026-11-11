<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Subscriber;

use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Service\LocaleResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/** Applies the authenticated user's and current site's locale to each request. */
final class RequestLocaleSubscriber implements EventSubscriberInterface
{
	public const string REQUEST_CONTEXT_ATTRIBUTE = '_iikiti_locale';

	public function __construct(
		private readonly Security $security,
		private readonly ApplicationRegistry $applications,
		private readonly LocaleResolver $locales,
		#[Autowire(service: 'translator')]
		private readonly LocaleAwareInterface $translator,
	) {
	}

	public static function getSubscribedEvents(): array
	{
		// Run after routing, site initialization, and the firewall have populated
		// the request's site and authenticated user context.
		return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		$request = $event->getRequest();
		$site = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		$application = $site instanceof Site && $this->applications->hasCurrentApplication() ?
			$this->applications->getCurrent() :
			null;
		$user = $this->security->getUser();
		$preferredLocale = $user instanceof User ? $user->getPreferredLocale() : null;
		$siteLocale = $site instanceof Site ? $site->getDefaultLocale() : null;
		$applicationLocale = $application instanceof Application ? $application->getDefaultLocale() : null;

		$uiLocale = $this->locales->resolveUiLocale($preferredLocale, $siteLocale, $applicationLocale);
		$presentationLocale = $this->locales->resolvePresentationLocale($preferredLocale, null, $siteLocale, $applicationLocale);
		$request->setLocale($uiLocale->toIcuLocale());
		$this->translator->setLocale($uiLocale->toIcuLocale());
		$request->attributes->set(self::REQUEST_CONTEXT_ATTRIBUTE, [
			'ui' => $uiLocale->getTag(),
			'presentation' => $presentationLocale->getTag(),
			'userPreferred' => $preferredLocale?->getTag(),
			'timeZone' => $user instanceof User ? $user->getTimeZone()?->getId() : null,
		]);
	}
}
