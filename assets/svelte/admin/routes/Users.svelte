<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import type { PagedResult, UserResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let users: PagedResult<UserResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'username', label: 'Username' },
		{
			key: 'emails',
			label: 'Email',
			render: (val: any) => Array.isArray(val) ? val.join(', ') : String(val ?? ''),
		},
		{
			key: 'groupIds',
			label: 'Groups',
			render: (val: any) => Array.isArray(val) ? val.join(', ') : '',
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			users = await api.getUsers();
		} catch (e: any) {
			error = e.message ?? 'Failed to load users';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Users" description="List of all user accounts in the system." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading users…" />
{:else}
	<DataTable
		data={users ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={(_row: UserResource) => {}}
	/>
{/if}
