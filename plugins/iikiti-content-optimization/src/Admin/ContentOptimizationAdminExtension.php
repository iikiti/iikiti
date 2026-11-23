<?php

declare(strict_types=1);

namespace iikiti\Extension\ContentOptimization\Admin;

use iikiti\CMS\Admin\AdminExtensionInterface;
use iikiti\CMS\Admin\AdminExtensionTrait;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;

final class ContentOptimizationAdminExtension implements AdminExtensionInterface
{
	use AdminExtensionTrait;

	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create(
				'Content Optimization',
				'/content-optimization',
				'search',
				65,
				[],
				'ROLE_SITE_MANAGER',
			),
		];
	}

	public function getAdminScreens(): array
	{
		return [
			new AdminScreen(
				path: '/content-optimization',
				title: 'Content Optimization',
				type: 'custom',
				component: 'ContentOptimization',
				permission: 'ROLE_SITE_MANAGER',
				description: 'Manage metadata, indexing policy, social previews, and analytics connectors.',
			),
		];
	}

	public function getResources(): array
	{
		return [];
	}
}
