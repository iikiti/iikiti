<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\RouteContextClassifier;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Runs early to initialize the application.
 */
class Initializer implements EventSubscriberInterface
{
	private readonly RouteContextClassifier $routeClassifier;

	/**
	 * Import necessary services.
	 */
	public function __construct(
		protected ManagerRegistry $registry,
		private Security $security,
		private SiteRegistry $siteRegistry,
		private ApplicationRegistry $appRegistry,
		#[Autowire('%application_role%')]
		private string $applicationRole,
		#[Autowire('%public_page_render_via_api%')]
		private bool $publicPageRenderViaApi = false,
		?RouteContextClassifier $routeClassifier = null,
	) {
		$this->routeClassifier = $routeClassifier ?? new RouteContextClassifier();
	}

	/**
	 * Identifies subscribed events.
	 */
	#[\Override]
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 30],
			KernelEvents::CONTROLLER => 'onKernelController',
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
		if (!$event->isMainRequest()) {
			return;
		}

		$this->siteRegistry->initializeForRequest();

		$route = $event->getRequest()->attributes->get('_route');
		$routeName = is_string($route) ? $route : null;
		$path = $event->getRequest()->getPathInfo();
		$consumerProxyRoute = $this->routeClassifier->matchesConsumerProxyRoute($routeName);
		$siteIndependentRoute = $this->routeClassifier->matchesSiteIndependentRoute(
			$routeName,
			$path,
			$this->applicationRole,
			$this->publicPageRenderViaApi,
		);
		if ('consumer' === $this->applicationRole) {
			if (!$consumerProxyRoute) {
				throw new ServiceUnavailableHttpException(null, 'This consumer runtime only supports API-backed page, asset, and admin shell routes.');
			}
		} elseif (!$siteIndependentRoute && !$this->routeClassifier->matchesInternalWorkerRoute($routeName)) {
			$this->appRegistry->getCurrent();
			$this->siteRegistry::getCurrent();
		}

		// TODO: Extensions::setInitialSiteId($site->getId() ?? 0);
	}

	/**
	 * Fired when a controller identified and called based on route.
	 */
	public function onKernelController(ControllerEvent $event): void
	{
		$this->_verifyUserHasSiteAccess($event->getRequest());
	}

	/**
	 * Ensures the current user has access to the current site.
	 */
	protected function _verifyUserHasSiteAccess(Request $request): void
	{
		if ($this->routeClassifier->matchesSiteAccessCheckExempt($request, $this->applicationRole)) {
			return;
		}

		$user = $this->security->getUser();
		if (!$user instanceof User) {
			return;
		} // Not logged in

		if (!$user->registeredToSite(SiteRegistry::getCurrent()->getId())) {
			throw new AuthenticationException('User not registered to current site');
		}
	}
}
