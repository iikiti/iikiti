<script lang="ts">
	import { onMount } from 'svelte';
	import type { RoleResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let roles: RoleResource[] = $state([]);
	let loading = $state(true);
	let error: string | null = $state(null);

	async function load() {
		loading = true;
		error = null;
		try {
			roles = await api.getRoles();
		} catch (e: any) {
			error = e.message ?? 'Failed to load roles';
		} finally {
			loading = false;
		}
	}

	onMount(load);

	function toggleHidden(role: RoleResource) {
		const updated = { ...role, isHidden: !role.isHidden };
		api.updateRole(role.id, updated).then(() => {
			roles = roles.map((r) => (r.id === role.id ? updated : r));
		});
	}

	function permissionString(perms: Record<string, string[]>): string {
		return Object.entries(perms)
			.map(([type, actions]) => `${type}: ${actions.join(', ')}`)
			.join('; ');
	}
</script>

<div>
	<div class="mb-4">
		<h2 class="text-xl font-semibold text-gray-900 dark:text-white">Roles & Permissions</h2>
		<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
			Manage role definitions and their permission templates. Default permissions
			are immutable; only custom permissions can be modified.
		</p>
	</div>

	{#if error}
		<div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg">{error}</div>
	{/if}

	{#if loading}
		<div class="text-center py-8 text-gray-500">Loading…</div>
	{:else}
		<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
			<table class="admin-table min-w-full divide-y divide-gray-200 dark:divide-gray-700">
				<thead>
					<tr>
						<th class="px-4 py-2">Role</th>
						<th class="px-4 py-2">Value</th>
						<th class="px-4 py-2">Default Permissions</th>
						<th class="px-4 py-2">Custom Permissions</th>
						<th class="px-4 py-2">System</th>
						<th class="px-4 py-2">Hidden</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-200 dark:divide-gray-700">
					{#each roles as role (role.id)}
						<tr>
							<td class="px-4 py-2 font-medium">{role.name}</td>
							<td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{role.value}</td>
							<td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
								{permissionString(role.defaultPermissions)}
							</td>
							<td class="px-4 py-2">
								<button
									class="text-xs text-blue-600 hover:text-blue-800"
									onclick={() => api.updateRole(role.id, { ...role, customPermissions: { ...role.customPermissions, profile: ['read'] } })}
								>
									Edit custom permissions
								</button>
							</td>
							<td class="px-4 py-2">{role.isDefault ? 'Yes' : 'No'}</td>
							<td class="px-4 py-2">
								{#if !role.isDefault}
									<button
										class="text-xs text-gray-600 hover:text-gray-800"
										onclick={() => toggleHidden(role)}
									>
										{role.isHidden ? 'Show' : 'Hide'}
									</button>
								{:else}
									—
								{/if}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</div>
