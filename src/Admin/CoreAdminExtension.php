<?php

namespace iikiti\CMS\Admin;

use iikiti\CMS\ApiResource\AdminApiResource;
use iikiti\CMS\ApiResource\AdminMenuItem;

/**
 * Core admin extension providing the built-in menu structure.
 *
 * Registers menu items for users, user groups, roles, applications, sites,
 * site groups, plugins, search, and audit logs. Plugins are discovered via
 * the plugin registry and contribute their own sections.
 */
class CoreAdminExtension implements AdminExtensionInterface
{
	public function getMenuItems(): array
	{
		return [
			AdminMenuItem::create('Dashboard', '/dashboard', 'layout-dashboard', 0),
			AdminMenuItem::create('Users', '/users', 'users', 100),
			AdminMenuItem::create('User Groups', '/user-groups', 'users', 90),
			AdminMenuItem::create('Roles & Permissions', '/roles', 'shield', 80),
			AdminMenuItem::create('Applications', '/applications', 'server', 70),
			AdminMenuItem::create('Sites', '/sites', 'globe', 60),
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
}
