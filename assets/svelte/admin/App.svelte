<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClient } from '$lib/api';
	import AdminLayout from './components/AdminLayout.svelte';
	import DashboardRoute from './routes/Dashboard.svelte';
	import UsersRoute from './routes/Users.svelte';
	import UserGroupsRoute from './routes/UserGroups.svelte';
	import RolesRoute from './routes/Roles.svelte';
	import ApplicationsRoute from './routes/Applications.svelte';
	import SitesRoute from './routes/Sites.svelte';
	import SiteGroupsRoute from './routes/SiteGroups.svelte';
	import AuditLogRoute from './routes/AuditLog.svelte';
	import PluginsRoute from './routes/Plugins.svelte';
	import PluginStoreRoute from './routes/PluginStore.svelte';
	import SearchIndexesRoute from './routes/SearchIndexes.svelte';
	import SearchFiltersRoute from './routes/SearchFilters.svelte';
	import SearchSiteGroupsRoute from './routes/SearchSiteGroups.svelte';
	import GenericListPage from './routes/GenericListPage.svelte';
	import GenericDetailPage from './routes/GenericDetailPage.svelte';
	import GenericFormPage from './routes/GenericFormPage.svelte';
	import LoadingRoute from './routes/LoadingRoute.svelte';
	import NotFoundRoute from './routes/NotFoundRoute.svelte';
	import type { MenuItem, AdminScreen } from '$types';

	interface Props {
		apiToken: string;
		apiBase?: string;
		debug?: boolean;
	}

	let { apiToken, apiBase = '/api', debug = false }: Props = $props();

	const api = $derived(new ApiClient(apiToken, apiBase, debug));

	let currentPath = $state<string>(typeof window !== 'undefined' ? (window.location.hash.slice(1) || '/') : '/');
	let menu: MenuItem[] = $state<MenuItem[]>([]);
	let screens: AdminScreen[] = $state<AdminScreen[]>([]);
	let componentCache: Record<string, any> = $state<Record<string, any>>({});

	/**
	 * Core components that are statically bundled with the SPA.
	 * A screen's `component` field matching one of these keys is resolved
	 * directly — no dynamic import needed.
	 */
	const CORE_COMPONENTS: Record<string, any> = {
		Dashboard: DashboardRoute,
		Users: UsersRoute,
		UserGroups: UserGroupsRoute,
		Roles: RolesRoute,
		Applications: ApplicationsRoute,
		Sites: SitesRoute,
		SiteGroups: SiteGroupsRoute,
		AuditLog: AuditLogRoute,
		Plugins: PluginsRoute,
		PluginStore: PluginStoreRoute,
		SearchIndexes: SearchIndexesRoute,
		SearchFilters: SearchFiltersRoute,
		SearchSiteGroups: SearchSiteGroupsRoute,
	};

	const CurrentComponent = $derived.by(() => {
		const screen = screens.find((s) => s.path === currentPath);
		if (!screen) {
			return NotFoundRoute;
		}

		// Plugin-provided custom component (loaded via dynamic import)
		if (screen.bundle && componentCache[screen.path]) {
			return componentCache[screen.path];
		}

		// Core static component resolved by name
		if (!screen.bundle && screen.component && CORE_COMPONENTS[screen.component]) {
			return CORE_COMPONENTS[screen.component];
		}

		// Generic metadata-driven component based on screen type
		if (!screen.bundle) {
			switch (screen.type) {
				case 'list':
					return GenericListPage;
				case 'detail':
					return GenericDetailPage;
				case 'form':
					return GenericFormPage;
			}
		}

		return LoadingRoute;
	});

	/**
	 * The screen descriptor for the current path, passed to generic routes.
	 */
	const currentScreen = $derived.by(() => {
		return screens.find((s) => s.path === currentPath) ?? null;
	});

	async function resolveScreen(screen: AdminScreen): Promise<void> {
		if (!screen.bundle || !screen.component) {
			return;
		}

		if (componentCache[screen.path]) {
			return;
		}

		try {
			const mod = await import(/* webpackIgnore: true */ screen.bundle);
			componentCache[screen.path] = mod[screen.component];
		} catch {
			componentCache[screen.path] = null;
		}
	}

	function navigateTo(path: string) {
		if (typeof window !== 'undefined') {
			window.location.hash = path;
		}
	}

	onMount(async () => {
		try {
			menu = await api.getMenu();
		} catch {
			menu = [];
		}

		try {
			screens = await api.getScreens();
		} catch {
			screens = [];
		}

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

	// When the path changes, kick off the dynamic import for custom screens
	$effect(() => {
		const screen = screens.find((s) => s.path === currentPath);
		if (screen && screen.bundle && !componentCache[screen.path]) {
			resolveScreen(screen);
		}
	});
</script>

<AdminLayout {menu} {api} {debug} {currentPath} onNavigate={navigateTo}>
	{#if CurrentComponent === NotFoundRoute}
		<CurrentComponent currentPath={currentPath} />
	{:else if CurrentComponent === GenericListPage || CurrentComponent === GenericDetailPage || CurrentComponent === GenericFormPage}
		<CurrentComponent {api} {debug} screen={currentScreen} />
	{:else}
		<CurrentComponent {api} {debug} />
	{/if}
</AdminLayout>
