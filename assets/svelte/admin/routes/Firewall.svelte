<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import PageHeader from '$components/PageHeader.svelte';
	import Tabs from '$components/Tabs.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import FirewallOverviewTab from './firewall/OverviewTab.svelte';
	import FirewallIpRulesTab from './firewall/IpRulesTab.svelte';
	import FirewallBansTab from './firewall/BansTab.svelte';
	import FirewallRateLimitsTab from './firewall/RateLimitsTab.svelte';
	import FirewallDosTab from './firewall/DosTab.svelte';
	import FirewallBotsTab from './firewall/BotsTab.svelte';
	import FirewallWafTab from './firewall/WafTab.svelte';
	import type { FirewallScope } from '$types';

	interface Props {
		api: ApiClient;
		debug?: boolean;
	}

	interface ApplicationOption { id: number | string; name: string; }
	interface SiteOption { id: number | string; name?: string | null; domain?: string | null; }

	type TabValue = 'overview' | 'ip-rules' | 'bans' | 'rate-limits' | 'dos' | 'bots' | 'waf';

	let { api }: Props = $props();

	const TABS: Array<{ label: string; value: TabValue }> = [
		{ label: 'Overview & Simulator', value: 'overview' },
		{ label: 'IP Allow & Blocklist', value: 'ip-rules' },
		{ label: 'Active Bans', value: 'bans' },
		{ label: 'Rate Limiting', value: 'rate-limits' },
		{ label: 'L7 DoS Protection', value: 'dos' },
		{ label: 'Bots, AI & Automations', value: 'bots' },
		{ label: 'WAF Rules & Packs', value: 'waf' },
	];

	let activeTab = $state<TabValue>('overview');
	let scope = $state<FirewallScope>('global');
	let applications = $state<ApplicationOption[]>([]);
	let sites = $state<SiteOption[]>([]);
	let applicationId = $state<string>('');
	let siteId = $state<string>('');
	let loading = $state(true);
	let error = $state('');
	let notice = $state('');

	/**
	 * Global scope targets the active application; site scope targets the
	 * site matching the current host (falling back to the first site).
	 */
	const scopeSiteId = $derived(scope === 'site' ? siteId : null);
	const hasScopeTarget = $derived(scope === 'global' ? applicationId !== '' : siteId !== '');

	/** Matches the current host, so the admin's own site is preselected. */
	function resolveCurrentSite(list: SiteOption[]): string {
		const host = (api as unknown as { siteHost?: string }).siteHost ?? '';
		const match = host ? list.find((site) => (site.domain ?? '') === host) : undefined;
		return String((match ?? list[0])?.id ?? '');
	}

	async function loadScopes(): Promise<void> {
		loading = true;
		error = '';
		try {
			const [appPage, sitePage] = await Promise.all([api.getApplications(), api.getSites()]);
			applications = (appPage.members ?? []) as ApplicationOption[];
			sites = (sitePage.members ?? []) as SiteOption[];
			if (!applicationId && applications.length) applicationId = String(applications[0].id);
			if (!siteId && sites.length) siteId = resolveCurrentSite(sites);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load firewall scopes.';
		} finally {
			loading = false;
		}
	}

	function setScope(next: FirewallScope): void {
		scope = next;
		notice = '';
	}

	function notify(message: string): void {
		notice = message;
	}

	function fail(message: string): void {
		error = message;
	}

	onMount(() => {
		void loadScopes();
	});
</script>

<PageHeader
	title="Firewall"
	description="Manage IP rules, rate limits, Layer 7 DoS protections, bot/AI policies, and WAF rules."
/>

<section class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-border bg-surface p-4">
	<div class="flex gap-2" role="radiogroup" aria-label="Firewall scope">
		<button
			type="button"
			role="radio"
			aria-checked={scope === 'global'}
			class="admin-btn admin-btn-sm {scope === 'global' ? 'admin-btn-primary' : 'admin-btn-secondary'}"
			onclick={() => setScope('global')}
		>
			Global (Application)
		</button>
		<button
			type="button"
			role="radio"
			aria-checked={scope === 'site'}
			class="admin-btn admin-btn-sm {scope === 'site' ? 'admin-btn-primary' : 'admin-btn-secondary'}"
			onclick={() => setScope('site')}
		>
			Current Site
		</button>
	</div>

	{#if scope === 'global'}
		<label class="block min-w-56 space-y-1 text-sm">
			<span class="text-text-muted">Application</span>
			<select class="admin-input w-full" bind:value={applicationId} disabled={applications.length === 0}>
				{#each applications as application (application.id)}
					<option value={String(application.id)}>{application.name}</option>
				{/each}
			</select>
		</label>
	{:else}
		<label class="block min-w-56 space-y-1 text-sm">
			<span class="text-text-muted">Site</span>
			<select class="admin-input w-full" bind:value={siteId} disabled={sites.length === 0}>
				{#each sites as site (site.id)}
					<option value={String(site.id)}>{site.name || site.domain || `Site ${site.id}`}</option>
				{/each}
			</select>
		</label>
	{/if}
</section>

{#if error}
	<ErrorBoundary message={error} onRetry={loadScopes} />
{:else if loading}
	<LoadingState label="Loading firewall scopes…" />
{:else if !hasScopeTarget}
	<p class="text-sm text-text-muted">No {scope === 'global' ? 'application' : 'site'} is available for this scope.</p>
{:else}
	{#if notice}<p class="mb-4 text-sm text-success" role="status">{notice}</p>{/if}

	<div class="mb-6">
		<Tabs
			tabs={TABS}
			activeValue={activeTab}
			onSelect={(value) => { activeTab = value as TabValue; notice = ''; }}
		/>
	</div>

	{#key `${scope}:${scope === 'global' ? applicationId : siteId}`}
		{#if activeTab === 'overview'}
			<FirewallOverviewTab {api} {scope} siteId={scopeSiteId} onError={fail} />
		{:else if activeTab === 'ip-rules'}
			<FirewallIpRulesTab {api} {scope} siteId={scopeSiteId} onNotify={notify} onError={fail} />
		{:else if activeTab === 'bans'}
			<FirewallBansTab {api} {scope} siteId={scopeSiteId} onNotify={notify} onError={fail} />
		{:else if activeTab === 'rate-limits'}
			<FirewallRateLimitsTab {api} {scope} {applicationId} siteId={scopeSiteId} onNotify={notify} onError={fail} />
		{:else if activeTab === 'dos'}
			<FirewallDosTab {api} {scope} {applicationId} siteId={scopeSiteId} onNotify={notify} onError={fail} />
		{:else if activeTab === 'bots'}
			<FirewallBotsTab {api} {scope} {applicationId} siteId={scopeSiteId} onNotify={notify} onError={fail} />
		{:else if activeTab === 'waf'}
			<FirewallWafTab {api} siteId={siteId} onNotify={notify} onError={fail} />
		{/if}
	{/key}
{/if}
