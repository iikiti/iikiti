<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import type { PagedResult, ApplicationResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let apps: PagedResult<ApplicationResource> | null = $state(null);
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
			apps = await api.getApplications();
		} catch (e: any) {
			error = e.message ?? 'Failed to load applications';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Applications" description="Manage application-level configurations." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading applications…" />
{:else}
	<DataTable
		data={apps ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
