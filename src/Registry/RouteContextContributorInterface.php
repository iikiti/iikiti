<?php

declare(strict_types=1);

namespace iikiti\CMS\Registry;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lightweight, database-free route-context rules contributed by plugins.
 *
 * Implementations run at early `KernelEvents::REQUEST` (priority 30) and MUST
 * NOT inject {@see SiteRegistry} or `EntityManagerInterface`.
 */
#[AutoconfigureTag('iikiti.route_context_contributor')]
interface RouteContextContributorInterface
{
	/**
	 * Route names allowed on database-free Consumer nodes (proxied to API).
	 *
	 * @return list<string>
	 */
	public function getConsumerProxyRoutes(): array;

	/**
	 * Route names that do not require resolving a site from the host.
	 *
	 * @return list<string>
	 */
	public function getSiteIndependentRoutes(): array;

	/**
	 * Path regex patterns (without delimiters) treated as application-level settings.
	 *
	 * @return list<string>
	 */
	public function getApplicationAdminSettingsPathPatterns(): array;

	/**
	 * Path regex patterns (without delimiters) treated as site-scoped settings.
	 *
	 * @return list<string>
	 */
	public function getSiteScopedSettingsPathPatterns(): array;

	/**
	 * Path prefixes allowed for guest API token authentication.
	 *
	 * @return list<string>
	 */
	public function getGuestTokenAllowedPathPrefixes(): array;

	/**
	 * Path prefixes excluded from the callable MCP API catalog.
	 *
	 * @return list<string>
	 */
	public function getMcpExcludedPathPrefixes(): array;

	/**
	 * Session-forwarded path prefixes supporting `_site_host` overrides and Consumer URL rewrites.
	 *
	 * @return list<string>
	 */
	public function getSessionFormPathPrefixes(): array;
}
