<script lang="ts">
	import { onMount } from 'svelte';
	import DetailView from '../components/DetailView.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import Breadcrumb from '../components/Breadcrumb.svelte';
	import Button from '../components/Button.svelte';
	import type { AdminScreen } from '$types';

	interface Props {
		api: any;
		debug?: boolean;
		screen: AdminScreen | null;
		id?: string | number;
	}

	let { api, debug = false, screen, id }: Props = $props();

	let record: any = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const fields = $derived(screen?.config?.fields ?? []);

	async function load() {
		if (!screen?.apiPath || !id) {
			error = 'No record ID provided.';
			loading = false;
			return;
		}

		loading = true;
		error = null;
		try {
			record = await api.request(`${screen.apiPath}/${id}`);
		} catch (e: any) {
			error = e.message ?? 'Failed to load record';
		} finally {
			loading = false;
		}
	}

	onMount(load);

	$effect(() => {
		if (screen && id) {
			load();
		}
	});
</script>

{#if !screen}
	<ErrorBoundary message="No screen configuration found." />
{:else}
	<div class="mb-4">
		<Breadcrumb
			items={[
				{ label: screen.title, path: screen.path },
				{ label: id ? `#${id}` : 'New' },
			]}
		/>
	</div>

	<PageHeader title={id ? `${screen.title} #${id}` : screen.title} description={screen.description} />

	{#if error}
		<ErrorBoundary message={error} onRetry={load} />
	{:else if loading}
		<LoadingState label={`Loading ${screen.title}…`} />
	{:else if record}
		<DetailView {fields} record={record} />
	{/if}
{/if}
