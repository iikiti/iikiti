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

	let groups: PagedResult<any> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
	];

	async function load() {
		loading = true;
		error = null;
		try {
			groups = await api.request('/admin/search/site-groups');
			if (groups && !groups.members) {
				groups = api.parsePaged(groups);
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load search site groups';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Search Site Groups" description="Manage search configuration site groups." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading search site groups…" />
{:else if groups}
	<DataTable
		data={groups ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
