<script lang="ts">
	import type { ApiClient } from '$lib/api';
	import Badge from './Badge.svelte';

	interface BatchJob {
		id: string;
		name: string;
		status: string;
		total: number;
		terminal: number;
		percent: number | null;
		totalKnown: boolean;
		taskKey: string | null;
	}

	interface BatchEvent {
		id: string;
		type: string;
		summary: string;
		createdAt: string;
	}

	interface BatchItem {
		id: string;
		key: string;
		type: string;
		summary: string;
		state: string;
		attempts: number;
		error: string | null;
	}

	interface Props {
		api: ApiClient;
		batchId: string | number;
	}

	let { api, batchId }: Props = $props();
	let job = $state<BatchJob | null>(null);
	let events = $state<BatchEvent[]>([]);
	let items = $state<BatchItem[]>([]);
	let mode = $state<'poll' | 'stream'>('stream');
	let error = $state('');
	let lastEventId = $state('0');
	let loading = $state(true);
	let refreshedToken = $state('');
	let savingToken = $state(false);

	const terminalStatuses = new Set(['completed', 'completed_with_errors', 'failed', 'cancelled']);

	async function refresh(): Promise<void> {
		const [snapshot, itemResponse] = await Promise.all([
			api.request(`/admin/batches/${batchId}`),
			api.request(`/admin/batches/${batchId}/items?limit=50`),
		]);
		job = snapshot;
		items = itemResponse.items ?? [];
		loading = false;
		loading = false;
	}

	async function refreshCredentials(): Promise<void> {
		if (!refreshedToken || !job) return;
		savingToken = true;
		error = '';
		try {
			await api.request(`/admin/site-transfer/imports/${job.id}/credentials`, { method: 'POST', body: JSON.stringify({ sourceToken: refreshedToken }) });
			refreshedToken = '';
			await refresh();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Source credentials were rejected.';
		} finally {
			savingToken = false;
		}
	}

	function addEvents(next: BatchEvent[]): void {
		if (next.length === 0) return;
		const known = new Set(events.map((event) => event.id));
		const added = next.filter((event) => !known.has(event.id));
		if (added.length > 0) {
			events = [...events, ...added].slice(-100);
			lastEventId = added[added.length - 1].id;
		}
	}

	async function poll(signal: AbortSignal): Promise<void> {
		while (!signal.aborted && !terminalStatuses.has(job?.status ?? '')) {
			const response = await api.request(`/admin/batches/${batchId}/events?mode=poll&after=${encodeURIComponent(lastEventId)}`, { signal });
			job = response.job;
			addEvents(response.events ?? []);
			await refresh();
			await new Promise((resolve) => setTimeout(resolve, 2500));
		}
	}

	function parseFrame(frame: string): { id: string; event: string; data: string } | null {
		let id = '';
		let event = 'message';
		const data: string[] = [];
		for (const line of frame.split(/\r?\n/)) {
			if (line.startsWith('id:')) id = line.slice(3).trim();
			else if (line.startsWith('event:')) event = line.slice(6).trim();
			else if (line.startsWith('data:')) data.push(line.slice(5).trimStart());
		}
		return data.length > 0 ? { id, event, data: data.join('\n') } : null;
	}

	async function stream(signal: AbortSignal): Promise<void> {
		while (!signal.aborted && !terminalStatuses.has(job?.status ?? '')) {
			const reader = await api.openStream(`/admin/batches/${batchId}/events?mode=stream&after=${encodeURIComponent(lastEventId)}`, { signal, lastEventId });
			const decoder = new TextDecoder();
			let buffer = '';
			try {
				while (!signal.aborted && !terminalStatuses.has(job?.status ?? '')) {
					const { value, done } = await reader.read();
					if (done) break;
					buffer = (buffer + decoder.decode(value, { stream: true })).replace(/\r\n/g, '\n');
					let boundary = buffer.indexOf('\n\n');
					while (boundary >= 0) {
						const frame = parseFrame(buffer.slice(0, boundary));
						buffer = buffer.slice(boundary + 2);
						if (frame) {
							if (frame.id) lastEventId = frame.id;
							const data = JSON.parse(frame.data);
							if (frame.event === 'snapshot') job = data;
							if (frame.event === 'update') addEvents([data]);
							await refresh();
						}
						boundary = buffer.indexOf('\n\n');
					}
				}
			} finally {
				await reader.cancel().catch(() => undefined);
			}
			if (!signal.aborted && !terminalStatuses.has(job?.status ?? '')) {
				await new Promise((resolve) => setTimeout(resolve, 500));
			}
		}
	}

	$effect(() => {
		const selectedMode = mode;
		const controller = new AbortController();
		const signal = controller.signal;
		loading = true;
		error = '';
		void (async () => {
			try {
				await refresh();
				if (selectedMode === 'stream') await stream(signal);
				else await poll(signal);
			} catch (cause) {
				if (!signal.aborted) error = cause instanceof Error ? cause.message : 'Unable to load batch progress.';
			} finally {
				if (!signal.aborted) loading = false;
			}
		})();
		return () => controller.abort();
	});
</script>

<section class="space-y-4" aria-live="polite">
	<header class="flex flex-wrap items-center justify-between gap-3">
		<div>
			<h3 class="text-base font-semibold text-text">{job?.name ?? 'Batch progress'}</h3>
			<p class="text-sm text-text-muted">{job?.status ?? 'Loading status'}</p>
		</div>
		<label class="flex items-center gap-2 text-sm text-text-muted">
			<span>Updates</span>
			<select class="rounded-md border border-border bg-surface px-2 py-1 text-text" bind:value={mode}>
				<option value="stream">Live stream</option>
				<option value="poll">Polling</option>
			</select>
		</label>
	</header>

	{#if job}
		<div class="space-y-2">
			<div class="flex justify-between text-sm text-text-muted">
				<span>{job.totalKnown ? `${job.terminal} of ${job.total} messages complete` : `${job.terminal} messages complete · preparing total`}</span>
				<strong>{job.percent == null ? 'In progress' : `${job.percent}%`}</strong>
			</div>
			<div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-valuenow={job.percent ?? undefined} aria-valuemin="0" aria-valuemax="100" aria-label="Batch completion">
				{#if job.percent == null}
					<div class="h-full w-1/3 animate-pulse rounded-full bg-accent"></div>
				{:else}
					<div class="h-full rounded-full bg-accent transition-[width] duration-300" style={`width:${job.percent}%`}></div>
				{/if}
			</div>
		</div>
	{/if}

	{#if error}<p class="text-sm text-danger" role="alert">{error}</p>{/if}
	{#if loading}<p class="text-sm text-text-muted">Loading batch details…</p>{/if}
	{#if job?.status === 'paused' && job.taskKey === 'site.transfer.import'}
		<form class="flex flex-wrap items-end gap-3 rounded-lg border border-warning/30 bg-warning/5 p-4" onsubmit={(event) => { event.preventDefault(); void refreshCredentials(); }}>
			<label class="min-w-64 flex-1 space-y-1 text-sm"><span class="text-text-muted">Replacement source API token</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 font-mono text-text" type="password" autocomplete="off" bind:value={refreshedToken} /></label>
			<button class="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={savingToken || !refreshedToken}>{savingToken ? 'Verifying…' : 'Verify and resume'}</button>
		</form>
	{/if}

	<div class="grid gap-4 xl:grid-cols-2">
		<div class="rounded-lg border border-border">
			<h4 class="border-b border-border px-4 py-3 text-sm font-semibold">Messages</h4>
			{#if items.length === 0}
				<p class="px-4 py-5 text-sm text-text-muted">No message details available yet.</p>
			{:else}
				<ul class="divide-y divide-border">
					{#each items as item (item.id)}
						<li class="flex items-start justify-between gap-3 px-4 py-3">
							<div class="min-w-0">
								<p class="truncate text-sm text-text">{item.summary}</p>
								<p class="mt-1 text-xs text-text-muted">{item.type} · {item.attempts} attempt{item.attempts === 1 ? '' : 's'}</p>
								{#if item.error}<p class="mt-1 text-xs text-danger">{item.error}</p>{/if}
							</div>
							<Badge status={item.state === 'succeeded' ? 'success' : item.state === 'failed' || item.state === 'skipped' ? 'danger' : item.state === 'running' ? 'info' : 'warning'}>{item.state}</Badge>
						</li>
					{/each}
				</ul>
			{/if}
		</div>
		<div class="rounded-lg border border-border">
			<h4 class="border-b border-border px-4 py-3 text-sm font-semibold">Activity</h4>
			{#if events.length === 0}
				<p class="px-4 py-5 text-sm text-text-muted">No activity messages yet.</p>
			{:else}
				<ul class="max-h-80 divide-y divide-border overflow-auto">
					{#each [...events].reverse() as event (event.id)}
						<li class="px-4 py-3">
							<p class="text-sm text-text">{event.summary}</p>
							<p class="mt-1 text-xs text-text-muted">{event.type} · {new Date(event.createdAt).toLocaleString()}</p>
						</li>
					{/each}
				</ul>
			{/if}
		</div>
	</div>
</section>
