<?php
namespace iikiti\CMS\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Repository\Object\SiteRepository;
use SplStack;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AutoconfigureTag('site_registry')]
class SiteRegistry {

    protected static SplStack $siteStack;
	protected static bool $initialized = false;

	public function __construct(
        protected RequestStack $requestStack,
		protected ManagerRegistry $registry,
    ) {
		if(static::$initialized) return;
		else if(isset(self::$siteStack)) {
			static::$initialized = true;
			return;
		}
        static::$siteStack = new SplStack();
		$this->populate();
		static::$initialized = true;
    }

	private function populate(): void {
		$request = $this->requestStack->getCurrentRequest();
		if($request === null) return;
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

    public function getCurrentSite(): Site {
        return static::count() < 1 ? new Site() : static::$siteStack->top();
    }

    public function pushSite(Site $site): void {
        static::$siteStack->push($site);
    }

    public function popSite(): ?Site {
        return static::count() < 1 ? null : static::$siteStack->pop();
    }

    public function count(): int {
        return static::$siteStack->count();
    }

}
