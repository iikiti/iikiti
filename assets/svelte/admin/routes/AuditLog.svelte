<script lang="ts">
	import { onMount } from 'svelte';
	import DataTable from '../components/DataTable.svelte';
	import PageHeader from '../components/PageHeader.svelte';
	import LoadingState from '../components/LoadingState.svelte';
	import ErrorBoundary from '../components/ErrorBoundary.svelte';
	import type { PagedResult, AuditLogResource } from '../types/index';

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

<PageHeader title="Audit Log" description="Records all administrative and system actions for audit purposes." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading audit log…" />
{:else if logs}
	<DataTable
		data={logs}
		{columns}
		onRowClick={() => {}}
	/>
{/if}
