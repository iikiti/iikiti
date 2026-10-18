<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import Badge from '$components/Badge.svelte';

	interface SiteOption { id: string; name: string; }
	interface OptionField { type: string; label: string; required?: boolean; default?: unknown; }
	interface TaskProvider {
		key: string;
		name: string;
		scope: 'site' | 'global';
		allowOverlap: boolean;
		options: Record<string, OptionField>;
	}
	interface Schedule {
		id: string;
		name: string;
		providerKey: string;
		cronExpression: string;
		timezone: string;
		scope: 'site' | 'global';
		siteId: string | null;
		options: Record<string, unknown>;
		enabled: boolean;
		allowOverlap: boolean;
		lastRunAt: string | null;
		nextRunAt: string;
	}
	interface Props { api: ApiClient; }
	let { api }: Props = $props();
	let providers = $state<TaskProvider[]>([]);
	let sites = $state<SiteOption[]>([]);
	let schedules = $state<Schedule[]>([]);
	let selectedProviderKey = $state('');
	let selectedSiteId = $state('');
	let name = $state('');
	let cronExpression = $state('0 * * * *');
	let timezone = $state(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
	let options = $state<Record<string, unknown>>({});
	let allowOverlap = $state(false);
	let editingId = $state<string | null>(null);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let notice = $state('');

	const selectedProvider = $derived(providers.find((provider) => provider.key === selectedProviderKey) ?? null);

	async function load(): Promise<void> {
		loading = true;
		error = '';
		try {
			const [catalog, response] = await Promise.all([
				api.request('/admin/batches/schedules/providers'),
				api.request('/admin/batches/schedules'),
			]);
			providers = catalog.providers ?? [];
			sites = catalog.sites ?? [];
			schedules = response.schedules ?? [];
			if (!selectedProviderKey && providers.length) selectedProviderKey = providers[0].key;
			if (!selectedSiteId && sites.length) selectedSiteId = sites[0].id;
			if (selectedProvider) resetOptions(selectedProvider);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load schedules.';
		} finally {
			loading = false;
		}
	}

	function resetOptions(provider: TaskProvider): void {
		const defaults: Record<string, unknown> = {};
		for (const [key, field] of Object.entries(provider.options ?? {})) defaults[key] = field.default ?? '';
		options = defaults;
		allowOverlap = false;
	}

	function changeProvider(key: string): void {
		selectedProviderKey = key;
		const provider = providers.find((entry) => entry.key === key);
		if (provider) resetOptions(provider);
		editingId = null;
		name = '';
	}

	function setOption(key: string, value: unknown): void {
		options = { ...options, [key]: value };
	}

	function edit(schedule: Schedule): void {
		const provider = providers.find((entry) => entry.key === schedule.providerKey);
		if (!provider) return;
		selectedProviderKey = schedule.providerKey;
		selectedSiteId = schedule.siteId ?? '';
		name = schedule.name;
		cronExpression = schedule.cronExpression;
		timezone = schedule.timezone;
		options = { ...schedule.options };
		allowOverlap = schedule.allowOverlap;
		editingId = schedule.id;
		notice = '';
	}

	async function save(): Promise<void> {
		if (!selectedProvider) return;
		saving = true;
		error = '';
		notice = '';
		const payload = {
			name,
			providerKey: selectedProvider.key,
			siteId: selectedProvider.scope === 'site' ? selectedSiteId : null,
			cronExpression,
			timezone,
			options,
			enabled: true,
			allowOverlap: selectedProvider.allowOverlap && allowOverlap,
		};
		try {
			await api.request(editingId ? `/admin/batches/schedules/${editingId}` : '/admin/batches/schedules', {
				method: editingId ? 'PUT' : 'POST',
				body: JSON.stringify(payload),
			});
			notice = editingId ? 'Schedule updated.' : 'Schedule created.';
			editingId = null;
			name = '';
			resetOptions(selectedProvider);
			await load();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to save schedule.';
		} finally {
			saving = false;
		}
	}

	async function toggle(schedule: Schedule): Promise<void> {
		try {
			await api.request(`/admin/batches/schedules/${schedule.id}/enabled`, { method: 'PATCH', body: JSON.stringify({ enabled: !schedule.enabled }) });
			await load();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to update schedule.';
		}
	}

	onMount(() => { void load(); });
</script>

<div class="space-y-6 p-6">
	<header>
		<h1 class="text-2xl font-semibold text-text">Schedules</h1>
		<p class="mt-1 text-sm text-text-muted">Create recurring runs from registered core and plugin task providers. Changes are picked up on the next scheduler tick.</p>
	</header>
	{#if error}<p class="text-sm text-danger" role="alert">{error}</p>{/if}
	{#if notice}<p class="text-sm text-success" role="status">{notice}</p>{/if}
	{#if loading}<p class="text-sm text-text-muted">Loading schedules…</p>{/if}
	<div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.85fr)_minmax(0,1.15fr)]">
		<section class="rounded-xl border border-border bg-surface p-5">
			<h2 class="mb-4 font-semibold text-text">{editingId ? 'Edit schedule' : 'New schedule'}</h2>
			{#if providers.length === 0}
				<p class="text-sm text-text-muted">No scheduled task providers are registered. Core and plugins can add providers through `ScheduledTaskProviderInterface`.</p>
			{:else}
				<form class="space-y-4" onsubmit={(event) => { event.preventDefault(); void save(); }}>
					<label class="block space-y-1 text-sm"><span class="text-text-muted">Task provider</span>
						<select class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" value={selectedProviderKey} onchange={(event) => changeProvider(event.currentTarget.value)}>
							{#each providers as provider (provider.key)}<option value={provider.key}>{provider.name}</option>{/each}
						</select>
					</label>
					<label class="block space-y-1 text-sm"><span class="text-text-muted">Schedule name</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={name} required maxlength="128" /></label>
					{#if selectedProvider?.scope === 'site'}
						<label class="block space-y-1 text-sm"><span class="text-text-muted">Site</span>
							<select class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={selectedSiteId} required>
								{#each sites as site (site.id)}<option value={site.id}>{site.name}</option>{/each}
							</select>
						</label>
					{/if}
					<div class="grid gap-3 sm:grid-cols-2">
						<label class="block space-y-1 text-sm"><span class="text-text-muted">Cron expression</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 font-mono text-text" bind:value={cronExpression} required placeholder="*/15 * * * *" /></label>
						<label class="block space-y-1 text-sm"><span class="text-text-muted">Time zone</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={timezone} required /></label>
					</div>
					{#each Object.entries(selectedProvider?.options ?? {}) as [key, field] (key)}
						<label class="block space-y-1 text-sm"><span class="text-text-muted">{field.label}</span>
							{#if field.type === 'boolean'}
								<input type="checkbox" checked={Boolean(options[key])} onchange={(event) => setOption(key, event.currentTarget.checked)} />
							{:else if field.type === 'number'}
								<input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" type="number" value={String(options[key] ?? '')} required={field.required ?? false} onchange={(event) => setOption(key, Number(event.currentTarget.value))} />
							{:else}
								<input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" type="text" value={String(options[key] ?? '')} required={field.required ?? false} onchange={(event) => setOption(key, event.currentTarget.value)} />
							{/if}
						</label>
					{/each}
					{#if selectedProvider?.allowOverlap}
						<label class="flex items-center gap-2 text-sm text-text"><input type="checkbox" bind:checked={allowOverlap} /> Allow overlapping runs</label>
					{/if}
					<div class="flex gap-2">
						<button class="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={saving}>{saving ? 'Saving…' : editingId ? 'Save changes' : 'Create schedule'}</button>
						{#if editingId}<button type="button" class="rounded-md border border-border px-4 py-2 text-sm text-text" onclick={() => { editingId = null; name = ''; if (selectedProvider) resetOptions(selectedProvider); }}>Cancel edit</button>{/if}
					</div>
				</form>
			{/if}
		</section>
		<section class="overflow-hidden rounded-xl border border-border bg-surface">
			<h2 class="border-b border-border px-5 py-4 font-semibold text-text">Configured schedules</h2>
			{#if schedules.length === 0}
				<p class="p-5 text-sm text-text-muted">No recurring schedules are configured.</p>
			{:else}
				<ul class="divide-y divide-border">
					{#each schedules as schedule (schedule.id)}
						<li class="flex flex-wrap items-center justify-between gap-4 p-4">
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-2"><h3 class="font-medium text-text">{schedule.name}</h3><Badge status={schedule.enabled ? 'success' : 'warning'}>{schedule.enabled ? 'enabled' : 'disabled'}</Badge></div>
								<p class="mt-1 text-xs text-text-muted">{schedule.providerKey} · <code>{schedule.cronExpression}</code> · {schedule.timezone}</p>
								<p class="mt-1 text-xs text-text-muted">Next: {new Date(schedule.nextRunAt).toLocaleString()} · Last: {schedule.lastRunAt ? new Date(schedule.lastRunAt).toLocaleString() : 'Never'}</p>
							</div>
							<div class="flex gap-3 text-sm"><button class="text-accent hover:underline" onclick={() => edit(schedule)}>Edit</button><button class="text-text-muted hover:underline" onclick={() => void toggle(schedule)}>{schedule.enabled ? 'Disable' : 'Enable'}</button></div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>
	</div>
</div>
