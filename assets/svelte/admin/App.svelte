<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClient } from '$lib/api';
	import AdminLayout from './components/AdminLayout.svelte';
	import UsersRoute from './routes/Users.svelte';
	import UserGroupsRoute from './routes/UserGroups.svelte';
	import RolesRoute from './routes/Roles.svelte';
	import ApplicationsRoute from './routes/Applications.svelte';
	import SitesRoute from './routes/Sites.svelte';
	import SiteGroupsRoute from './routes/SiteGroups.svelte';
	import AuditLogRoute from './routes/AuditLog.svelte';
	import DashboardRoute from './routes/Dashboard.svelte';
	import type { MenuItem } from '$types';

	interface Props {
		apiToken: string;
		apiBase?: string;
		debug?: boolean;
	}

	let { apiToken, apiBase = '/api', debug = false }: Props = $props();

	const api = $derived(new ApiClient(apiToken, apiBase, debug));

	let currentPath = $state<string>(typeof window !== 'undefined' ? (window.location.hash.slice(1) || '/') : '/');
	let menu: MenuItem[] = $state<MenuItem[]>([]);

	const ROUTES: Record<string, any> = {
		'/': DashboardRoute,
		'/dashboard': DashboardRoute,
		'/users': UsersRoute,
		'/user-groups': UserGroupsRoute,
		'/roles': RolesRoute,
		'/applications': ApplicationsRoute,
		'/sites': SitesRoute,
		'/site-groups': SiteGroupsRoute,
		'/audit-log': AuditLogRoute,
	};

	let CurrentComponent = $derived<any>(ROUTES[currentPath] ?? DashboardRoute);

	function navigateTo(path: string) {
		if (typeof window !== 'undefined') {
			window.location.hash = path;
		}
	}

	onMount(async () => {
		menu = await api.getMenu();

		if (typeof window !== 'undefined') {
			const handler = () => {
				currentPath = window.location.hash.slice(1) || '/';
			};
			window.addEventListener('hashchange', handler);

			return () => {
				window.removeEventListener('hashchange', handler);
			};
		}
	});
</script>

<AdminLayout {menu} {api} {debug} onNavigate={navigateTo}>
	{@render CurrentComponent()}
</AdminLayout>
