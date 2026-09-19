<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
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

<div>
	<div class="mb-4">
		<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Users</h2>
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
			List of all user accounts in the system.
		</p>
	</div>

	{#if error}
		<div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg">{error}</div>
	{/if}

	<DataTable
		data={users ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={(row: UserResource) => {}}
		loading={loading}
	/>
</div>
