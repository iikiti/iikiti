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

	let indexes: PagedResult<any> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
		{ key: 'handle', label: 'Handle' },
		{ key: 'status', label: 'Status' },
	];

	async function load() {
		loading = true;
		error = null;
		try {
			indexes = await api.request('/admin/search/indexes');
			if (indexes && !indexes.members) {
				indexes = api.parsePaged(indexes);
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load search indexes';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Search Indexes" description="Manage search index configurations." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading search indexes…" />
{:else if indexes}
	<DataTable
		data={indexes ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
