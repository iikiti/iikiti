<?php

namespace iikiti\CMS\Registry;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\ApiToken;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Security\GuestApiTokenManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
		#[Autowire('%public_page_render_via_api%')]
		private readonly bool $publicPageRenderViaApi = false,
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
		static::populate($this->requestStack, $this->registry, $this->guestTokens, $this->applicationRole, $this->publicPageRenderViaApi);
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
		bool $publicPageRenderViaApi,
	): void {
		$route = $requestStack->getCurrentRequest()?->attributes->get('_route');
		$routeName = is_string($route) ? $route : null;
		$path = $requestStack->getCurrentRequest()?->getPathInfo() ?? '';
		$siteIndependentRoute = RouteContextClassifier::isSiteIndependentRoute(
			$routeName,
			$path,
			$applicationRole,
			$publicPageRenderViaApi,
		);
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
		if (RouteContextClassifier::isInternalWorkerRoute($routeName)) {
			static::$populated = true;

			return;
		}
		$host = $request->getHost();
		if ('api_auth_login' === $routeName) {
			$loginHost = $request->request->get('_site_host');
			$sessionHost = $request->hasSession() ? $request->getSession()->get('auth_site_host') : null;
			if (is_string($loginHost) && is_string($sessionHost) && 0 !== strcasecmp($loginHost, $sessionHost)) {
				throw new AccessDeniedHttpException('Login site context does not match the current session.');
			}
			if (is_string($loginHost) && '' !== $loginHost) {
				$host = $loginHost;
			}
		} elseif ('mfa_challenge' === $routeName && $request->hasSession()) {
			$loginHost = $request->getSession()->get('auth_site_host');
			if (is_string($loginHost) && '' !== $loginHost) {
				$host = $loginHost;
			}
		}
		$isStatelessForwarded = RouteContextClassifier::isStatelessForwardedHostRoute($routeName, $path);
		$isSessionForwarded = RouteContextClassifier::isSessionForwardedHostRoute($routeName, $path);
		if ($isStatelessForwarded || $isSessionForwarded) {
			$forwardedHost = $request->headers->get('X-SITE-HOST');
			if (is_string($forwardedHost) && '' !== $forwardedHost) {
				if ($isStatelessForwarded) {
					$token = (string) $request->headers->get('X-AUTH-TOKEN', '');
					$isPublicRenderOrAsset = RouteContextClassifier::isPublicRenderOrAssetRoute($routeName);
					$isGuestToken = str_starts_with($token, 'guest1.') || str_starts_with($token, 'guest2.');
					if ($isGuestToken) {
						$guest = $guestTokens->decode($token);
						if (null === $guest || 0 !== strcasecmp($guest->getSiteHost(), $forwardedHost)) {
							throw new AccessDeniedHttpException('Invalid or out-of-scope guest API credential.');
						}
						$host = $forwardedHost;
					} elseif ('' !== $token && self::isValidApiToken($registry, $token)) {
						$host = $forwardedHost;
					} elseif ($isPublicRenderOrAsset) {
						throw new AccessDeniedHttpException('A site-scoped API credential is required.');
					}
				} else {
					$host = $forwardedHost;
				}
			} elseif ($isStatelessForwarded && RouteContextClassifier::isPublicRenderOrAssetRoute($routeName)) {
				$token = (string) $request->headers->get('X-AUTH-TOKEN', '');
				if ('' === $token) {
					throw new AccessDeniedHttpException('A site-scoped API credential is required.');
				}
			}
			$siteHost = $request->attributes->get('siteHost');
			if (is_string($siteHost) && '' !== $siteHost) {
				$host = $siteHost;
			}
			if (str_starts_with($path, '/api/forms/')) {
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

	private static function isValidApiToken(ManagerRegistry $registry, string $token): bool
	{
		if (!str_starts_with($token, ApiToken::TOKEN_PREFIX)) {
			return false;
		}

		$repository = $registry->getManager()->getRepository(ApiToken::class);
		$record = $repository->findOneBy(['token' => $token]);

		return $record instanceof ApiToken && !$record->isExpired();
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
