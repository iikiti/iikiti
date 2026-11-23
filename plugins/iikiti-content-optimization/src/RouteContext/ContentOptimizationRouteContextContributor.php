<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\RouteContext;

use iikiti\CMS\Registry\AbstractRouteContextContributor;

/**
 * Registers content-optimization public discovery and settings routes cleanly
 * without hardcoding plugin paths in core {@see \iikiti\CMS\Registry\RouteContextClassifier}.
 */
final class ContentOptimizationRouteContextContributor extends AbstractRouteContextContributor
{
	public function getConsumerProxyRoutes(): array
	{
		return ['public_robots', 'public_sitemap'];
	}

	public function getApplicationAdminSettingsPathPatterns(): array
	{
		return ['^/api/admin/applications/[^/]+/content-optimization/settings$'];
	}

	public function getSiteScopedSettingsPathPatterns(): array
	{
		return ['^/api/site/[^/]+/content-optimization/(?:settings|activation)$'];
	}

	public function getGuestTokenAllowedPathPrefixes(): array
	{
		return [
			'/api/public/content-optimization/robots',
			'/api/public/content-optimization/sitemap',
		];
	}

	public function getMcpExcludedPathPrefixes(): array
	{
		return ['/api/public/content-optimization/'];
	}
}
