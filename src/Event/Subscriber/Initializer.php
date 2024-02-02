<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Registry\ApplicationRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class OutputParser.
 */
class Initializer implements EventSubscriberInterface
{
	protected static bool $hasInitialized = false;

	public function __construct(
		protected ManagerRegistry $registry,
		private Stopwatch $stopwatch,
		private Security $security,
		private SiteRegistry $siteRegistry,
		private ApplicationRegistry $appRegistry
	) {
	}

	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.request' => 'onKernelRequest',
			'kernel.controller' => 'onKernelController',
		];
	}

	/**
	 * @return void
	 *
	 * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
	 *                                                                       When current host does not match a site in the database
	 */
	public function onKernelRequest(RequestEvent $event)
	{
		// Skip if already initialized or not the main route.
		if (static::$hasInitialized || !$event->isMainRequest()) {
			return;
		}

		$stopwatch = $this->stopwatch;

		$this->appRegistry->getCurrent();
		$this->siteRegistry::getCurrent();

		// Add HTML Output Filter
		OutputParser::appendFilter(function (ResponseEvent $event) use ($stopwatch) {
			HtmlFilter::filterHtml($event, $stopwatch);
		});

		/** @var \Doctrine\ORM\EntityManager $em */
		$em = $this->registry->getManager();

		$em->getFilters()->enable('ObjectPropertyFilter');

		// TODO: Extensions::setInitialSiteId($site->getId() ?? 0);

		static::$hasInitialized = true;
	}

	public function onKernelController(ControllerEvent $event): void
	{
		$this->_verifyUserHasSiteAccess();
	}

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
