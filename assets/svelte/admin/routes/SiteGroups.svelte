<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import type { PagedResult, SiteGroupResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let groups: PagedResult<SiteGroupResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'name', label: 'Name' },
		{ key: 'label', label: 'Label' },
		{
			key: 'siteIds',
			label: 'Sites',
			render: (val: any) => Array.isArray(val) ? val.join(', ') : '',
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			groups = await api.getSiteGroups();
		} catch (e: any) {
			error = e.message ?? 'Failed to load site groups';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<div>
	<div class="mb-4">
		<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Site Groups</h2>
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
			Manage groups of sites for collective configuration.
		</p>
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
