<?php

namespace iikiti\CMS\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages sites (children of applications) stored in the database.
 * Allows access to the current site based on the request.
 */
#[AutoconfigureTag('site_registry')]
class SiteRegistry
{
	protected static \SplStack $siteStack;
	protected static bool $initialized = false;
	protected static bool $populated = false;

	public function __construct(
		RequestStack $requestStack,
		ManagerRegistry $registry
	) {
		static::initialize($requestStack, $registry);
	}

	protected static function initialize(
		RequestStack $requestStack,
		ManagerRegistry $registry
	): void {
		if (static::$initialized) {
			return;
		}
		static::$siteStack = new \SplStack();
		static::$initialized = true;
		static::populate($requestStack, $registry);
	}

	protected static function populate(
		RequestStack $requestStack,
		ManagerRegistry $registry
	): void {
		if (static::$populated || !static::$initialized) {
			return;
		}
		$request = $requestStack->getCurrentRequest();
		if (null === $request) {
			throw new NotFoundHttpException('Request does not exist.');
		}
		$siteRep = $registry->getManager()->getRepository(Site::class);
		$sites = $siteRep->findByDomain($request->getHost());
		if (empty($sites)) {
			throw new NotFoundHttpException('Site does not exist for current domain.');
		}
		foreach ($sites as $site) {
			static::pushSite($site);
		}
		static::$populated = true;
	}

	public static function getStack(): \SplStack
	{
		return clone static::$siteStack;
	}

	public static function getAll(): array
	{
		return iterator_to_array(static::$siteStack);
	}

	public static function getCurrent(): Site
	{
		if (!static::hasCurrent()) {
			throw new NotFoundHttpException('No site.');
		}

		return static::$siteStack->top();
	}

	public static function hasCurrent(): bool
	{
		return static::count() > 0;
	}

	public static function pushSite(Site $site): void
	{
		static::$siteStack->push($site);
	}

	public static function popSite(): Site
	{
		if (!static::hasCurrent()) {
			throw new NotFoundHttpException('No site.');
		}

		return static::$siteStack->pop();
	}

	public static function count(): int
	{
		return static::$siteStack->count();
	}
}
