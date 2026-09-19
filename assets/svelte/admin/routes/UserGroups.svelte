<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import type { PagedResult, UserGroupResource } from '../types/index';

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

<div>
	<div class="mb-4 flex justify-between items-center">
		<div>
			<h2 class="text-xl font-semibold text-gray-900 dark:text-white">User Groups</h2>
			<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
				Manage user groups for collective permission management.
			</p>
		</div>
	</div>

	{#if error}
		<div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg">{error}</div>
	{/if}

	<DataTable
		data={groups ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
		loading={loading}
	/>
</div>
