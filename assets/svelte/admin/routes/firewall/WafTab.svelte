<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallRulePack, FirewallWafRule } from '$types';
	import LoadingState from '$components/LoadingState.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import Badge from '$components/Badge.svelte';
	import { errorMessage } from './firewallHelpers';

	interface Props {
		api: ApiClient;
		siteId: string | null;
		onNotify: (message: string) => void;
		onError: (message: string) => void;
	}

	let { api, siteId, onNotify, onError }: Props = $props();

	const MODE_OPTIONS = [
		{ value: 'deny', label: 'Deny (block)' },
		{ value: 'report', label: 'Report only' },
	];

	let loading = $state(true);
	let saving = $state(false);
	let rules = $state<FirewallWafRule[]>([]);
	let rulesJson = $state('[]');
	let jsonError = $state('');
	let packs = $state<FirewallRulePack[]>([]);
	let activatingKey = $state<string | null>(null);
	let selectedVersions = $state<Record<string, string>>({});

	/** Summary of each rule shown beside the editor for quick review. */
	const summaries = $derived(
		rules.map((rule, index) => ({
			index,
			label: rule.name || rule.id || `Rule ${index + 1}`,
			mode: rule.mode,
		})),
	);

	async function load(): Promise<void> {
		loading = true;
		try {
			const [policy, packItems] = await Promise.all([
				api.getFirewallWafPolicy(siteId as string),
				api.getFirewallRulePacks(),
			]);
			rules = (policy.rules ?? []) as FirewallWafRule[];
			rulesJson = JSON.stringify(rules, null, 2);
			packs = packItems;
			selectedVersions = Object.fromEntries(
				packItems.map((pack: FirewallRulePack) => [pack.key, pack.activeVersion ?? pack.versions[0] ?? '']),
			);
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load WAF rules.'));
		} finally {
			loading = false;
		}
	}

	/** Parses the editor and validates each rule's mode before sending. */
	function parseRules(): FirewallWafRule[] | null {
		let parsed: unknown;
		try {
			parsed = JSON.parse(rulesJson);
		} catch {
			jsonError = 'Rules must be valid JSON.';
			return null;
		}
		if (!Array.isArray(parsed)) {
			jsonError = 'Rules must be a JSON array.';
			return null;
		}
		const invalid = parsed.find((rule) => !rule || typeof rule !== 'object' || !['deny', 'report'].includes((rule as FirewallWafRule).mode));
		if (invalid) {
			jsonError = 'Every rule needs mode "deny" or "report".';
			return null;
		}
		jsonError = '';
		return parsed as FirewallWafRule[];
	}

	/** Changes a single rule's mode in place, then syncs the JSON editor. */
	function setRuleMode(index: number, mode: string): void {
		const parsed = parseRules();
		if (!parsed) return;
		parsed[index] = { ...parsed[index], mode: mode as 'deny' | 'report' };
		rulesJson = JSON.stringify(parsed, null, 2);
		rules = parsed;
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		const parsed = parseRules();
		if (!parsed) return;
		saving = true;
		try {
			await api.updateFirewallWafPolicy(siteId as string, parsed);
			onNotify('WAF rules saved.');
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to save WAF rules.'));
		} finally {
			saving = false;
		}
	}

	async function activate(pack: FirewallRulePack): Promise<void> {
		const version = selectedVersions[pack.key];
		if (!version) return;
		activatingKey = pack.key;
		try {
			await api.activateFirewallRulePack(pack.key, version);
			onNotify(`Activated ${pack.name} ${version}.`);
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to activate rule pack.'));
		} finally {
			activatingKey = null;
		}
	}

	onMount(() => { void load(); });
</script>

{#if loading}
	<LoadingState label="Loading WAF rules…" />
{:else}
	<div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
		<section class="admin-card space-y-4 p-5">
			<h2 class="font-semibold text-text">Site WAF rules</h2>
			{#if summaries.length === 0}
				<p class="text-sm text-text-muted">No site WAF rules yet. Add rules as a JSON array below.</p>
			{:else}
				<ul class="space-y-2">
					{#each summaries as item (item.index)}
						<li class="flex items-center justify-between gap-3 rounded-md border border-border p-2 text-sm">
							<span class="text-text">{item.label}</span>
							<div class="w-44">
								<SelectInput
									name={`mode-${item.index}`}
									value={item.mode}
									options={MODE_OPTIONS}
									onchange={(v) => setRuleMode(item.index, v)}
								/>
							</div>
						</li>
					{/each}
				</ul>
			{/if}

			<form class="space-y-3" onsubmit={save}>
				<label class="block space-y-1 text-sm">
					<span class="text-text-muted">Rule JSON</span>
					<TextareaInput name="rules" rows={14} value={rulesJson} oninput={(v) => (rulesJson = String(v ?? ''))} />
				</label>
				{#if jsonError}<p class="text-sm text-danger" role="alert">{jsonError}</p>{/if}
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>{saving ? 'Saving…' : 'Save WAF rules'}</button>
			</form>
		</section>

		<section class="admin-card space-y-4 p-5">
			<h2 class="font-semibold text-text">Signed rule packs</h2>
			{#if packs.length === 0}
				<p class="text-sm text-text-muted">No signed rule packs are registered.</p>
			{:else}
				<ul class="space-y-4">
					{#each packs as pack (pack.key)}
						<li class="space-y-2 rounded-md border border-border p-3 text-sm">
							<div class="flex items-center justify-between gap-2">
								<div>
									<div class="font-medium text-text">{pack.name}</div>
									<div class="text-xs text-text-muted">{pack.key}</div>
								</div>
								<Badge status={pack.activeVersion ? 'success' : 'warning'}>{pack.activeVersion ? `active ${pack.activeVersion}` : 'inactive'}</Badge>
							</div>
							{#if pack.checksum}<p class="text-xs text-text-muted">Checksum <code>{pack.checksum.slice(0, 16)}…</code></p>{/if}
							<div class="flex items-end gap-2">
								<div class="flex-1">
									<SelectInput
										name={`version-${pack.key}`}
										value={selectedVersions[pack.key] ?? ''}
										options={pack.versions.map((v) => ({ value: v, label: v }))}
										onchange={(v) => (selectedVersions = { ...selectedVersions, [pack.key]: v })}
									/>
								</div>
								<button
									type="button"
									class="admin-btn admin-btn-secondary admin-btn-sm"
									disabled={activatingKey === pack.key || !selectedVersions[pack.key]}
									onclick={() => void activate(pack)}
								>
									{activatingKey === pack.key ? 'Activating…' : 'Activate'}
								</button>
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</section>
	</div>
{/if}
