<?php

declare(strict_types=1);

namespace iikiti\CMS\Registry;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared route classification for site resolution and request initialization.
 *
 * Centralizing these rules prevents drift between {@see SiteRegistry} and
 * {@see \iikiti\CMS\Event\Subscriber\Initializer}.
 */
final class RouteContextClassifier
{
	/** @var list<string> */
	private const CONSUMER_PROXY_ROUTES = [
		'home',
		'page',
		'digital_asset_file',
		'public_fragment',
		'public_robots',
		'public_sitemap',
		'admin_home',
		'admin_spa',
	];

	/** @var list<string> */
	private const INTERNAL_WORKER_ROUTES = [
		'api_internal_scheduler_run_due',
		'api_internal_batch_item_process',
		'api_internal_digital_asset_process',
		'api_internal_background_queue_run',
		'html_logout',
	];

	public static function isConsumerProxyRoute(?string $route): bool
	{
		return null !== $route && in_array($route, self::CONSUMER_PROXY_ROUTES, true);
	}

	public static function isInternalWorkerRoute(?string $route): bool
	{
		return null !== $route && in_array($route, self::INTERNAL_WORKER_ROUTES, true);
	}

	public static function isApplicationAdminSettingsPath(string $path): bool
	{
		return 1 === preg_match('~^/api/admin/applications/[^/]+/headers(?:/preview)?$~D', $path)
			|| 1 === preg_match('~^/api/admin/applications/[^/]+/content-optimization/settings$~D', $path);
	}

	public static function isSiteScopedSettingsPath(string $path): bool
	{
		return 1 === preg_match('~^/api/site/[^/]+/headers(?:/preview)?$~D', $path)
			|| 1 === preg_match('~^/api/site/[^/]+/content-optimization/(?:settings|activation)$~D', $path);
	}

	public static function isSiteIndependentRoute(
		?string $route,
		string $path,
		string $applicationRole,
		bool $publicPageRenderViaApi,
	): bool {
		return 'html_login' === $route
			|| in_array($route, ['admin_home', 'admin_spa'], true)
			|| self::isApplicationAdminSettingsPath($path)
			|| 'api_internal_response_headers_resolve' === $route
			|| 'api_internal_firewall_resolve' === $route
			|| 'firewall_challenge_verify' === $route
			|| ('consumer' === $applicationRole && self::isConsumerProxyRoute($route))
			|| ($publicPageRenderViaApi && in_array($route, ['home', 'page'], true));
	}

	public static function isSiteAccessCheckExempt(Request $request, string $applicationRole): bool
	{
		$route = $request->attributes->get('_route');
		$path = $request->getPathInfo();

		return 'consumer' === $applicationRole
			|| in_array($route, ['admin_home', 'admin_spa', 'html_login'], true)
			|| 'api_internal_response_headers_resolve' === $route
			|| self::isApplicationAdminSettingsPath($path)
			|| self::isSiteScopedSettingsPath($path);
	}

	public static function isPublicRenderOrAssetRoute(?string $route): bool
	{
		return null !== $route && (
			str_starts_with($route, 'api_public_render_')
			|| str_starts_with($route, 'api_public_asset_')
		);
	}

	public static function isSessionForwardedHostRoute(?string $route, string $path): bool
	{
		return 'api_auth_session_token' === $route || str_starts_with($path, '/api/forms/');
	}

	public static function isStatelessForwardedHostRoute(?string $route, string $path): bool
	{
		return self::isPublicRenderOrAssetRoute($route)
			|| '/api/mcp' === $path
			|| str_starts_with($path, '/api/admin/')
			|| self::isSiteScopedSettingsPath($path)
			|| str_starts_with($path, '/api/editor/');
	}
}
