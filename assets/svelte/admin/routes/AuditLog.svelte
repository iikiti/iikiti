<script lang="ts">
	import { onMount } from 'svelte';
	import type { AuditLogResource, PagedResult } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let logs: PagedResult<AuditLogResource> | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'id', label: 'ID' },
		{ key: 'action', label: 'Action' },
		{ key: 'objectType', label: 'Object Type' },
		{ key: 'objectId', label: 'Object ID' },
		{ key: 'userId', label: 'User ID' },
		{
			key: 'createdAt',
			label: 'Timestamp',
		},
	];

	async function load() {
		loading = true;
		error = null;
		try {
			logs = await api.getAuditLogs();
		} catch (e: any) {
			error = e.message ?? 'Failed to load audit log';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<div>
	<div class="mb-4">
		<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Audit Log</h2>
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
			Records all administrative and system actions for audit purposes.
		</p>
	</div>

	{#if error}
		<div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg">{error}</div>
	{/if}

	{#if loading}
		<div class="text-center py-8 text-gray-500">Loading…</div>
	{:else if logs}
		<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
			<table class="admin-table min-w-full divide-y divide-gray-200 dark:divide-gray-700">
				<thead>
					<tr>
						<th class="px-4 py-2">ID</th>
						<th class="px-4 py-2">Action</th>
						<th class="px-4 py-2">Object Type</th>
						<th class="px-4 py-2">Object ID</th>
						<th class="px-4 py-2">User ID</th>
						<th class="px-4 py-2">When</th>
						<th class="px-4 py-2">Details</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
					{#each logs.members as entry (entry.id)}
						<tr>
							<td class="px-4 py-2 text-sm">{entry.id}</td>
							<td class="px-4 py-2">
								<span class="admin-badge admin-badge-system">{entry.action}</span>
							</td>
							<td class="px-4 py-2 text-sm">{entry.objectType}</td>
							<td class="px-4 py-2 text-sm">{entry.objectId ?? '—'}</td>
							<td class="px-4 py-2 text-sm">{entry.userId ?? '—'}</td>
							<td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
								{new Date(entry.createdAt).toLocaleString()}
							</td>
							<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
								{entry.ipAddress ?? '—'}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</div>
