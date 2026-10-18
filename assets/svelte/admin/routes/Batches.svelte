<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import BatchProgress from '$components/BatchProgress.svelte';
	import Badge from '$components/Badge.svelte';

	interface Job {
		id: string;
		name: string;
		taskKey: string | null;
		siteId: string | number | null;
		status: string;
		total: number;
		terminal: number;
		percent: number | null;
		createdAt: string;
	}

	interface Props { api: ApiClient; }
	let { api }: Props = $props();
	let jobs = $state<Job[]>([]);
	let selectedId = $state('');
	let loading = $state(true);
	let error = $state('');
	let actionError = $state('');

	async function load(): Promise<void> {
		try {
			const response = await api.request('/admin/batches');
			jobs = response.jobs ?? [];
			if (selectedId && !jobs.some((job) => job.id === selectedId)) selectedId = '';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load batch jobs.';
		} finally {
			loading = false;
		}
	}

	async function act(job: Job, operation: 'cancel' | 'retry'): Promise<void> {
		actionError = '';
		try {
			await api.request(`/admin/batches/${job.id}/${operation}`, { method: 'POST', body: '{}' });
			await load();
		} catch (cause) {
			actionError = cause instanceof Error ? cause.message : `Unable to ${operation} batch.`;
		}
	}

	function badgeStatus(status: string): 'success' | 'warning' | 'danger' | 'info' | 'primary' {
		if (status === 'completed') return 'success';
		if (status === 'completed_with_errors') return 'warning';
		if (status === 'failed' || status === 'cancelled') return 'danger';
		if (status === 'running') return 'info';
		return 'primary';
	}

	onMount(() => {
		void load();
		const timer = setInterval(() => void load(), 10000);
		return () => clearInterval(timer);
	});
</script>

<div class="space-y-6 p-6">
	<header>
		<h1 class="text-2xl font-semibold text-text">Batch Jobs</h1>
		<p class="mt-1 text-sm text-text-muted">Monitor distributed message processing across core tasks and plugins.</p>
	</header>
	{#if error}<p class="text-sm text-danger" role="alert">{error}</p>{/if}
	{#if actionError}<p class="text-sm text-danger" role="alert">{actionError}</p>{/if}
	{#if loading}<p class="text-sm text-text-muted">Loading jobs…</p>{/if}
	<div class="grid gap-6 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(0,1.5fr)]">
		<section class="overflow-hidden rounded-xl border border-border bg-surface">
			<div class="border-b border-border px-4 py-3">
				<h2 class="font-semibold text-text">Recent jobs</h2>
			</div>
			{#if jobs.length === 0 && !loading}
				<p class="p-5 text-sm text-text-muted">No batch jobs are available in your managed scope.</p>
			{:else}
				<ul class="divide-y divide-border">
					{#each jobs as job (job.id)}
						<li class="p-4 {selectedId === job.id ? 'bg-accent/5' : ''}">
							<button class="w-full text-left" onclick={() => selectedId = job.id}>
								<div class="flex items-center justify-between gap-2">
									<span class="font-medium text-text">{job.name}</span>
									<Badge status={badgeStatus(job.status)}>{job.status.replaceAll('_', ' ')}</Badge>
								</div>
								<p class="mt-1 text-xs text-text-muted">#{job.id} · {job.taskKey ?? 'manual'} · {job.terminal}/{job.total}</p>
							</button>
							<div class="mt-3 flex gap-2">
								{#if ['queued', 'running', 'paused'].includes(job.status)}
									<button class="text-xs text-danger hover:underline" onclick={() => void act(job, 'cancel')}>Cancel</button>
								{:else if ['completed_with_errors', 'failed'].includes(job.status)}
									<button class="text-xs text-accent hover:underline" onclick={() => void act(job, 'retry')}>Retry failures</button>
								{/if}
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>
		<section class="min-w-0 rounded-xl border border-border bg-surface p-5">
			{#if selectedId}
				<BatchProgress {api} batchId={selectedId} />
			{:else}
				<div class="flex min-h-48 items-center justify-center text-center text-sm text-text-muted">Select a job to inspect messages and progress.</div>
			{/if}
		</section>
	</div>
</div>
