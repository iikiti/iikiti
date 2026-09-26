<script lang="ts">
	import { onMount } from 'svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import type { PluginResource } from '../types/index';

	interface Props {
		api: any;
		debug?: boolean;
	}

	let { api, debug = false }: Props = $props();

	let store: PluginResource[] = $state([]);
	let loading = $state(true);
	let error: string | null = $state(null);

	async function load() {
		loading = true;
		error = null;
		try {
			store = await api.request('/api/store/plugins');
		} catch (e: any) {
			error = e.message ?? 'Failed to load plugin store';
		} finally {
			loading = false;
		}
	}

	onMount(load);
</script>

<PageHeader title="Plugin Store" description="Browse and install plugins from the iikiti store." />

{#if error}
	<div class="p-3 bg-danger-subtle text-danger rounded-lg">{error}</div>
{:else if loading}
	<LoadingState label="Loading plugin store…" />
{:else}
	<div class="text-text-muted">
		The plugin store is not configured. Set the store URL in `config/packages/plugins.yaml`.
	</div>
{/if}
