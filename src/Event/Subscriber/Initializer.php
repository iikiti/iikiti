<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Filters\InlineCssFilter;
use iikiti\CMS\Filters\InlineJavaScriptFilter;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
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
		private ApplicationRegistry $appRegistry,
		private InlineCssFilter $inlineCssFilter,
		private InlineJavaScriptFilter $inlineJavaScriptFilter,
		#[Autowire('%application_role%')]
		private string $applicationRole,
		#[Autowire('%public_page_render_via_api%')]
		private bool $publicPageRenderViaApi = false,
	) {
	}

	/**
	 * Identifies subscribed events.
	 */
	#[Override]
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

		$stopwatch = $this->stopwatch;

		$route = $event->getRequest()->attributes->get('_route');
		$consumerProxyRoute = in_array($route, ['home', 'page', 'digital_asset_file', 'public_fragment', 'admin_home', 'admin_spa'], true);
		$apiBackedPageRoute = $this->publicPageRenderViaApi && in_array($route, ['home', 'page'], true);
		$siteIndependentRoute = 'html_login' === $route
			|| in_array($route, ['admin_home', 'admin_spa'], true)
			|| ('consumer' === $this->applicationRole && $consumerProxyRoute)
			|| $apiBackedPageRoute;
		if ('consumer' === $this->applicationRole) {
			if (!$consumerProxyRoute) {
				throw new ServiceUnavailableHttpException(null, 'This consumer runtime only supports API-backed page, asset, and admin shell routes.');
			}
		} elseif (!$siteIndependentRoute && !in_array($route, ['api_internal_scheduler_run_due', 'api_internal_batch_item_process', 'api_internal_digital_asset_process', 'api_internal_background_queue_run', 'html_logout'], true)) {
			$this->appRegistry->getCurrent();
			$this->siteRegistry::getCurrent();
		}

		if (!static::$hasInitialized) {
			OutputParser::appendFilter(function (ResponseEvent $event) use ($stopwatch) {
				HtmlFilter::filterHtml($event, $stopwatch);
			});
			OutputParser::appendFilter($this->inlineCssFilter->filter(...));
			OutputParser::appendFilter($this->inlineJavaScriptFilter->filter(...));
			static::$hasInitialized = true;
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
		if ('consumer' === $this->applicationRole || in_array($request->attributes->get('_route'), ['admin_home', 'admin_spa', 'html_login'], true)) {
			return;
		}

		$user = $this->security->getUser();
		if (!($user instanceof User)) {
			return;
		} // Not logged in

		if (!$user->registeredToSite(SiteRegistry::getCurrent()->getId())) {
			throw new AuthenticationException('User not registered to current site');
		}
	}
}
