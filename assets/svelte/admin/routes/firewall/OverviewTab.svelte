<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallEventEntry, FirewallIpBan, FirewallIpRule, FirewallScope, FirewallSimulationResult } from '$types';
	import DataTable from '$components/DataTable.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import Card from '$components/Card.svelte';
	import TextInput from '$components/TextInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import { errorMessage, formatTimestamp, isExpired } from './firewallHelpers';

	interface Props {
		api: ApiClient;
		scope: FirewallScope;
		siteId: string | null;
		onError: (message: string) => void;
	}

	let { api, scope, siteId, onError }: Props = $props();

	let loading = $state(true);
	let rules = $state<FirewallIpRule[]>([]);
	let bans = $state<FirewallIpBan[]>([]);
	let events = $state<FirewallEventEntry[]>([]);
	let rateLimitCount = $state(0);
	let botPosture = $state<Record<string, string>>({});

	let simulating = $state(false);
	let simulation = $state<FirewallSimulationResult | null>(null);
	let simIp = $state('203.0.113.10');
	let simMethod = $state('GET');
	let simPath = $state('/');
	let simUserAgent = $state('Mozilla/5.0');
	let simAuthenticated = $state(false);

	const METHOD_OPTIONS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'].map((m) => ({ value: m, label: m }));

	const activeRules = $derived(rules.filter((rule) => rule.enabled && !isExpired(rule)).length);
	const activeBans = $derived(bans.filter((ban) => !isExpired({ expiresAt: ban.expiresAt })).length);
	const botSummary = $derived.by(() => {
		const values = Object.values(botPosture);
		if (values.length === 0) return 'Not configured';
		const blocking = values.filter((value) => value === 'block' || value === 'challenge').length;
		return `${blocking} of ${values.length} categories restrict`;
	});

	const eventColumns = [
		{ key: 'createdAt', label: 'When', render: (value: string | null) => formatTimestamp(value) },
		{ key: 'action', label: 'Action' },
		{ key: 'summary', label: 'Summary' },
		{ key: 'ipAddress', label: 'IP', render: (value: string | null) => value ?? '—' },
		{ key: 'requestUri', label: 'Path', render: (value: string | null) => value ?? '—' },
	];

	async function load(): Promise<void> {
		loading = true;
		try {
			const [ruleItems, banItems, eventItems, protection] = await Promise.all([
				api.getFirewallIpRules(scope, siteId),
				api.getFirewallBans(scope, siteId),
				api.getFirewallEvents(scope, siteId),
				// Only site scope has an effective protection policy to summarize.
				scope === 'site' ? api.getFirewallSiteProtection(siteId as string) : Promise.resolve(null),
			]);
			rules = ruleItems;
			bans = banItems;
			events = eventItems.slice(0, 25);
			const rateLimits = protection?.rateLimits ?? [];
			rateLimitCount = rateLimits.filter((rule: { enabled?: boolean }) => rule.enabled !== false).length;
			botPosture = protection?.bots?.categoryPostures ?? {};
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load firewall overview.'));
		} finally {
			loading = false;
		}
	}

	async function runSimulation(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		simulating = true;
		try {
			simulation = await api.simulateFirewallRequest(scope, siteId, {
				ip: simIp,
				method: simMethod,
				path: simPath,
				userAgent: simUserAgent,
				authenticated: simAuthenticated,
				headers: { 'User-Agent': simUserAgent },
			});
		} catch (cause) {
			onError(errorMessage(cause, 'Simulation failed.'));
		} finally {
			simulating = false;
		}
	}

	onMount(() => { void load(); });
</script>

{#if loading}
	<LoadingState label="Loading overview…" />
{:else}
	<div class="space-y-6">
		<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
			<Card title="Active IP rules"><p class="text-2xl font-semibold text-text">{activeRules}</p></Card>
			<Card title="Active bans"><p class="text-2xl font-semibold text-text">{activeBans}</p></Card>
			<Card title="Enabled rate limits"><p class="text-2xl font-semibold text-text">{rateLimitCount}</p></Card>
			<Card title="Bot posture"><p class="text-sm text-text-muted">{botSummary}</p></Card>
		</div>

		<section class="admin-card p-5">
			<h2 class="mb-4 font-semibold text-text">Simulate request</h2>
			<form class="grid gap-4 md:grid-cols-2" onsubmit={runSimulation}>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">IP address</span>
					<TextInput name="ip" value={simIp} oninput={(v) => (simIp = String(v ?? ''))} />
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Method</span>
					<SelectInput name="method" value={simMethod} options={METHOD_OPTIONS} onchange={(v) => (simMethod = v)} />
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Path</span>
					<TextInput name="path" value={simPath} oninput={(v) => (simPath = String(v ?? '/'))} />
				</label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">User agent</span>
					<TextInput name="userAgent" value={simUserAgent} oninput={(v) => (simUserAgent = String(v ?? ''))} />
				</label>
				<div class="md:col-span-2"><ToggleInput label="Authenticated request" value={simAuthenticated} onchange={(v) => (simAuthenticated = v)} /></div>
				<div class="md:col-span-2">
					<button type="submit" class="admin-btn admin-btn-primary admin-btn-md" disabled={simulating}>
						{simulating ? 'Simulating…' : 'Run dry-run'}
					</button>
				</div>
			</form>

			{#if simulation}
				<div class="mt-6 space-y-3 border-t border-border pt-4 text-sm">
					<p><span class="text-text-muted">Decision:</span> <strong class="text-text">{simulation.decision || 'allow'}</strong></p>
					<div>
						<h3 class="mb-2 font-medium text-text">Per-stage findings</h3>
						<ul class="space-y-1 text-text-muted">
							<li>IP match: <code>{JSON.stringify(simulation.ipMatch)}</code></li>
							<li>Bot match: <code>{JSON.stringify(simulation.botMatch)}</code></li>
							<li>L7 DoS: <code>{JSON.stringify(simulation.dosResult)}</code></li>
							<li>Rate limits: <code>{JSON.stringify(simulation.rateLimits)}</code></li>
							<li>WAF findings: <code>{JSON.stringify(simulation.wafFindings)}</code></li>
						</ul>
					</div>
				</div>
			{/if}
		</section>

		<section>
			<h2 class="mb-3 font-semibold text-text">Recent events</h2>
			<DataTable data={events} columns={eventColumns} />
		</section>
	</div>
{/if}
