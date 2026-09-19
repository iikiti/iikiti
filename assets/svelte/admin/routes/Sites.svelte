<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import type { PagedResult, SiteResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let sites: PagedResult<SiteResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'domain', label: 'Domain' },
		{ key: 'name', label: 'Name' },
	];

	async function load() {
		loading = true;
		error = null;
		try {
			sites = await api.getSites();
		} catch (e: any) {
			error = e.message ?? 'Failed to load sites';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Sites" description="Manage site configurations and domain mappings." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading sites…" />
{:else}
	<DataTable
		data={sites ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
