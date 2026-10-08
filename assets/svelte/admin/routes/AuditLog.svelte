<script lang="ts">
	import DataTable from '$components/DataTable.svelte';
	import PageHeader from '$components/PageHeader.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import DateTime from '$components/DateTime.svelte';
	import { ensureTemporal, formatDateTime, hasNativeTemporal } from '$iikiti/time/temporal.js';
	import type { PagedResult, AuditLogResource } from '$types/index';

	interface Props {
		api: any;
		debug?: boolean;
		/** Entry id from `#/audit-log?id=N`; null shows the list. */
		id?: string | null;
	}

	let { api, debug = false, id = null }: Props = $props();

	const LIST_PATH = '/audit-log';

	let logs: PagedResult<AuditLogResource> | null = $state(null);
	let entry: AuditLogResource | null = $state(null);
	let loading = $state(true);
	let error: string | null = $state(null);

	const columns = [
		{ key: 'summary', label: 'Event' },
		{ key: 'username', label: 'User' },
		{ key: 'createdAt', label: 'Timestamp', render: (value: string) => formatTimestamp(value) },
	];

	let temporalReady = $state(hasNativeTemporal());

	// Load the polyfill only if the browser lacks Temporal; re-render once ready.
	$effect(() => {
		if (!temporalReady) {
			ensureTemporal().then(() => (temporalReady = true)).catch(() => {});
		}
	});

	/** Plain-text timestamp for list cells; falls back to the ISO string until Temporal is ready. */
	function formatTimestamp(value: string | null | undefined): string {
		if (!value) return '';
		if (!temporalReady) return value;
		try {
			return formatDateTime(value, { kind: 'datetime', style: 'medium' });
		} catch {
			return value;
		}
	}

	/**
	 * Real steps only: drops any sub-event that just repeats its parent's
	 * summary, which older rows recorded as a placeholder.
	 */
	const steps = $derived.by(() => {
		if (!entry) return [];
		return entry.subEvents.filter((step) => step.summary !== entry?.summary);
	});

	/** Treats a missing key and a null value the same way. */
	function hasValue(value: unknown): boolean {
		return value !== null && value !== undefined;
	}

	/**
	 * Formats the initiator as "username (id N)". The live username is used when
	 * the user still exists; otherwise the snapshot stored at log time.
	 */
	function initiator(item: AuditLogResource): string {
		if (item.username === null && item.userId === null) {
			return 'System';
		}
		const name = item.username ?? 'Deleted user';
		return item.userId !== null ? `${name} (id ${item.userId})` : name;
	}

	function openEntry(row: AuditLogResource) {
		window.location.hash = `${LIST_PATH}?id=${row.id}`;
	}

	function backToList() {
		window.location.hash = LIST_PATH;
	}

	async function loadList() {
		logs = await api.getAuditLogs();
	}

	async function loadEntry(entryId: string) {
		entry = await api.getAuditLog(entryId);
		if (!entry) {
			error = `Audit entry ${entryId} was not found.`;
		}
	}

	async function load() {
		loading = true;
		error = null;
		entry = null;
		try {
			if (id) {
				await loadEntry(id);
			} else {
				await loadList();
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load audit log';
		} finally {
			loading = false;
		}
	}

	// Re-run when the hash changes between list and detail (or between entries).
	$effect(() => {
		void id;
		load();
	});

</script>

<PageHeader title="Audit Log" description="Records all administrative and system actions for audit purposes." />

{#if error}
	<ErrorBoundary message={error} onRetry={load} />
{:else if loading}
	<LoadingState label="Loading audit log…" />
{:else if id && entry}
	<div class="mb-4">
		<button type="button" class="admin-link" onclick={backToList}>← Back to audit log</button>
	</div>

	<section class="admin-card space-y-6 p-6">
		<h2 class="text-lg font-semibold">{entry.summary ?? 'Audit event'}</h2>

		<dl class="grid grid-cols-[max-content_1fr] gap-x-8 gap-y-3 text-sm">
			<dt class="font-semibold">Entry ID</dt>
			<dd>{entry.id}</dd>

			<dt class="font-semibold">Initiated by</dt>
			<dd>{initiator(entry)}</dd>

			<dt class="font-semibold">When</dt>
			<dd><DateTime value={entry.createdAt} kind="datetime" style="long" /></dd>

			<dt class="font-semibold">Action</dt>
			<dd>{entry.action} · {entry.objectType}{hasValue(entry.objectId) ? ` #${entry.objectId}` : ''}</dd>

			{#if entry.ipAddress}
				<dt class="font-semibold">IP address</dt>
				<dd>{entry.ipAddress}</dd>
			{/if}

			{#if entry.requestUri}
				<dt class="font-semibold">Request</dt>
				<dd>{entry.requestUri}</dd>
			{/if}
		</dl>

		<h3 class="font-semibold">Steps ({steps.length})</h3>
		{#if steps.length > 0}
			<ul class="divide-y divide-border">
				{#each steps as step (step.id)}
					<li class="py-3 px-1">
						<div class="font-medium">{step.summary}</div>
						<div class="text-sm text-text-muted">
							{step.action} · {step.objectType}{hasValue(step.objectId) ? ` #${step.objectId}` : ''}
						</div>
						{#if debug && (step.beforeState || step.afterState)}
							<pre class="text-xs mt-1 overflow-x-auto">{JSON.stringify({ before: step.beforeState, after: step.afterState }, null, 2)}</pre>
						{/if}
					</li>
				{/each}
			</ul>
		{/if}
	</section>
{:else if logs}
	<DataTable data={logs} {columns} onRowClick={openEntry} />
{/if}
