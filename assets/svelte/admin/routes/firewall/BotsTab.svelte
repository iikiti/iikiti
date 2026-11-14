<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type {
		FirewallAutomationAuthMode,
		FirewallBotCatalogEntry,
		FirewallBotCategory,
		FirewallBotConfig,
		FirewallPosture,
		FirewallProtectionPolicy,
		FirewallScope,
		FirewallTrustedAutomation,
	} from '$types';
	import LoadingState from '$components/LoadingState.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import TextInput from '$components/TextInput.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import Badge from '$components/Badge.svelte';
	import {
		BOT_CATEGORIES,
		BOT_CATEGORY_LABELS,
		POSTURE_OPTIONS,
		errorMessage,
		linesToList,
		listToLines,
		toInt,
	} from './firewallHelpers';
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

	const IMPERSONATION_OPTIONS = [
		{ value: 'block', label: 'Block' },
		{ value: 'report', label: 'Report only' },
	];
	const HONEYPOT_OPTIONS = IMPERSONATION_OPTIONS;
	const AUTH_MODE_OPTIONS: Array<{ value: FirewallAutomationAuthMode; label: string }> = [
		{ value: 'cidr', label: 'CIDR allowlist' },
		{ value: 'header_secret', label: 'Header secret' },
		{ value: 'hmac', label: 'HMAC-SHA256 signature' },
		{ value: 'http_signature', label: 'HTTP message signature (RFC 9421)' },
	];
	const OVERRIDE_OPTIONS = [{ value: '', label: 'Inherit category' }, ...POSTURE_OPTIONS];

	let loading = $state(true);
	let saving = $state(false);
	let version = $state(1);
	let required = $state(false);
	let carriedRateLimits = $state<FirewallProtectionPolicy['rateLimits']>([]);
	let carriedDos = $state<Record<string, unknown>>({});
	let bots = $state<FirewallBotConfig>(defaultBots());
	let honeypotPathsText = $state('');
	let catalog = $state<FirewallBotCatalogEntry[]>([]);
	let automations = $state<FirewallTrustedAutomation[]>([]);
	let automationOpen = $state(false);
	let automationSecretChanged = $state(false);
	let automationDraft = $state<FirewallTrustedAutomation>(blankAutomation());
	let automationCidrsText = $state('');
	let automationPathsText = $state('');
	let automationError = $state('');

	function defaultBots(): FirewallBotConfig {
		return {
			enabled: true,
			fcrdnsEnabled: false,
			blockImpersonators: true,
			impersonationAction: 'block',
			honeypotEnabled: false,
			honeypotPaths: [],
			honeypotAction: 'block',
			challengeDifficulty: 16,
			challengeTtl: 1800,
			categoryPostures: {
				search_engine: 'allow',
				ai_training: 'block',
				ai_agent: 'allow',
				monitoring: 'allow',
				social_preview: 'allow',
				malicious_scanner: 'block',
				custom_automation: 'allow',
			},
			botOverrides: {},
		};
	}

	function blankAutomation(): FirewallTrustedAutomation {
		return {
			id: null,
			siteId: null,
			name: '',
			handle: '',
			category: 'custom_automation',
			authMode: 'cidr',
			cidrs: [],
			headerName: null,
			publicKey: null,
			secret: null,
			hasSecret: false,
			allowedPaths: [],
			bypassWaf: false,
			rateLimitOverride: null,
			enabled: true,
			priority: 0,
		};
	}

	/** Per-bot overrides in a stable display order. */
	const overrideEntries = $derived(Object.entries(bots.botOverrides ?? {}));

	async function load(): Promise<void> {
		loading = true;
		try {
			const [policy, catalogItems, automationItems] = await Promise.all([
				loadProtectionPolicy(api, scope, applicationId, siteId),
				api.getFirewallBotCatalog(),
				api.getFirewallAutomations(scope, siteId),
			]);
			version = policy.version ?? 1;
			carriedRateLimits = policy.rateLimits ?? [];
			carriedDos = (policy.dos ?? {}) as Record<string, unknown>;
			required = Boolean((policy.bots as { required?: boolean } | undefined)?.required);
			const current = (policy.bots ?? {}) as Partial<FirewallBotConfig>;
			bots = { ...defaultBots(), ...current, categoryPostures: { ...defaultBots().categoryPostures, ...(current.categoryPostures ?? {}) } } as FirewallBotConfig;
			honeypotPathsText = listToLines(bots.honeypotPaths);
			catalog = catalogItems;
			automations = automationItems;
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load bot settings.'));
		} finally {
			loading = false;
		}
	}

	function setPosture(category: FirewallBotCategory, posture: string): void {
		bots.categoryPostures = { ...bots.categoryPostures, [category]: posture as FirewallPosture };
	}

	function setOverride(botId: string, posture: string): void {
		const next = { ...bots.botOverrides };
		if (posture === '') {
			delete next[botId];
		} else {
			next[botId] = { posture: posture as FirewallPosture };
		}
		bots.botOverrides = next;
	}

	async function savePolicy(): Promise<void> {
		saving = true;
		try {
			const payload = {
				version,
				rateLimits: carriedRateLimits,
				dos: carriedDos,
				bots: {
					...bots,
					honeypotPaths: linesToList(honeypotPathsText),
					...(scope === 'global' ? { required } : {}),
				},
			};
			const saved = await saveProtectionPolicy(api, scope, applicationId, siteId, payload);
			version = saved?.version ?? version;
			onNotify('Bot and AI policy saved.');
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to save bot policy.'));
		} finally {
			saving = false;
		}
	}

	function openAutomation(item?: FirewallTrustedAutomation): void {
		automationDraft = item ? structuredClone(item) : blankAutomation();
		automationCidrsText = listToLines(automationDraft.cidrs);
		automationPathsText = listToLines(automationDraft.allowedPaths);
		automationSecretChanged = false;
		automationError = '';
		automationOpen = true;
	}

	function automationPayload(): Record<string, unknown> {
		const payload: Record<string, unknown> = {
			name: automationDraft.name.trim(),
			handle: automationDraft.handle.trim(),
			category: automationDraft.category,
			authMode: automationDraft.authMode,
			cidrs: linesToList(automationCidrsText),
			headerName: automationDraft.headerName || null,
			publicKey: automationDraft.publicKey || null,
			allowedPaths: linesToList(automationPathsText),
			bypassWaf: automationDraft.bypassWaf,
			rateLimitOverride: automationDraft.rateLimitOverride,
			enabled: automationDraft.enabled,
			priority: toInt(automationDraft.priority, 0),
		};
		// The secret is write-only: only send it when the operator typed a new value.
		if (automationSecretChanged && automationDraft.secret) payload.secret = automationDraft.secret;
		return payload;
	}

	async function saveAutomation(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!automationDraft.name.trim() || !automationDraft.handle.trim()) {
			automationError = 'Name and handle are required.';
			return;
		}
		saving = true;
		automationError = '';
		try {
			const payload = automationPayload();
			if (automationDraft.id === null) {
				await api.createFirewallAutomation(scope, siteId, payload);
				onNotify('Trusted automation created.');
			} else {
				await api.updateFirewallAutomation(scope, siteId, automationDraft.id, payload);
				onNotify('Trusted automation updated.');
			}
			automationOpen = false;
			await load();
		} catch (cause) {
			automationError = errorMessage(cause, 'Unable to save automation.');
		} finally {
			saving = false;
		}
	}

	async function removeAutomation(item: FirewallTrustedAutomation): Promise<void> {
		if (!confirm(`Delete trusted automation "${item.name}"?`)) return;
		try {
			await api.deleteFirewallAutomation(scope, siteId, item.id);
			onNotify('Trusted automation deleted.');
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to delete automation.'));
		}
	}

	onMount(() => { void load(); });
</script>

{#if loading}
	<LoadingState label="Loading bot and AI policy…" />
{:else}
	<div class="space-y-8">
		<section class="admin-card space-y-4 p-5">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<h2 class="font-semibold text-text">Category posture matrix</h2>
				<button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick={savePolicy} disabled={saving}>{saving ? 'Saving…' : 'Save bot policy'}</button>
			</div>
			<div class="grid gap-3 sm:grid-cols-2">
				{#each BOT_CATEGORIES as category (category)}
					<label class="block space-y-1 text-sm">
						<span class="text-text-muted">{BOT_CATEGORY_LABELS[category]}</span>
						<SelectInput name={category} value={bots.categoryPostures[category]} options={POSTURE_OPTIONS} onchange={(v) => setPosture(category, v)} />
					</label>
				{/each}
			</div>

			<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				<ToggleInput label="Bot policy enabled" value={bots.enabled} onchange={(v) => (bots.enabled = v)} />
				<ToggleInput label="Block impersonators" value={bots.blockImpersonators} onchange={(v) => (bots.blockImpersonators = v)} />
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Impersonation action</span>
					<SelectInput name="impersonationAction" value={bots.impersonationAction} options={IMPERSONATION_OPTIONS} onchange={(v) => (bots.impersonationAction = v)} />
				</label>
				<ToggleInput label="FCrDNS verification (opt-in)" value={bots.fcrdnsEnabled} onchange={(v) => (bots.fcrdnsEnabled = v)} />
				<ToggleInput label="Honeypot traps enabled" value={bots.honeypotEnabled} onchange={(v) => (bots.honeypotEnabled = v)} />
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Honeypot action</span>
					<SelectInput name="honeypotAction" value={bots.honeypotAction} options={HONEYPOT_OPTIONS} onchange={(v) => (bots.honeypotAction = v)} />
				</label>
				<label class="block space-y-1 text-sm sm:col-span-2 xl:col-span-3"><span class="text-text-muted">Honeypot paths (one per line)</span>
					<textarea class="admin-input w-full" rows="3" value={honeypotPathsText} oninput={(e) => (honeypotPathsText = (e.currentTarget as HTMLTextAreaElement).value)}></textarea>
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Challenge difficulty (1–32)</span>
					<TextInput name="challengeDifficulty" type="number" min={1} max={32} value={String(bots.challengeDifficulty)} oninput={(v) => (bots.challengeDifficulty = toInt(v, 16))} />
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Challenge clearance TTL (seconds)</span>
					<TextInput name="challengeTtl" type="number" min={60} value={String(bots.challengeTtl)} oninput={(v) => (bots.challengeTtl = toInt(v, 1800))} />
				</label>
				{#if scope === 'global'}
					<div class="sm:col-span-2 xl:col-span-3"><ToggleInput label="Required (sites cannot weaken these settings)" value={required} onchange={(v) => (required = v)} /></div>
				{/if}
			</div>
		</section>

		<section class="space-y-3">
			<h2 class="font-semibold text-text">Known bot &amp; AI directory</h2>
			<div class="overflow-x-auto rounded-lg border border-border">
				<table class="admin-table min-w-full">
					<thead><tr><th>Bot</th><th>Category</th><th>Verification</th><th>Override</th></tr></thead>
					<tbody>
						{#each catalog as bot (bot.id)}
							<tr>
								<td>
									<div class="font-medium text-text">{bot.name}</div>
									<div class="text-xs text-text-muted">{bot.description}</div>
								</td>
								<td>{BOT_CATEGORY_LABELS[bot.category as FirewallBotCategory] ?? bot.category}</td>
								<td><Badge status="info">{bot.verificationMethod}</Badge></td>
								<td class="min-w-40">
									<SelectInput
										name={`override-${bot.id}`}
										value={bots.botOverrides?.[bot.id]?.posture ?? ''}
										options={OVERRIDE_OPTIONS}
										onchange={(v) => setOverride(bot.id, v)}
									/>
								</td>
							</tr>
						{:else}
							<tr><td colspan="4" class="text-center text-text-muted">No known bots in the catalog.</td></tr>
						{/each}
					</tbody>
				</table>
			</div>
			{#if overrideEntries.length > 0}
				<p class="text-xs text-text-muted">{overrideEntries.length} per-bot override(s) active. Save the bot policy to persist changes.</p>
			{/if}
		</section>

		<section class="space-y-3">
			<div class="flex items-center justify-between">
				<h2 class="font-semibold text-text">Custom trusted automations</h2>
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => openAutomation()}>Add automation</button>
			</div>
			<div class="overflow-x-auto rounded-lg border border-border">
				<table class="admin-table min-w-full">
					<thead><tr><th>Name</th><th>Auth</th><th>Secret</th><th>Bypass WAF</th><th>Status</th><th></th></tr></thead>
					<tbody>
						{#each automations as item (item.id)}
							<tr>
								<td>{item.name} <span class="text-xs text-text-muted">({item.handle})</span></td>
								<td>{item.authMode}</td>
								<td>{#if item.hasSecret}<Badge status="success">Configured</Badge>{:else}<span class="text-text-muted">—</span>{/if}</td>
								<td>{item.bypassWaf ? 'Yes' : 'No'}</td>
								<td><Badge status={item.enabled ? 'success' : 'warning'}>{item.enabled ? 'enabled' : 'disabled'}</Badge></td>
								<td class="space-x-3 text-right">
									<button type="button" class="admin-link" onclick={() => openAutomation(item)}>Edit</button>
									<button type="button" class="admin-link text-danger" onclick={() => void removeAutomation(item)}>Delete</button>
								</td>
							</tr>
						{:else}
							<tr><td colspan="6" class="text-center text-text-muted">No trusted automations configured.</td></tr>
						{/each}
					</tbody>
				</table>
			</div>
		</section>
	</div>
{/if}

{#if automationOpen}
	<ModalDialog title={automationDraft.id === null ? 'Add trusted automation' : 'Edit trusted automation'} onClose={() => (automationOpen = false)} width={600}>
		<form class="grid gap-4 sm:grid-cols-2" onsubmit={saveAutomation}>
			{#if automationError}<p class="text-sm text-danger sm:col-span-2" role="alert">{automationError}</p>{/if}
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Name</span>
				<TextInput name="name" value={automationDraft.name} required oninput={(v) => (automationDraft.name = String(v ?? ''))} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Handle</span>
				<TextInput name="handle" value={automationDraft.handle} required oninput={(v) => (automationDraft.handle = String(v ?? ''))} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Auth mode</span>
				<SelectInput name="authMode" value={automationDraft.authMode} options={AUTH_MODE_OPTIONS} onchange={(v) => (automationDraft.authMode = v)} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Category</span>
				<SelectInput name="category" value={automationDraft.category} options={BOT_CATEGORIES.map((c) => ({ value: c, label: BOT_CATEGORY_LABELS[c] }))} onchange={(v) => (automationDraft.category = v)} />
			</label>
			<label class="block space-y-1 text-sm sm:col-span-2"><span class="text-text-muted">CIDRs (one per line)</span>
				<TextareaInput name="cidrs" value={automationCidrsText} oninput={(v) => (automationCidrsText = String(v ?? ''))} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Header name</span>
				<TextInput name="headerName" value={automationDraft.headerName ?? ''} oninput={(v) => (automationDraft.headerName = String(v ?? '') || null)} placeholder="X-Automation-Key" />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Secret <span class="text-xs">(write-only)</span></span>
				<div class="flex items-center gap-2">
					<TextInput name="secret" type="password" value={automationDraft.secret ?? ''} oninput={(v) => { automationDraft.secret = String(v ?? ''); automationSecretChanged = true; }} />
					{#if automationDraft.hasSecret && !automationSecretChanged}<Badge status="success">Configured</Badge>{/if}
				</div>
			</label>
			<label class="block space-y-1 text-sm sm:col-span-2"><span class="text-text-muted">Public key (http_signature)</span>
				<TextareaInput name="publicKey" value={automationDraft.publicKey ?? ''} oninput={(v) => (automationDraft.publicKey = String(v ?? '') || null)} />
			</label>
			<label class="block space-y-1 text-sm sm:col-span-2"><span class="text-text-muted">Allowed paths (one prefix per line)</span>
				<TextareaInput name="allowedPaths" value={automationPathsText} oninput={(v) => (automationPathsText = String(v ?? ''))} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Priority</span>
				<TextInput name="priority" type="number" value={String(automationDraft.priority)} oninput={(v) => (automationDraft.priority = toInt(v, 0))} />
			</label>
			<div class="flex flex-col gap-3">
				<ToggleInput label="Bypass WAF" value={automationDraft.bypassWaf} onchange={(v) => (automationDraft.bypassWaf = v)} />
				<ToggleInput label="Enabled" value={automationDraft.enabled} onchange={(v) => (automationDraft.enabled = v)} />
			</div>
			<div class="flex justify-end gap-2 sm:col-span-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => (automationOpen = false)}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
			</div>
		</form>
	</ModalDialog>
{/if}
