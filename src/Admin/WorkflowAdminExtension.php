<?php

declare(strict_types=1);

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;

final class WorkflowAdminExtension implements AdminExtensionInterface
{
	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create('Workflows', '/workflows/definitions', 'workflow', 75, [
				AdminMenuItem::create('Definitions', '/workflows/definitions', null, 76, [], 'ROLE_SITE_MANAGER'),
				AdminMenuItem::create('Task inbox', '/workflows/tasks', null, 77, [], 'ROLE_USER'),
			], 'ROLE_USER'),
		];
	}

	public function getResources(): array
	{
		return [];
	}

	public function getAdminScreens(): array
	{
		return [
			new AdminScreen(
				path: '/workflows/definitions',
				title: 'Workflow definitions',
				type: 'custom',
				component: 'WorkflowDefinitions',
				permission: 'ROLE_SITE_MANAGER',
			),
			new AdminScreen(
				path: '/workflows/tasks',
				title: 'Workflow task inbox',
				type: 'custom',
				component: 'WorkflowTasks',
				permission: 'ROLE_USER',
			),
		];
	}
}
