<script lang="ts">
	import { onMount, type Component } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import EmptyState from '../components/EmptyState.svelte';
	import Pagination from '../components/Pagination.svelte';
	import SearchForm from '../components/SearchForm.svelte';
	import type { AdminScreen, PagedResult } from '$types';

	interface Props {
		api: any;
		debug?: boolean;
		screen: AdminScreen | null;
	}

	let { api, debug = false, screen }: Props = $props();

	let data: PagedResult<any> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = $derived(screen?.config?.columns ?? []);

	async function load() {
		if (!screen?.apiPath) {
			error = 'No API path configured for this screen.';
			loading = false;
			return;
		}

		loading = true;
		error = null;
		try {
			data = await api.request(screen.apiPath);
			if (data && !data.members) {
				data = api.parsePaged(data);
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load data';
		} finally {
			loading = false;
		}
	}

	onMount(load);

	$effect(() => {
		if (screen) {
			load();
		}
	});
</script>

{#if !screen}
	<ErrorBoundary message="No screen configuration found." />
{:else}
	<PageHeader title={screen.title} description={screen.description} />

	{#if error}
		<ErrorBoundary message={error} onRetry={load} />
	{:else if loading}
		<LoadingState label={`Loading ${screen.title}…`} />
	{:else if data && data.totalItems === 0}
		<EmptyState title={`No ${screen.title.toLowerCase()} found`} description="There are no records to display yet." />
	{:else if data}
		<DataTable
			data={data}
			{columns}
			onRowClick={() => {}}
		/>
		<Pagination
			currentPage={data.currentPage}
			totalPages={Math.ceil(data.totalItems / data.itemsPerPage)}
			totalItems={data.totalItems}
			itemsPerPage={data.itemsPerPage}
		/>
	{/if}
{/if}
