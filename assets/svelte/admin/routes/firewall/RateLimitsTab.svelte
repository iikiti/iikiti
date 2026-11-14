<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallProtectionPolicy, FirewallRateLimitRule, FirewallScope } from '$types';
	import LoadingState from '$components/LoadingState.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import TextInput from '$components/TextInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import Badge from '$components/Badge.svelte';
	import { blankRateLimit, errorMessage, linesToList, listToLines, toInt } from './firewallHelpers';
	import { loadProtectionPolicy, saveProtectionPolicy } from './policy';

	interface Props {
		api: ApiClient;
		scope: FirewallScope;
		applicationId: string;
		siteId: string | null;
		onNotify: (message: string) => void;
		onError: (message: string) => void;
	}

	let { api, scope, applicationId, siteId, onNotify, onError }: Props = $props();

	const ALGORITHMS = [
		{ value: 'sliding_window', label: 'Sliding window' },
		{ value: 'token_bucket', label: 'Token bucket' },
		{ value: 'fixed_window', label: 'Fixed window' },
	];
	const ACTIONS = [
		{ value: 'throttle', label: 'Throttle (429)' },
		{ value: 'report', label: 'Report only' },
	];
	const KEY_BY = [
		{ value: 'ip', label: 'IP' },
		{ value: 'subnet', label: 'Subnet (/24 · /64)' },
		{ value: 'user', label: 'User' },
		{ value: 'token', label: 'API token' },
		{ value: 'ip_and_route', label: 'IP + route' },
		{ value: 'bot_identity', label: 'Bot identity' },
		{ value: 'site', label: 'Site' },
	];
	const ROUTE_GROUPS = [
		{ value: 'auth', label: 'Auth' },
		{ value: 'mfa', label: 'MFA' },
		{ value: 'forms', label: 'Forms' },
		{ value: 'api', label: 'Public API' },
		{ value: 'public', label: 'Public' },
		{ value: 'search', label: 'Search' },
		{ value: 'admin', label: 'Admin' },
		{ value: 'custom', label: 'Custom' },
	];
	const AUTH_OPTIONS = [
		{ value: 'any', label: 'Any' },
		{ value: 'anonymous', label: 'Anonymous only' },
		{ value: 'authenticated', label: 'Authenticated only' },
	];
	const INHERITANCE_OPTIONS = [
		{ value: 'default', label: 'Default (overridable)' },
		{ value: 'required', label: 'Required (cannot be weakened)' },
	];

	let loading = $state(true);
	let saving = $state(false);
	let version = $state(1);
	let rules = $state<FirewallRateLimitRule[]>([]);
	let effective = $state<FirewallRateLimitRule[]>([]);
	/** Preserved verbatim so saving rate limits never clobbers DoS or bot settings. */
	let carriedDos = $state<Record<string, unknown>>({});
	let carriedBots = $state<Record<string, unknown>>({});
	let editing = $state<FirewallRateLimitRule | null>(null);
	let isNew = $state(false);
	let methodsText = $state('');
	let formError = $state('');

	/** Site scope lists inherited (required/default from the application) separately from its own rules. */
	const inheritedIds = $derived(
		scope === 'site' ? new Set(effective.filter((r) => r.inheritance === 'required').map((r) => r.id)) : new Set<string>(),
	);

	function isInherited(rule: FirewallRateLimitRule): boolean {
		return scope === 'site' && inheritedIds.has(rule.id) && !rules.some((own) => own.id === rule.id);
	}

	async function load(): Promise<void> {
		loading = true;
		try {
			const policy: FirewallProtectionPolicy = await loadProtectionPolicy(api, scope, applicationId, siteId);
			version = policy.version ?? 1;
			rules = policy.rateLimits ?? [];
			effective = policy.effective?.rateLimits ?? [];
			carriedDos = (policy.dos ?? {}) as Record<string, unknown>;
			carriedBots = (policy.bots ?? {}) as Record<string, unknown>;
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load rate limits.'));
		} finally {
			loading = false;
		}
	}

	/** Rules shown in the table: own rules plus inherited ones that are not overridden. */
	const displayRules = $derived.by(() => {
		if (scope !== 'site') return rules.map((rule) => ({ rule, source: 'own' as const }));
		const own = rules.map((rule) => ({ rule, source: 'site' as const }));
		const inherited = effective
			.filter((rule) => !rules.some((own) => own.id === rule.id))
			.map((rule) => ({ rule, source: 'inherited' as const }));
		return [...inherited, ...own];
	});

	function openCreate(): void {
		editing = blankRateLimit();
		isNew = true;
		methodsText = '';
		formError = '';
	}

	function openEdit(rule: FirewallRateLimitRule): void {
		editing = structuredClone(rule);
		isNew = false;
		methodsText = listToLines(rule.match.methods);
		formError = '';
	}

	function applyDraftMethods(rule: FirewallRateLimitRule): FirewallRateLimitRule {
		return { ...rule, match: { ...rule.match, methods: linesToList(methodsText).map((m) => m.toUpperCase()) } };
	}

	function commitEdit(event: SubmitEvent): void {
		event.preventDefault();
		if (!editing) return;
		const candidate = applyDraftMethods(editing);
		if (!candidate.name.trim()) {
			formError = 'A rule name is required.';
			return;
		}
		if (isNew) {
			rules = [...rules, candidate];
		} else {
			rules = rules.map((rule) => (rule.id === candidate.id ? candidate : rule));
		}
		editing = null;
	}

	function removeRule(rule: FirewallRateLimitRule): void {
		rules = rules.filter((item) => item.id !== rule.id);
	}

	async function save(): Promise<void> {
		saving = true;
		try {
			const saved = await saveProtectionPolicy(api, scope, applicationId, siteId, {
				version,
				rateLimits: rules,
				dos: carriedDos,
				bots: carriedBots,
			});
			onNotify('Rate limit policy saved.');
			version = saved?.version ?? version;
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to save rate limit policy.'));
		} finally {
			saving = false;
		}
	}

	onMount(() => { void load(); });
</script>

{#if loading}
	<LoadingState label="Loading rate limits…" />
{:else}
	<div class="space-y-4">
		<div class="flex items-center justify-between">
			<p class="text-sm text-text-muted">
				{scope === 'site' ? 'Inherited application rules are shown first; site rules tighten or add to them.' : 'Application-wide rules apply to every site.'}
			</p>
			<div class="flex gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={openCreate}>Add rule</button>
				<button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick={save} disabled={saving}>{saving ? 'Saving…' : 'Save policy'}</button>
			</div>
		</div>

		<div class="overflow-x-auto rounded-lg border border-border">
			<table class="admin-table min-w-full">
				<thead><tr><th>Name</th><th>Source</th><th>Algorithm</th><th>Limit</th><th>Key</th><th>Action</th><th>Status</th><th></th></tr></thead>
				<tbody>
					{#each displayRules as { rule, source } (rule.id)}
						<tr>
							<td>{rule.name || rule.id}</td>
							<td>
								{#if source === 'inherited'}<Badge status="info">inherited</Badge>
								{:else if source === 'site'}<Badge status="primary">site</Badge>
								{:else}<Badge status="primary">application</Badge>{/if}
							</td>
							<td>{rule.algorithm}</td>
							<td>{rule.limit} / {rule.interval}s{rule.burst ? ` (burst ${rule.burst})` : ''}</td>
							<td>{rule.keyBy}</td>
							<td>{rule.action}</td>
							<td><Badge status={rule.enabled ? 'success' : 'warning'}>{rule.enabled ? 'enabled' : 'disabled'}</Badge></td>
							<td class="text-right">
								{#if source !== 'inherited'}
									<button type="button" class="admin-link" onclick={() => openEdit(rule)}>Edit</button>
									<button type="button" class="admin-link text-danger" onclick={() => removeRule(rule)}>Remove</button>
								{:else}
									<span class="text-xs text-text-muted">Locked by application</span>
								{/if}
							</td>
						</tr>
					{:else}
						<tr><td colspan="8" class="text-center text-text-muted">No rate-limit rules configured.</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
	</div>
{/if}

{#if editing}
	<ModalDialog title={isNew ? 'Add rate limit' : 'Edit rate limit'} onClose={() => (editing = null)} width={560}>
		<form class="grid gap-4 sm:grid-cols-2" onsubmit={commitEdit}>
			{#if formError}<p class="text-sm text-danger sm:col-span-2" role="alert">{formError}</p>{/if}
			<label class="block space-y-1 text-sm sm:col-span-2"><span class="text-text-muted">Name</span>
				<TextInput name="name" value={editing.name} required oninput={(v) => { if (editing) editing.name = String(v ?? ''); }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Algorithm</span>
				<SelectInput name="algorithm" value={editing.algorithm} options={ALGORITHMS} onchange={(v) => { if (editing) editing.algorithm = v; }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Action</span>
				<SelectInput name="action" value={editing.action} options={ACTIONS} onchange={(v) => { if (editing) editing.action = v; }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Limit</span>
				<TextInput name="limit" type="number" min={1} value={String(editing.limit)} oninput={(v) => { if (editing) editing.limit = toInt(v, 1); }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Interval (seconds)</span>
				<TextInput name="interval" type="number" min={1} value={String(editing.interval)} oninput={(v) => { if (editing) editing.interval = toInt(v, 60); }} />
			</label>
			{#if editing.algorithm === 'token_bucket'}
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Burst</span>
					<TextInput name="burst" type="number" min={1} value={String(editing.burst ?? '')} oninput={(v) => { if (editing) editing.burst = toInt(v, 1); }} />
				</label>
			{/if}
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Key by</span>
				<SelectInput name="keyBy" value={editing.keyBy} options={KEY_BY} onchange={(v) => { if (editing) editing.keyBy = v; }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Route group</span>
				<SelectInput name="routeGroup" value={editing.match.routeGroup} options={ROUTE_GROUPS} onchange={(v) => { if (editing) editing.match.routeGroup = v; }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Path prefix</span>
				<TextInput name="pathPrefix" value={editing.match.pathPrefix} oninput={(v) => { if (editing) editing.match.pathPrefix = String(v ?? ''); }} placeholder="/api/auth/login" />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Authenticated</span>
				<SelectInput name="authenticated" value={editing.match.authenticated} options={AUTH_OPTIONS} onchange={(v) => { if (editing) editing.match.authenticated = v; }} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Methods (one per line, empty = all)</span>
				<textarea class="admin-input w-full" rows="3" value={methodsText} oninput={(e) => (methodsText = (e.currentTarget as HTMLTextAreaElement).value)}></textarea>
			</label>
			{#if scope === 'global'}
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Inheritance</span>
					<SelectInput name="inheritance" value={editing.inheritance} options={INHERITANCE_OPTIONS} onchange={(v) => { if (editing) editing.inheritance = v; }} />
				</label>
			{/if}
			<div class="sm:col-span-2"><ToggleInput label="Enabled" value={editing.enabled} onchange={(v) => { if (editing) editing.enabled = v; }} /></div>
			<div class="flex justify-end gap-2 sm:col-span-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => (editing = null)}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">Apply</button>
			</div>
		</form>
	</ModalDialog>
{/if}
