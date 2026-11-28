<?php

declare(strict_types=1);

namespace iikiti\CMS\Registry;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared route classification for site resolution and request initialization.
 *
 * Aggregates core rules with plugin-contributed {@see RouteContextContributorInterface}
 * services while preserving legacy static helpers as deprecated compatibility wrappers.
 */
final class RouteContextClassifier
{
	/** @var list<string> */
	private const CORE_CONSUMER_PROXY_ROUTES = [
		'home',
		'page',
		'digital_asset_file',
		'digital_asset_signed_file',
		'public_fragment',
		'public_plugin_asset',
		'admin_home',
		'admin_spa',
	];

	/** @var list<string> */
	private const CORE_INTERNAL_WORKER_ROUTES = [
		'api_internal_scheduler_run_due',
		'api_internal_batch_item_process',
		'api_internal_digital_asset_process',
		'api_internal_background_queue_run',
		'html_logout',
	];

	/** @var list<string> */
	private const CORE_APPLICATION_ADMIN_SETTINGS_PATTERNS = [
		'^/api/admin/applications/[^/]+/headers(?:/preview)?$',
	];

	/** @var list<string> */
	private const CORE_SITE_SCOPED_SETTINGS_PATTERNS = [
		'^/api/site/[^/]+/headers(?:/preview)?$',
	];

	/** @var list<string> */
	private const CORE_SITE_INDEPENDENT_ROUTES = [
		'html_login',
		'admin_home',
		'admin_spa',
		'api_internal_response_headers_resolve',
		'api_internal_firewall_resolve',
		'firewall_challenge_verify',
	];

	/** @var list<string> */
	private const CORE_GUEST_TOKEN_PATH_PREFIXES = [
		'/api/public/render/',
		'/api/public/assets/',
		'/api/public/plugin-assets/',
	];

	/** @var list<string> */
	private const CORE_MCP_EXCLUDED_PATH_PREFIXES = [
		'/api/auth/',
		'/api/internal/',
		'/api/public/render/',
		'/api/public/assets/',
		'/api/public/plugin-assets/',
	];

	/** @var list<string> */
	private const CORE_SESSION_FORM_PATH_PREFIXES = [];

	/** @var list<string> */
	private array $consumerProxyRoutes;

	/** @var list<string> */
	private array $siteIndependentRoutes;

	/** @var list<string> */
	private array $applicationAdminSettingsPatterns;

	/** @var list<string> */
	private array $siteScopedSettingsPatterns;

	/** @var list<string> */
	private array $guestTokenAllowedPathPrefixes;

	/** @var list<string> */
	private array $mcpExcludedPathPrefixes;

	/** @var list<string> */
	private array $sessionFormPathPrefixes;

	/**
	 * @param iterable<RouteContextContributorInterface> $contributors
	 */
	public function __construct(
		#[AutowireIterator('iikiti.route_context_contributor')]
		iterable $contributors = [],
	) {
		$consumerProxyRoutes = self::CORE_CONSUMER_PROXY_ROUTES;
		$siteIndependentRoutes = self::CORE_SITE_INDEPENDENT_ROUTES;
		$appSettingsPatterns = self::CORE_APPLICATION_ADMIN_SETTINGS_PATTERNS;
		$siteSettingsPatterns = self::CORE_SITE_SCOPED_SETTINGS_PATTERNS;
		$guestTokenPrefixes = self::CORE_GUEST_TOKEN_PATH_PREFIXES;
		$mcpExcludedPrefixes = self::CORE_MCP_EXCLUDED_PATH_PREFIXES;
		$sessionFormPrefixes = self::CORE_SESSION_FORM_PATH_PREFIXES;

		foreach ($contributors as $contributor) {
			array_push($consumerProxyRoutes, ...$contributor->getConsumerProxyRoutes());
			array_push($siteIndependentRoutes, ...$contributor->getSiteIndependentRoutes());
			array_push($appSettingsPatterns, ...$contributor->getApplicationAdminSettingsPathPatterns());
			array_push($siteSettingsPatterns, ...$contributor->getSiteScopedSettingsPathPatterns());
			array_push($guestTokenPrefixes, ...$contributor->getGuestTokenAllowedPathPrefixes());
			array_push($mcpExcludedPrefixes, ...$contributor->getMcpExcludedPathPrefixes());
			array_push($sessionFormPrefixes, ...$contributor->getSessionFormPathPrefixes());
		}

		$this->consumerProxyRoutes = array_values(array_unique($consumerProxyRoutes));
		$this->siteIndependentRoutes = array_values(array_unique($siteIndependentRoutes));
		$this->applicationAdminSettingsPatterns = array_values(array_unique($appSettingsPatterns));
		$this->siteScopedSettingsPatterns = array_values(array_unique($siteSettingsPatterns));
		$this->guestTokenAllowedPathPrefixes = array_values(array_unique($guestTokenPrefixes));
		$this->mcpExcludedPathPrefixes = array_values(array_unique($mcpExcludedPrefixes));
		$this->sessionFormPathPrefixes = array_values(array_unique($sessionFormPrefixes));
	}

	public function matchesConsumerProxyRoute(?string $route): bool
	{
		return null !== $route && in_array($route, $this->consumerProxyRoutes, true);
	}

	public function matchesInternalWorkerRoute(?string $route): bool
	{
		return null !== $route && in_array($route, self::CORE_INTERNAL_WORKER_ROUTES, true);
	}

	public function matchesApplicationAdminSettingsPath(string $path): bool
	{
		foreach ($this->applicationAdminSettingsPatterns as $pattern) {
			if (1 === @preg_match('~'.$pattern.'~D', $path)) {
				return true;
			}
		}

		return false;
	}

	public function matchesSiteScopedSettingsPath(string $path): bool
	{
		foreach ($this->siteScopedSettingsPatterns as $pattern) {
			if (1 === @preg_match('~'.$pattern.'~D', $path)) {
				return true;
			}
		}

		return false;
	}

	public function matchesSiteIndependentRoute(
		?string $route,
		string $path,
		string $applicationRole,
		bool $publicPageRenderViaApi,
	): bool {
		return (null !== $route && in_array($route, $this->siteIndependentRoutes, true))
			|| $this->matchesApplicationAdminSettingsPath($path)
			|| ('consumer' === $applicationRole && $this->matchesConsumerProxyRoute($route))
			|| ($publicPageRenderViaApi && in_array($route, ['home', 'page'], true));
	}

	public function matchesSiteAccessCheckExempt(
		Request $request,
		string $applicationRole,
		bool $publicPageRenderViaApi = false,
	): bool {
		$route = $request->attributes->get('_route');
		$routeName = is_string($route) ? $route : null;
		$path = $request->getPathInfo();

		return 'consumer' === $applicationRole
			|| $this->matchesSiteIndependentRoute($routeName, $path, $applicationRole, $publicPageRenderViaApi)
			|| $this->matchesInternalWorkerRoute($routeName)
			|| $this->matchesSiteScopedSettingsPath($path);
	}

	public function matchesPublicRenderOrAssetRoute(?string $route): bool
	{
		return null !== $route && (
			str_starts_with($route, 'api_public_render_')
			|| str_starts_with($route, 'api_public_asset_')
		);
	}

	public function matchesSessionForwardedHostRoute(?string $route, string $path): bool
	{
		return 'api_auth_session_token' === $route || $this->isSessionFormPath($path);
	}

	public function matchesStatelessForwardedHostRoute(?string $route, string $path): bool
	{
		return $this->matchesPublicRenderOrAssetRoute($route)
			|| '/api/mcp' === $path
			|| str_starts_with($path, '/api/admin/')
			|| $this->matchesSiteScopedSettingsPath($path)
			|| str_starts_with($path, '/api/editor/');
	}

	public function isGuestTokenAllowedPath(string $path): bool
	{
		foreach ($this->guestTokenAllowedPathPrefixes as $prefix) {
			if ('' !== $prefix && str_starts_with($path, $prefix)) {
				return true;
			}
		}

		return false;
	}

	public function isMcpExcludedPath(string $path, string $routeName): bool
	{
		foreach ($this->mcpExcludedPathPrefixes as $prefix) {
			if ('' !== $prefix && str_starts_with($path, $prefix)) {
				return true;
			}
		}

		return str_starts_with($routeName, 'api_internal_')
			|| str_starts_with($routeName, 'api_auth_')
			|| str_starts_with($routeName, 'api_public_render_')
			|| '_api_errors' === $routeName
			|| str_starts_with($routeName, '_api_validation_errors_');
	}

	public function isSessionFormPath(string $path): bool
	{
		foreach ($this->sessionFormPathPrefixes as $prefix) {
			if ('' !== $prefix && str_starts_with($path, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prefixes rewritten from relative URLs to backend API base URLs in Consumer responses.
	 *
	 * @return list<string>
	 */
	public function getConsumerUrlRewritePrefixes(): array
	{
		return $this->sessionFormPathPrefixes;
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isConsumerProxyRoute(?string $route): bool
	{
		return (new self())->matchesConsumerProxyRoute($route);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isInternalWorkerRoute(?string $route): bool
	{
		return (new self())->matchesInternalWorkerRoute($route);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isApplicationAdminSettingsPath(string $path): bool
	{
		return (new self())->matchesApplicationAdminSettingsPath($path);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isSiteScopedSettingsPath(string $path): bool
	{
		return (new self())->matchesSiteScopedSettingsPath($path);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isSiteIndependentRoute(
		?string $route,
		string $path,
		string $applicationRole,
		bool $publicPageRenderViaApi,
	): bool {
		return (new self())->matchesSiteIndependentRoute($route, $path, $applicationRole, $publicPageRenderViaApi);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isSiteAccessCheckExempt(
		Request $request,
		string $applicationRole,
		bool $publicPageRenderViaApi = false,
	): bool {
		return (new self())->matchesSiteAccessCheckExempt($request, $applicationRole, $publicPageRenderViaApi);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isPublicRenderOrAssetRoute(?string $route): bool
	{
		return (new self())->matchesPublicRenderOrAssetRoute($route);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isSessionForwardedHostRoute(?string $route, string $path): bool
	{
		return (new self())->matchesSessionForwardedHostRoute($route, $path);
	}

	/** @deprecated Inject {@see RouteContextClassifier} instead. */
	public static function isStatelessForwardedHostRoute(?string $route, string $path): bool
	{
		return (new self())->matchesStatelessForwardedHostRoute($route, $path);
	}
}
