<script lang="ts">
	import { onMount } from 'svelte';
	import { ApiClient } from '$lib/api';
	import { findScreenByPath, getRouteId } from '$lib/router';
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
		currentUser?: string;
		debug?: boolean;
	}

	let { apiToken, apiBase = '/api', currentUser = '', debug = false }: Props = $props();

	const api = $derived(new ApiClient(apiToken, apiBase, debug));

	let currentPath = $state<string>(typeof window !== 'undefined' ? (window.location.hash.slice(1) || '/') : '/');
	let menu: MenuItem[] = $state<MenuItem[]>([]);
	let screens: AdminScreen[] = $state<AdminScreen[]>([]);
	let componentCache: Record<string, any> = $state<Record<string, any>>({});
	let loading = $state(true);

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

	// Match against the pathname only so dynamic-id screens (e.g.
	// `/admin/templates/edit?id=26`) resolve to their declared `path`.
	const currentScreen = $derived.by(() => findScreenByPath(screens, currentPath) ?? null);
	/** id query param for detail/form screens (`?id=N`), or null. */
	const currentId = $derived.by(() => getRouteId(currentPath));

	const CurrentComponent = $derived.by(() => {
		const screen = currentScreen;
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

		loading = false;

		// Default to the first menu entry (the Dashboard) when no route is
		// selected so a bare /admin never lands on "Page Not Found".
		if (typeof window !== 'undefined' && (window.location.hash === '' || window.location.hash === '#' || window.location.hash === '#/')) {
			const fallback = menu.find((item) => item.path)?.path ?? '/dashboard';
			window.location.hash = fallback;
			currentPath = fallback;
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
		const screen = currentScreen;
		if (screen && screen.bundle && !componentCache[screen.path]) {
			resolveScreen(screen);
		}
	});
</script>

{#if loading}
	<div class="fixed inset-0 flex items-center justify-center bg-bg z-50">
		<div class="text-center">
			<div class="animate-spin rounded-full h-10 w-10 border-2 border-transparent border-t-accent mb-4"></div>
			<p class="text-text-muted">Loading administration…</p>
		</div>
	</div>
{:else}
		<AdminLayout {menu} {api} {debug} {currentUser} {currentPath} onNavigate={navigateTo}>
		{#if CurrentComponent === NotFoundRoute}
			<CurrentComponent currentPath={currentPath} />
		{:else if CurrentComponent === GenericListPage}
			<CurrentComponent {api} {debug} screen={currentScreen} onRowClick={(row: any) => {
				const editPath = currentScreen?.config?.editPath;
				if (editPath && row?.id != null) {
					navigateTo(`${editPath}?id=${row.id}`);
				}
			}} />
		{:else if CurrentComponent === GenericFormPage || CurrentComponent === GenericDetailPage}
			<CurrentComponent {api} {debug} screen={currentScreen} id={currentId} />
		{:else}
			<CurrentComponent {api} {debug} />
		{/if}
	</AdminLayout>
{/if}
