<script lang="ts">
	import { onMount } from 'svelte';
import type { ApiClient } from '$lib/api';

	interface Props {
		api: ApiClient;
		debug?: boolean;
	}

	interface SiteOption {
		id: string | number;
		name: string;
		domain: string;
	}

	interface WorkflowFormField {
		name: string;
		type: string;
		label?: string;
		required?: boolean;
		options?: Record<string, unknown>;
	}

	interface WorkflowTask {
		id: string | number;
		instanceId: string | number;
		place: string;
		label: string;
		siteId: string | number | null;
		assigneeUserId: string | number | null;
		assigneeRoleKey: string | null;
		claimedByUserId: string | number | null;
		status: string;
		form: { fields: WorkflowFormField[] } | null;
		enabledTransitions: string[];
	}

	interface WorkflowInstance {
		id: string | number;
		definition: string;
		definitionVersion: number;
		siteId: string | number | null;
		subject: { type: string; id: string } | null;
		marking: Record<string, number>;
		executionState: string;
		pauseReason: string | null;
		attempts: number;
		lastError: string | null;
		enabledTransitions: string[];
		tasks?: WorkflowTask[];
		events?: Array<{ id: string | number; type: string; transition: string | null; fromMarking: Record<string, number> | null; toMarking: Record<string, number> | null; details: Record<string, unknown> }>;
	}

	let { api }: Props = $props();
	let sites = $state<SiteOption[]>([]);
	let selectedSiteId = $state('');
	let tasks = $state<WorkflowTask[]>([]);
	let selectedInstance = $state<WorkflowInstance | null>(null);
	let loading = $state(true);
	let acting = $state(false);
	let error = $state('');
	let message = $state('');
	let formValues = $state<Record<string, Record<string, unknown>>>({});

	async function load(): Promise<void> {
		loading = true;
		error = '';
		try {
			const siteResponse = await api.request('/admin/workflows/tasks/sites');
			sites = siteResponse.sites ?? [];
			if ('' === selectedSiteId) {
				selectedSiteId = String(siteResponse.defaultSiteId ?? sites[0]?.id ?? '');
			}
			const query = selectedSiteId ? `?siteId=${encodeURIComponent(selectedSiteId)}` : '';
			const response = await api.request(`/admin/workflows/tasks${query}`);
			tasks = response.tasks ?? [];
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load workflow tasks.';
		} finally {
			loading = false;
		}
	}

	async function openInstance(instanceId: string | number): Promise<void> {
		error = '';
		try {
			selectedInstance = await api.request(`/admin/workflows/instances/${instanceId}`);
			formValues = {};
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load workflow instance.';
		}
	}

	async function claimTask(task: WorkflowTask): Promise<void> {
		acting = true;
		error = '';
		try {
			await api.request(`/admin/workflows/tasks/${task.id}/claim`, { method: 'POST', body: '{}' });
			message = 'Task claimed.';
			await load();
			await openInstance(task.instanceId);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to claim workflow task.';
		} finally {
			acting = false;
		}
	}

	async function releaseTask(task: WorkflowTask): Promise<void> {
		acting = true;
		error = '';
		try {
			await api.request(`/admin/workflows/tasks/${task.id}/release`, { method: 'POST', body: '{}' });
			message = 'Task released back to the role queue.';
			await load();
			await openInstance(task.instanceId);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to release workflow task.';
		} finally {
			acting = false;
		}
	}

	function taskValue(task: WorkflowTask, field: string): unknown {
		return formValues[String(task.id)]?.[field] ?? '';
	}

	function changeTaskValue(task: WorkflowTask, field: string, value: unknown): void {
		const taskKey = String(task.id);
		formValues = { ...formValues, [taskKey]: { ...(formValues[taskKey] ?? {}), [field]: value } };
	}

	function choiceEntries(field: WorkflowFormField): Array<[string, string]> {
		const choices = field.options?.choices;
		if (!choices || typeof choices !== 'object' || Array.isArray(choices)) return [];

		return Object.entries(choices as Record<string, string>);
	}

	async function applyTransition(task: WorkflowTask, transition: string): Promise<void> {
		if (!selectedInstance) return;
		acting = true;
		error = '';
		try {
			selectedInstance = await api.request(`/admin/workflows/instances/${selectedInstance.id}/transitions/${encodeURIComponent(transition)}`, {
				method: 'POST',
				body: JSON.stringify({ data: formValues[String(task.id)] ?? {} }),
			});
			message = `Applied ${transition.replaceAll('_', ' ')}.`;
			await load();
			await openInstance(selectedInstance.id);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to apply transition.';
		} finally {
			acting = false;
		}
	}

	onMount(() => {
		void load();
	});
</script>

<section class="space-y-5 p-6">
	<header class="flex flex-wrap items-end justify-between gap-4">
		<div>
			<p class="text-xs uppercase tracking-[0.24em] text-text-muted">Workflow operations</p>
			<h1 class="mt-1 text-2xl font-semibold text-text">Task inbox</h1>
			<p class="mt-1 text-sm text-text-muted">Role tasks can be claimed by eligible site members. Transitions remain guarded by the workflow policy.</p>
		</div>
		<div class="flex items-center gap-3">
			<label for="task-site" class="text-sm text-text-muted">Site</label>
			<select id="task-site" class="admin-input" bind:value={selectedSiteId} onchange={() => void load()}>
				{#each sites as site (site.id)}
					<option value={String(site.id)}>{site.name} ({site.domain})</option>
				{/each}
			</select>
			<button class="iikiti-btn iikiti-btn--secondary" onclick={() => void load()}>Refresh</button>
		</div>
	</header>

	{#if error}<div class="rounded border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">{error}</div>{/if}
	{#if message}<div class="rounded border border-success/30 bg-success/5 px-4 py-3 text-sm text-success">{message}</div>{/if}

	{#if loading}
		<p class="text-sm text-text-muted">Loading tasks…</p>
	{:else}
		<div class="grid gap-5 xl:grid-cols-[minmax(22rem,0.85fr)_minmax(28rem,1.15fr)]">
			<section class="overflow-hidden rounded-lg border border-border bg-surface">
				<div class="border-b border-border px-4 py-3">
					<h2 class="text-sm font-semibold text-text">Open and claimed tasks ({tasks.length})</h2>
				</div>
				{#if tasks.length === 0}
					<p class="p-4 text-sm text-text-muted">No tasks are waiting for you in this site.</p>
				{:else}
					<ul class="divide-y divide-border">
						{#each tasks as task (task.id)}
							<li class="flex items-center justify-between gap-3 p-4">
								<button class="min-w-0 text-left" onclick={() => void openInstance(task.instanceId)}>
									<span class="block truncate font-medium text-text">{task.label}</span>
									<span class="mt-1 block text-xs text-text-muted">{task.place} · instance {task.instanceId} · {task.status}{task.assigneeRoleKey ? ` · ${task.assigneeRoleKey}` : ''}</span>
								</button>
								{#if task.assigneeRoleKey && task.status === 'open'}
									<button class="iikiti-btn iikiti-btn--secondary" disabled={acting} onclick={() => void claimTask(task)}>Claim</button>
								{:else if task.assigneeRoleKey && task.status === 'claimed'}
									<button class="iikiti-btn iikiti-btn--secondary" disabled={acting} onclick={() => void releaseTask(task)}>Release</button>
								{/if}
							</li>
						{/each}
					</ul>
				{/if}
			</section>

			<section class="rounded-lg border border-border bg-surface p-5">
				{#if !selectedInstance}
					<p class="text-sm text-text-muted">Select a task to inspect its workflow instance.</p>
				{:else}
					<div class="flex items-start justify-between gap-4">
						<div>
							<h2 class="text-lg font-semibold text-text">{selectedInstance.definition}</h2>
							<p class="mt-1 text-xs text-text-muted">Version {selectedInstance.definitionVersion} · instance {selectedInstance.id}</p>
						</div>
						<span class="rounded px-2 py-1 text-xs {selectedInstance.executionState === 'active' ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'}">{selectedInstance.executionState}</span>
					</div>
					{#if selectedInstance.pauseReason}
						<p class="mt-4 rounded bg-warning/10 p-3 text-sm text-warning">Paused: {selectedInstance.pauseReason}</p>
					{/if}
					<div class="mt-4">
						<h3 class="text-xs font-semibold uppercase tracking-wide text-text-muted">Active markings</h3>
						<div class="mt-2 flex flex-wrap gap-2">
							{#each Object.entries(selectedInstance.marking) as [place, tokens] (place)}
								<span class="rounded-full bg-accent/10 px-3 py-1 text-xs text-accent">{place} × {tokens}</span>
							{/each}
						</div>
					</div>
					{#if selectedInstance.tasks?.length}
						<h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-text-muted">Instance tasks</h3>
						<ul class="mt-2 space-y-2">
							{#each selectedInstance.tasks.filter((task) => ['open', 'claimed'].includes(task.status)) as task (task.id)}
								<li class="rounded border border-border p-3">
									<div class="flex justify-between gap-3 text-sm"><span class="font-medium">{task.label} · {task.status}</span><span class="text-text-muted">{task.assigneeRoleKey ?? task.assigneeUserId ?? ''}</span></div>
									{#if task.form?.fields?.length}
										<div class="mt-3 grid gap-3 sm:grid-cols-2">
											{#each task.form.fields as field (field.name)}
												<label class="block text-xs text-text-muted">{field.label ?? field.name}
													{#if field.type === 'textarea'}
														<textarea class="admin-input mt-1 w-full" required={field.required ?? false} value={String(taskValue(task, field.name))} oninput={(event) => changeTaskValue(task, field.name, event.currentTarget.value)}></textarea>
													{:else if field.type === 'choice'}
														<select class="admin-input mt-1 w-full" required={field.required ?? false} value={String(taskValue(task, field.name))} onchange={(event) => changeTaskValue(task, field.name, event.currentTarget.value)}>
															<option value="">Select…</option>
															{#each choiceEntries(field) as [value, label] (value)}
																<option value={value}>{label}</option>
															{/each}
														</select>
													{:else if field.type === 'checkbox'}
														<input class="mt-2" type="checkbox" checked={Boolean(taskValue(task, field.name))} onchange={(event) => changeTaskValue(task, field.name, event.currentTarget.checked)} />
													{:else}
														<input class="admin-input mt-1 w-full" type={field.type} required={field.required ?? false} value={String(taskValue(task, field.name))} oninput={(event) => changeTaskValue(task, field.name, event.currentTarget.value)} />
													{/if}
												</label>
											{/each}
										</div>
									{/if}
									{#if task.enabledTransitions.length > 0}
										<div class="mt-3 flex flex-wrap gap-2">
											{#each task.enabledTransitions as transition (transition)}
												<button class="iikiti-btn iikiti-btn--primary" disabled={acting} onclick={() => void applyTransition(task, transition)}>{transition.replaceAll('_', ' ')}</button>
											{/each}
										</div>
									{/if}
								</li>
							{/each}
						</ul>
					{/if}
					{#if selectedInstance.events?.length}
						<h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-text-muted">History</h3>
						<ol class="mt-2 space-y-2">
							{#each selectedInstance.events as event (event.id)}
								<li class="rounded border border-border px-3 py-2 text-xs text-text-muted">{event.type}{event.transition ? ` · ${event.transition}` : ''}{event.toMarking ? ` · ${Object.keys(event.toMarking).join(', ')}` : ''}</li>
							{/each}
						</ol>
					{/if}
				{/if}
			</section>
		</div>
	{/if}
</section>
