<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallIpAction, FirewallIpRule, FirewallInheritance, FirewallScope } from '$types';
	import DataTable from '$components/DataTable.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import TextInput from '$components/TextInput.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import Badge from '$components/Badge.svelte';
	import {
		errorMessage,
		formatTimestamp,
		isExpired,
		linesToList,
		localInputToIso,
		toInt,
	} from './firewallHelpers';

	interface Props {
		api: ApiClient;
		scope: FirewallScope;
		siteId: string | null;
		onNotify: (message: string) => void;
		onError: (message: string) => void;
	}

	let { api, scope, siteId, onNotify, onError }: Props = $props();

	const ACTION_OPTIONS = [
		{ value: 'allow', label: 'Allow' },
		{ value: 'block', label: 'Block' },
		{ value: 'report', label: 'Report' },
	];
	const INHERITANCE_OPTIONS = [
		{ value: 'default', label: 'Default (overridable)' },
		{ value: 'required', label: 'Required (cannot be weakened)' },
	];

	interface RuleDraft {
		id: number | string | null;
		cidr: string;
		action: FirewallIpAction;
		inheritance: FirewallInheritance;
		priority: number;
		expiresAt: string;
		reason: string;
		enabled: boolean;
	}

	const blankDraft = (): RuleDraft => ({
		id: null,
		cidr: '',
		action: 'block',
		inheritance: 'default',
		priority: 0,
		expiresAt: '',
		reason: '',
		enabled: true,
	});

	let loading = $state(true);
	let saving = $state(false);
	let rules = $state<FirewallIpRule[]>([]);
	let query = $state('');
	let modalOpen = $state(false);
	let importOpen = $state(false);
	let importText = $state('');
	let draft = $state<RuleDraft>(blankDraft());
	let formError = $state('');

	const isGlobal = $derived(scope === 'global');

	/** Case-insensitive search across CIDR, reason, and action. */
	const filtered = $derived.by(() => {
		const needle = query.trim().toLowerCase();
		if (!needle) return rules;
		return rules.filter((rule) =>
			[rule.cidr, rule.reason, rule.action].some((field) => field.toLowerCase().includes(needle)),
		);
	});

	const columns = [
		{ key: 'cidr', label: 'CIDR' },
		{ key: 'action', label: 'Action', render: (value: string) => value.toUpperCase() },
		{ key: 'inheritance', label: 'Inheritance', render: (value: string) => (value === 'required' ? 'Required' : 'Default') },
		{ key: 'enabled', label: 'Status', render: (_: unknown, row: FirewallIpRule) => statusLabel(row) },
		{ key: 'priority', label: 'Priority' },
		{ key: 'expiresAt', label: 'Expires', render: (value: string | null) => formatTimestamp(value) },
		{ key: 'reason', label: 'Reason' },
	];

	function statusLabel(rule: FirewallIpRule): string {
		if (!rule.enabled) return 'Disabled';
		if (isExpired(rule)) return 'Expired';
		return 'Active';
	}

	function actionStatus(action: FirewallIpAction): 'success' | 'danger' | 'warning' {
		if (action === 'allow') return 'success';
		if (action === 'block') return 'danger';
		return 'warning';
	}

	async function load(): Promise<void> {
		loading = true;
		try {
			rules = await api.getFirewallIpRules(scope, siteId);
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load IP rules.'));
		} finally {
			loading = false;
		}
	}

	function openCreate(): void {
		draft = blankDraft();
		formError = '';
		modalOpen = true;
	}

	function openEdit(rule: FirewallIpRule): void {
		draft = {
			id: rule.id,
			cidr: rule.cidr,
			action: rule.action,
			inheritance: rule.inheritance,
			priority: rule.priority,
			expiresAt: rule.expiresAt ? rule.expiresAt.slice(0, 16) : '',
			reason: rule.reason,
			enabled: rule.enabled,
		};
		formError = '';
		modalOpen = true;
	}

	function buildPayload(): Record<string, unknown> {
		const payload: Record<string, unknown> = {
			cidr: draft.cidr.trim(),
			action: draft.action,
			priority: toInt(draft.priority, 0),
			expiresAt: localInputToIso(draft.expiresAt),
			reason: draft.reason.trim(),
			enabled: draft.enabled,
		};
		// Inheritance is only meaningful for global rules; site rules never send it.
		if (isGlobal) payload.inheritance = draft.inheritance;
		return payload;
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!draft.cidr.trim()) {
			formError = 'CIDR is required (for example 203.0.113.0/24 or 2001:db8::/32).';
			return;
		}
		saving = true;
		formError = '';
		try {
			const payload = buildPayload();
			if (draft.id === null) {
				await api.createFirewallIpRule(scope, siteId, payload);
				onNotify('IP rule created.');
			} else {
				await api.updateFirewallIpRule(scope, siteId, draft.id, payload);
				onNotify('IP rule updated.');
			}
			modalOpen = false;
			await load();
		} catch (cause) {
			formError = errorMessage(cause, 'Unable to save IP rule.');
		} finally {
			saving = false;
		}
	}

	async function remove(rule: FirewallIpRule): Promise<void> {
		if (!confirm(`Delete IP rule ${rule.cidr}?`)) return;
		try {
			await api.deleteFirewallIpRule(scope, siteId, rule.id);
			onNotify('IP rule deleted.');
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to delete IP rule.'));
		}
	}

	async function runImport(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		const items = linesToList(importText).map((cidr) => ({ cidr, action: 'block', inheritance: 'default' }));
		if (items.length === 0) {
			formError = 'Enter at least one CIDR, one per line.';
			return;
		}
		saving = true;
		formError = '';
		try {
			const result = await api.importFirewallIpRules(items);
			onNotify(`Imported ${result?.importedCount ?? items.length} IP rule(s).`);
			importOpen = false;
			importText = '';
			await load();
		} catch (cause) {
			formError = errorMessage(cause, 'Import failed.');
		} finally {
			saving = false;
		}
	}

	onMount(() => { void load(); });
</script>

<div class="space-y-4">
	<div class="flex flex-wrap items-center justify-between gap-3">
		<div class="w-full max-w-sm">
			<TextInput name="search" placeholder="Search CIDR, reason, or action…" value={query} oninput={(v) => (query = String(v ?? ''))} />
		</div>
		<div class="flex gap-2">
			{#if isGlobal}
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => { formError = ''; importOpen = true; }}>Bulk CIDR import</button>
			{/if}
			<button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick={openCreate}>Add IP rule</button>
		</div>
	</div>

	{#if loading}
		<LoadingState label="Loading IP rules…" />
	{:else}
		<DataTable data={filtered} {columns} />
		<ul class="space-y-2">
			{#each filtered as rule (rule.id)}
				<li class="flex items-center justify-between gap-3 rounded-lg border border-border bg-surface p-3 text-sm">
					<div class="flex items-center gap-2">
						<code class="text-text">{rule.cidr}</code>
						<Badge status={actionStatus(rule.action)}>{rule.action}</Badge>
						{#if rule.inheritance === 'required'}<Badge status="info">required</Badge>{/if}
						<Badge status={rule.enabled && !isExpired(rule) ? 'success' : 'warning'}>{statusLabel(rule)}</Badge>
					</div>
					<div class="flex gap-3">
						<button type="button" class="admin-link" onclick={() => openEdit(rule)}>Edit</button>
						<button type="button" class="admin-link text-danger" onclick={() => void remove(rule)}>Delete</button>
					</div>
				</li>
			{/each}
		</ul>
	{/if}
</div>

{#if modalOpen}
	<ModalDialog title={draft.id === null ? 'Add IP rule' : 'Edit IP rule'} onClose={() => (modalOpen = false)}>
		<form class="space-y-4" onsubmit={save}>
			{#if formError}<p class="text-sm text-danger" role="alert">{formError}</p>{/if}
			<label class="block space-y-1 text-sm"><span class="text-text-muted">CIDR (IPv4 or IPv6)</span>
				<TextInput name="cidr" value={draft.cidr} required oninput={(v) => (draft.cidr = String(v ?? ''))} placeholder="203.0.113.0/24" />
			</label>
			<div class="grid gap-4 sm:grid-cols-2">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Action</span>
					<SelectInput name="action" value={draft.action} options={ACTION_OPTIONS} onchange={(v) => (draft.action = v)} />
				</label>
				{#if isGlobal}
					<label class="block space-y-1 text-sm"><span class="text-text-muted">Inheritance</span>
						<SelectInput name="inheritance" value={draft.inheritance} options={INHERITANCE_OPTIONS} onchange={(v) => (draft.inheritance = v)} />
					</label>
				{/if}
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Priority</span>
					<TextInput name="priority" type="number" value={String(draft.priority)} oninput={(v) => (draft.priority = toInt(v, 0))} />
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Expires at (optional)</span>
					<input type="datetime-local" class="admin-input w-full" value={draft.expiresAt} oninput={(e) => (draft.expiresAt = (e.currentTarget as HTMLInputElement).value)} />
				</label>
			</div>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Reason</span>
				<TextareaInput name="reason" value={draft.reason} oninput={(v) => (draft.reason = String(v ?? ''))} />
			</label>
			<ToggleInput label="Enabled" value={draft.enabled} onchange={(v) => (draft.enabled = v)} />
			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => (modalOpen = false)}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
			</div>
		</form>
	</ModalDialog>
{/if}

{#if importOpen}
	<ModalDialog title="Bulk CIDR import" onClose={() => (importOpen = false)} width={560}>
		<form class="space-y-4" onsubmit={runImport}>
			{#if formError}<p class="text-sm text-danger" role="alert">{formError}</p>{/if}
			<p class="text-sm text-text-muted">One CIDR per line. Imported as <strong>block</strong> rules with default inheritance.</p>
			<TextareaInput name="cidrs" value={importText} oninput={(v) => (importText = String(v ?? ''))} />
			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => (importOpen = false)}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>{saving ? 'Importing…' : 'Import'}</button>
			</div>
		</form>
	</ModalDialog>
{/if}
