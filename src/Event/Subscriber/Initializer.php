<?php

namespace iikiti\CMS\Event\Subscriber;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
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

	protected static SiteRegistry $siteRegistry;

	public function __construct(
		protected ManagerRegistry $registry,
		private Stopwatch $stopwatch,
		private Security $security
	) {
		/** @var SiteRepository $siteRepository */
		$siteRepository = $this->registry->getManager()->getRepository(Site::class);
		self::$siteRegistry = $siteRepository->getRegistry();
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
		if (static::$hasInitialized || $event->isMainRequest()) {
			return;
		}

		// Add HTML Output Filter
		OutputParser::appendFilter(function (ResponseEvent $event) {
			HtmlFilter::filterHtml($event, $this->stopwatch);
		});

		/** @var \Doctrine\ORM\EntityManager $em */
		$em = $this->registry->getManager();

		$em->getFilters()->enable('ObjectPropertyFilter');

		if (
			($site = self::$siteRegistry->getCurrentSite()) instanceof Site &&
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

		if (!$user->registeredToSite(static::$siteRegistry->getCurrentSite()->getId())) {
			throw new AuthenticationException('User not registered to current site');
		}
	}
}
