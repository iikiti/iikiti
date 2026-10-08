<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '$components/DataTable.svelte';
	import PageHeader from '$components/PageHeader.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import type { PagedResult, SiteGroupResource } from '$types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let groups: PagedResult<SiteGroupResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
		{ key: 'label', label: 'Label' },
		{
			key: 'siteIds',
			label: 'Sites',
			render: (val: any) => Array.isArray(val) ? val.join(', ') : '',
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			groups = await api.getSiteGroups();
		} catch (e: any) {
			error = e.message ?? 'Failed to load site groups';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Site Groups" description="Manage groups of sites for collective configuration." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading site groups…" />
{:else}
	<DataTable
		data={groups ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
