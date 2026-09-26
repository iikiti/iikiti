<script lang="ts">
	import { onMount } from 'svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
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

<PageHeader
	title="Roles & Permissions"
	description="Manage role definitions and their permission templates. Default permissions are immutable; only custom permissions can be modified."
/>

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading roles…" />
{:else}
	<div class="overflow-x-auto rounded-lg border border-border">
		<table class="admin-table min-w-full divide-y divide-border">
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
			<tbody class="divide-y divide-border">
				{#each roles as role (role.id)}
					<tr>
						<td class="px-4 py-2 font-medium text-text">{role.name}</td>
						<td class="px-4 py-2 text-sm text-text-muted">{role.value}</td>
						<td class="px-4 py-2 text-xs text-text-muted">
							{permissionString(role.defaultPermissions)}
						</td>
						<td class="px-4 py-2">
							<button
								class="text-xs text-accent hover:text-accent-hover"
								onclick={() => api.updateRole(role.id, { ...role, customPermissions: { ...role.customPermissions, profile: ['read'] } })}
							>
								Edit custom permissions
							</button>
						</td>
						<td class="px-4 py-2">{role.isDefault ? 'Yes' : 'No'}</td>
						<td class="px-4 py-2">
							{#if !role.isDefault}
								<button
									class="text-xs text-text-muted hover:text-text"
									onclick={() => toggleHidden(role)}
								>
									{role.isHidden ? 'Show' : 'Hide'}
								</button>
							{:else}
								&mdash;
							{/if}
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
{/if}
