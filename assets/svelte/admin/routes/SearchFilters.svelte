<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import type { PagedResult } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let filters: PagedResult<any> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
		{ key: 'visibility', label: 'Visibility' },
	];

	async function load() {
		loading = true;
		error = null;
		try {
			filters = await api.request('/admin/search/filters');
			if (filters && !filters.members) {
				filters = api.parsePaged(filters);
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load search filters';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Search Filters" description="Manage custom search filters." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading search filters…" />
{:else if filters}
	<DataTable
		data={filters ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
