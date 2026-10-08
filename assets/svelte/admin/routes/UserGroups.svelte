<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '$components/DataTable.svelte';
	import PageHeader from '$components/PageHeader.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import type { PagedResult, UserGroupResource } from '$types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let groups: PagedResult<UserGroupResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
		{ key: 'label', label: 'Label' },
		{
			key: 'isSystem',
			label: 'System',
			render: (val: any) => (val ? 'Yes' : 'No'),
		},
		{
			key: 'isHidden',
			label: 'Hidden',
			render: (val: any) => (val ? 'Yes' : 'No'),
		},
		{
			key: 'userCount',
			label: 'Members',
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			groups = await api.getUserGroups();
		} catch (e: any) {
			error = e.message ?? 'Failed to load user groups';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="User Groups" description="Manage user groups for collective permission management." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading user groups…" />
{:else}
	<DataTable
		data={groups ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
