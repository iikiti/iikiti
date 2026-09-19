<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
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
		} catch (e) {
			error = e.message ?? 'Failed to load applications';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<div>
	<div class="mb-4">
		<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Applications</h2>
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
			Manage application-level configurations.
		</p>
	</div>

	{#if error}
		<div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg">{error}</div>
	{/if}

	<DataTable
		data={apps ?? { members: [], totalItems: 0, itemsPerPage: 25, currentPage: 1 }}
		{columns}
		onRowClick={() => {}}
		loading={loading}
	/>
</div>
