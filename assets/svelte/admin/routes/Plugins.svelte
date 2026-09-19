<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import type { PagedResult, PluginResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let plugins: PagedResult<PluginResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'name', label: 'Name' },
		{ key: 'slug', label: 'Slug' },
		{ key: 'version', label: 'Version' },
		{
			key: 'enabled',
			label: 'Enabled',
			render: (val: any) => (val ? 'Yes' : 'No'),
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			plugins = await api.request('/admin/plugins');
			if (plugins && !plugins.members) {
				plugins = api.parsePaged(plugins);
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load plugins';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Plugins" description="Manage installed plugins and browse the store." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading plugins…" />
{:else if plugins}
	<DataTable
		data={plugins ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
