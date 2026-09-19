<script lang="ts">
	import { onMount } from 'svelte';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let summary: Record<string, any> | null = $state(null);
	let loading = $state(true);

	async function load() {
		loading = true;
		try {
			const users = await api.getUsers();
			const roles = await api.getRoles();
			const siteGroups = await api.getSiteGroups();
			summary = {
				userCount: users.totalItems,
				roleCount: roles.length,
				siteGroupCount: siteGroups.totalItems,
			};
		} catch (e: any) {
			summary = null;
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

{#if loading}
	<div class="text-center py-8 text-gray-500">Loading dashboard…</div>
{:else if summary}
	<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
		<div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
			<span class="text-2xl font-bold text-gray-900 dark:text-white">{summary.userCount}</span>
			<p class="text-sm text-gray-500 dark:text-gray-400">Users</p>
		</div>
		<div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
			<span class="text-2xl font-bold text-gray-900 dark:text-white">{summary.roleCount}</span>
			<p class="text-sm text-gray-500 dark:text-gray-400">Roles</p>
		</div>
		<div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
			<span class="text-2xl font-bold text-gray-900 dark:text-white">{summary.siteGroupCount}</span>
			<p class="text-sm text-gray-500 dark:text-gray-400">Site Groups</p>
		</div>
	</div>
{/if}
