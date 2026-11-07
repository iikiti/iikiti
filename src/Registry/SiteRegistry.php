<?php

namespace iikiti\CMS\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\GuestApiTokenManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages sites (children of applications) stored in the database.
 * Allows access to the current site based on the request.
 */
#[AutoconfigureTag('site_registry')]
class SiteRegistry
{
	/** @var \SplStack<Site> */
	protected static \SplStack $siteStack;
	protected static bool $initialized = false;
	protected static bool $populated = false;

	public function __construct(
		private readonly RequestStack $requestStack,
		private readonly ManagerRegistry $registry,
		private readonly GuestApiTokenManager $guestTokens,
		#[Autowire('%application_role%')]
		private readonly string $applicationRole,
	) {
		self::initialize();
	}

	public function ensureInitialized(): void
	{
		self::initialize();
	}

	private static function initialize(): void
	{
		if (static::$initialized) {
			return;
		}
		static::$siteStack = new \SplStack();
		static::$initialized = true;
	}

	public function initializeForRequest(): void
	{
		static::$siteStack = new \SplStack();
		static::$initialized = true;
		static::$populated = false;
		static::populate($this->requestStack, $this->registry, $this->guestTokens, $this->applicationRole);
	}

	public static function isInitialized(): bool
	{
		return static::$initialized;
	}

	protected static function populate(
		RequestStack $requestStack,
		ManagerRegistry $registry,
		GuestApiTokenManager $guestTokens,
		string $applicationRole,
	): void {
		$route = $requestStack->getCurrentRequest()?->attributes->get('_route');
		$consumerProxyRoute = in_array($route, ['home', 'page', 'digital_asset_file'], true);
		$siteIndependentRoute = 'html_login' === $route || in_array($route, ['admin_home', 'admin_spa'], true)
			|| ('consumer' === $applicationRole && $consumerProxyRoute);
		if (static::$populated || !static::$initialized || 'consumer' === $applicationRole || $siteIndependentRoute) {
			static::$populated = true;

			return;
		}
		$request = $requestStack->getCurrentRequest();
		if (null === $request) {
			// No HTTP request (e.g. CLI commands, workers). Leave the stack
			// empty rather than failing construction of every service that
			// depends on the registry; callers that actually need the current
			// site still receive a clear error from getCurrent().
			return;
		}
		if (in_array($request->attributes->get('_route'), ['api_internal_scheduler_run_due', 'api_internal_batch_item_process', 'api_internal_digital_asset_process', 'api_internal_background_queue_run', 'html_logout'], true)) {
			static::$populated = true;

			return;
		}
		$host = $request->getHost();
		$route = $request->attributes->get('_route');
		if ('api_auth_login' === $route) {
			$loginHost = $request->request->get('_site_host');
			$sessionHost = $request->hasSession() ? $request->getSession()->get('auth_site_host') : null;
			if (is_string($loginHost) && is_string($sessionHost) && 0 !== strcasecmp($loginHost, $sessionHost)) {
				throw new AccessDeniedHttpException('Login site context does not match the current session.');
			}
			if (is_string($loginHost) && '' !== $loginHost) {
				$host = $loginHost;
			}
		} elseif ('mfa_challenge' === $route && $request->hasSession()) {
			$loginHost = $request->getSession()->get('auth_site_host');
			if (is_string($loginHost) && '' !== $loginHost) {
				$host = $loginHost;
			}
		}
		if (str_starts_with((string) $request->attributes->get('_route'), 'api_public_render_')
			|| str_starts_with((string) $request->attributes->get('_route'), 'api_public_asset_')
			|| 'api_auth_session_token' === $request->attributes->get('_route')
			|| str_starts_with($request->getPathInfo(), '/api/forms/')
			|| str_starts_with($request->getPathInfo(), '/api/admin/')
			|| str_starts_with($request->getPathInfo(), '/api/editor/')) {
			$forwardedHost = $request->headers->get('X-SITE-HOST');
			if (is_string($forwardedHost) && '' !== $forwardedHost) {
				$token = $request->headers->get('X-AUTH-TOKEN', '');
				$guest = str_starts_with($token, 'guest1.') ? $guestTokens->decode($token) : null;
				if (str_starts_with($token, 'guest1.') && (null === $guest || 0 !== strcasecmp($guest->getSiteHost(), $forwardedHost))) {
					throw new NotFoundHttpException('Site does not exist for current domain.');
				}
				$host = $forwardedHost;
			}
			$siteHost = $request->attributes->get('siteHost');
			if (is_string($siteHost) && '' !== $siteHost) {
				$host = $siteHost;
			}
			if (str_starts_with($request->getPathInfo(), '/api/forms/')) {
				$formHost = $request->query->get('_site_host') ?? $request->request->get('_site_host');
				if ((!is_string($formHost) || '' === $formHost) && $request->hasSession()) {
					$formHost = $request->getSession()->get('auth_site_host');
				}
				if (is_string($formHost) && '' !== $formHost) {
					$host = $formHost;
				}
			}
		}

		$siteRep = $registry->getManager()->getRepository(Site::class);
		$sites = $siteRep->findByDomain($host);
		if (empty($sites)) {
			throw new NotFoundHttpException('Site does not exist for current domain.');
		}
		foreach ($sites as $site) {
			static::pushSite($site);
		}
		static::$populated = true;
	}

	/**
	 * @return \SplStack<Site>
	 */
	public static function getStack(): \SplStack
	{
		return clone static::$siteStack;
	}

	/**
	 * @return array<int,Site>
	 */
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
