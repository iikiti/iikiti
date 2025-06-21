<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Runs early to initialize the application.
 */
class Initializer implements EventSubscriberInterface
{
	protected static bool $hasInitialized = false;

	/**
	 * Import necessary services.
	 */
	public function __construct(
		protected ManagerRegistry $registry,
		private Stopwatch $stopwatch,
		private Security $security,
		private SiteRegistry $siteRegistry,
		private ApplicationRegistry $appRegistry
	) {
	}

	/**
	 * Identifies subscribed events.
	 */
	#[Override]
	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.request' => 'onKernelRequest',
			'kernel.controller' => 'onKernelController',
		];
	}

	/**
	 * Fired when kernel request comes in. Handles initialization of the
	 * CMS.
	 *
	 * @return void
	 *
	 * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException When current host
	 *                                                                       does not match a site
	 *                                                                       in the database
	 */
	public function onKernelRequest(RequestEvent $event)
	{
		// Skip if already initialized or not the main route.
		if (static::$hasInitialized || !$event->isMainRequest()) {
			static::$hasInitialized = true;

			return;
		}

		$stopwatch = $this->stopwatch;

		$this->appRegistry->getCurrent();
		$this->siteRegistry::getCurrent();

		// Add HTML Output Filter
		OutputParser::appendFilter(function (ResponseEvent $event) use ($stopwatch) {
			HtmlFilter::filterHtml($event, $stopwatch);
		});

		// TODO: Extensions::setInitialSiteId($site->getId() ?? 0);
	}

	/**
	 * Fired when a controller identified and called based on route.
	 */
	public function onKernelController(ControllerEvent $event): void
	{
		$this->_verifyUserHasSiteAccess();
	}

	/**
	 * Ensures the current user has access to the current site.
	 */
	protected function _verifyUserHasSiteAccess(): void
	{
		$user = $this->security->getUser();
		if (!($user instanceof User)) {
			return;
		} // Not logged in

		if (!$user->registeredToSite(SiteRegistry::getCurrent()->getId())) {
			throw new AuthenticationException('User not registered to current site');
		}
	}
}
