<?php

declare(strict_types=1);

namespace iikiti\CMS\Registry;

abstract class AbstractRouteContextContributor implements RouteContextContributorInterface
{
	public function getConsumerProxyRoutes(): array
	{
		return [];
	}

	public function getSiteIndependentRoutes(): array
	{
		return [];
	}

	public function getApplicationAdminSettingsPathPatterns(): array
	{
		return [];
	}

	public function getSiteScopedSettingsPathPatterns(): array
	{
		return [];
	}

	public function getGuestTokenAllowedPathPrefixes(): array
	{
		return [];
	}

	public function getMcpExcludedPathPrefixes(): array
	{
		return [];
	}

	public function getSessionFormPathPrefixes(): array
	{
		return [];
	}
}
