<?php

namespace iikiti\CMS\EventSubscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\HtmlFilter;
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
		private SiteRegistry $siteRegistry
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
		if (
			static::$hasInitialized ||
			str_starts_with($event->getRequest()->attributes->get('_route'), '_')
		) {
			return;
		}

		// Add HTML Output Filter
		OutputParser::appendFilter(function (ResponseEvent $event) {
			HtmlFilter::filterHtml($event, $this->stopwatch);
		});

		$request = $event->getRequest();

		$registry = $this->registry;
		/** @var \Doctrine\ORM\EntityManager $em */
		$em = $registry->getManager();

		$em->getFilters()->enable('ObjectPropertyFilter');

		if (
			($site = $this->siteRegistry->getCurrentSite()) instanceof Site &&
			null !== $site->getId()
		) {
			// TODO: Extensions::setInitialSiteId($site->getId() ?? 0);
		}

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

		if (!$user->registeredToSite($this->siteRegistry->getCurrentSite()->getId())) {
			throw new AuthenticationException('User not registered to current site');
		}
	}
}
