<?php

namespace iikiti\CMS\EventListeners;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Filters\HtmlFilter;
use iikiti\CMS\Registry\SiteRegistry;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class OutputParser
 *
 * @package iikiti\CMS\src\EventListeners
 */
class Initializer implements EventSubscriberInterface, ContainerAwareInterface
{
    protected static $hasInitialized = false;
    protected ContainerInterface $container;

    public function __construct(
        protected ManagerRegistry $registry
    ) {}

    public function setContainer(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @inheritDoc
     */
    #[ArrayShape(['kernel.response' => "string"])]
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
            str_starts_with($event->getRequest()->get('_route'), '_')
        ) {
            return;
        }

        // Add HTML Output Filter
        OutputParser::appendFilter(function(ResponseEvent $event) {
            HtmlFilter::filterHtml($event);
        });

        $request = $event->getRequest();
        $registry = $this->registry;
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $registry->getManager();

        // Enable Object Type Filter
        $em->getFilters()->enable('ObjectTypeFilter');

        $this->matchDomain($request);

        // Enable Site filter
        $em->getFilters()->enable('SiteFilter');

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
        $sites = $this->registry->getManager()->getRepository(Site::class)
            ->findByDomain($request->getHost());
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
