<?php

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;
use iikiti\CMS\ApiResource\AdminScreen;

/**
 * Core admin extension providing the built-in menu structure and screen
 * descriptors.
 *
 * Registers menu items for users, user groups, roles, applications, sites,
 * site groups, plugins, search, and audit logs. Plugins are discovered via
 * the plugin registry and contribute their own sections.
 */
class CoreAdminExtension implements AdminExtensionInterface
{
	use AdminExtensionTrait;

	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create('Dashboard', '/dashboard', 'layout-dashboard', 0),
			AdminMenuItem::create('Users', '/users', 'users', 100),
			AdminMenuItem::create('User Groups', '/user-groups', 'users', 90),
			AdminMenuItem::create('Roles & Permissions', '/roles', 'shield', 80),
			AdminMenuItem::create('Applications', '/applications', 'server', 70),
			AdminMenuItem::create('Sites', '/sites', 'globe', 60),
			AdminMenuItem::create('Templates', '/admin/templates', 'layout-template', 35),
			AdminMenuItem::create('Site Groups', '/site-groups', 'layers', 50),
			AdminMenuItem::create('Plugins', '/plugins', 'puzzle', 40, [
				AdminMenuItem::create('Installed', '/plugins'),
				AdminMenuItem::create('Store', '/plugins/store'),
			]),
			AdminMenuItem::create('Search', '/search', 'search', 30, [
				AdminMenuItem::create('Indexes', '/search/indexes'),
				AdminMenuItem::create('Filters', '/search/filters'),
				AdminMenuItem::create('Site Groups', '/search/site-groups'),
			]),
			AdminMenuItem::create('Audit Log', '/audit-log', 'history', 20),
		];
	}

	public function getResources(): array
	{
		return [
			AdminApiResource::create('users', '/api/admin/users', 'Users'),
			AdminApiResource::create('user-groups', '/api/admin/user-groups', 'User Groups'),
			AdminApiResource::create('roles', '/api/admin/roles', 'Roles'),
			AdminApiResource::create('applications', '/api/applications', 'Applications'),
			AdminApiResource::create('sites', '/api/sites', 'Sites'),
			AdminApiResource::create('site-groups', '/api/admin/site-groups', 'Site Groups'),
			AdminApiResource::create('plugins', '/api/admin/plugins', 'Plugins'),
			AdminApiResource::create('search', '/api/admin/search/indexes', 'Search'),
			AdminApiResource::create('audit-logs', '/api/admin/audit-logs', 'Audit Log'),
		];
	}

	public function getAdminScreens(): array
	{
		return [
			new AdminScreen(path: '/dashboard', title: 'Dashboard', type: 'custom', component: 'Dashboard', description: 'System overview and quick actions'),
			new AdminScreen(path: '/users', title: 'Users', type: 'list', apiPath: '/admin/users', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'username', 'label' => 'Username'],
					['key' => 'emails', 'label' => 'Email'],
					['key' => 'groupIds', 'label' => 'Groups'],
				],
			]),
			new AdminScreen(path: '/user-groups', title: 'User Groups', type: 'list', apiPath: '/admin/user-groups', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
					['key' => 'label', 'label' => 'Label'],
					['key' => 'isSystem', 'label' => 'System'],
					['key' => 'isHidden', 'label' => 'Hidden'],
					['key' => 'userCount', 'label' => 'Members'],
				],
			]),
			new AdminScreen(path: '/roles', title: 'Roles & Permissions', type: 'custom', component: 'Roles'),
			new AdminScreen(path: '/applications', title: 'Applications', type: 'list', apiPath: '/applications', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
				],
			]),
			new AdminScreen(path: '/sites', title: 'Sites', type: 'list', apiPath: '/sites', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'domain', 'label' => 'Domain'],
					['key' => 'name', 'label' => 'Name'],
				],
			]),
			new AdminScreen(path: '/admin/templates', title: 'Templates', type: 'list', apiPath: '/api/admin/templates', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'title', 'label' => 'Title'],
					['key' => 'layout', 'label' => 'Layout'],
				],
			]),
			new AdminScreen(path: '/site-groups', title: 'Site Groups', type: 'list', apiPath: '/admin/site-groups', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
					['key' => 'label', 'label' => 'Label'],
					['key' => 'siteIds', 'label' => 'Sites'],
				],
			]),
			new AdminScreen(path: '/plugins', title: 'Plugins', type: 'custom', component: 'Plugins'),
			new AdminScreen(path: '/plugins/store', title: 'Plugin Store', type: 'custom', component: 'PluginStore'),
			new AdminScreen(path: '/search/indexes', title: 'Search Indexes', type: 'list', apiPath: '/admin/search/indexes', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
					['key' => 'handle', 'label' => 'Handle'],
					['key' => 'status', 'label' => 'Status'],
				],
			]),
			new AdminScreen(path: '/search/filters', title: 'Search Filters', type: 'list', apiPath: '/admin/search/filters', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
					['key' => 'visibility', 'label' => 'Visibility'],
				],
			]),
			new AdminScreen(path: '/search/site-groups', title: 'Search Site Groups', type: 'list', apiPath: '/admin/search/site-groups', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'name', 'label' => 'Name'],
				],
			]),
			new AdminScreen(path: '/audit-log', title: 'Audit Log', type: 'list', apiPath: '/admin/audit-logs', config: [
				'columns' => [
					['key' => 'id', 'label' => 'ID'],
					['key' => 'action', 'label' => 'Action'],
					['key' => 'objectType', 'label' => 'Object Type'],
					['key' => 'objectId', 'label' => 'Object ID'],
					['key' => 'userId', 'label' => 'User ID'],
					['key' => 'createdAt', 'label' => 'Timestamp'],
				],
			]),
		];
	}
}
