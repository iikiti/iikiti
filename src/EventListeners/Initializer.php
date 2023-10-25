<?php

namespace iikiti\CMS\EventListeners;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Loader\Extensions;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class OutputParser
 *
 * @package iikiti\CMS\src\EventListeners
 */
class Initializer implements EventSubscriberInterface, ContainerAwareInterface
{
	protected static bool $hasInitialized = false;
	protected ?ContainerInterface $container = null;

	public function __construct(
		protected ManagerRegistry $registry,
		private Stopwatch $stopwatch
	) {}

	public function setContainer(?ContainerInterface $container = null): void
	{
		$this->container = $container;
	}

	/**
	 * @inheritDoc
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'kernel.request' => 'onKernelRequest'
		];
	}

	/**
	 * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
	 *
	 * @return void
	 * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
	 *         When current host does not match a site in the database.
	 */
	public function onKernelRequest(RequestEvent $event) {
		// Skip if already initialized or not the main route.
		if(
			static::$hasInitialized ||
			str_starts_with($event->getRequest()->attributes->get('_route'), '_')
		) {
			return;
		}

		// Add HTML Output Filter
		OutputParser::appendFilter(function(ResponseEvent $event) {
			HtmlFilter::filterHtml($event, $this->stopwatch);
		});

		$request = $event->getRequest();

		$registry = $this->registry;
		/** @var \Doctrine\ORM\EntityManager $em */
		$em = $registry->getManager();

		$em->getFilters()->enable('ObjectPropertyFilter');

		$this->matchDomain($request);
		
		if(SiteRegistry::getCurrentSite() instanceof Site) {
			Extensions::setInitialSiteId(
				SiteRegistry::getCurrentSite()->getId() ?? 0
			);
		}

		static::$hasInitialized = true;
	}

	/**
	 * matchDomain
	 * 
	 * Get and add possible site(s) to registry based on the current
	 * domain/host.
	 *
	 * @param  Request $request
	 */
	private function matchDomain(Request $request): void {
		/** @var SiteRepository $siteRep */
		$siteRep = $this->registry->getManager()->getRepository(Site::class);
		$sites = $siteRep->findByDomain($request->getHost());
		if(empty($sites)) {
			throw new NotFoundHttpException(
				'Site does not exist for current domain.'
			);
		}
		foreach($sites as $site) {
			SiteRegistry::pushSite($site);
		}
	}

}
