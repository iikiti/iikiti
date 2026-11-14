<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallDosAction, FirewallDosConfig, FirewallProtectionPolicy, FirewallScope } from '$types';
	import LoadingState from '$components/LoadingState.svelte';
	import TextInput from '$components/TextInput.svelte';
	import SelectInput from '$components/SelectInput.svelte';
	import ToggleInput from '$components/ToggleInput.svelte';
	import { errorMessage, linesToList, listToLines, toInt } from './firewallHelpers';
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

	const ACTION_OPTIONS: Array<{ value: FirewallDosAction; label: string }> = [
		{ value: 'report', label: 'Report only' },
		{ value: 'enforce', label: 'Enforce (block)' },
	];

	let loading = $state(true);
	let saving = $state(false);
	let version = $state(1);
	let carriedRateLimits = $state<FirewallProtectionPolicy['rateLimits']>([]);
	let carriedBots = $state<Record<string, unknown>>({});
	let required = $state(false);
	let dos = $state<FirewallDosConfig>(defaultDos());
	let autoBanDurationsText = $state('');
	let bodyCaps = $state({ auth: 65536, forms: 1048576, api: 8388608, public: 262144 });

	function defaultDos(): FirewallDosConfig {
		return {
			enabled: true,
			maxHeaderCount: 100,
			maxHeaderBytes: 32768,
			maxUriLength: 4096,
			maxQueryParams: 128,
			maxCookieCount: 50,
			maxCookieBytes: 16384,
			maxBodyBytesByGroup: { auth: 65536, forms: 1048576, api: 8388608, public: 262144 },
			errorStormThreshold: 50,
			errorStormWindow: 60,
			errorStormAction: 'report',
			expensiveRouteConcurrencyPerIp: 10,
			expensiveRouteConcurrencyPerSite: 100,
			concurrencyAction: 'report',
			autoBanEnabled: true,
			autoBanAction: 'report',
			autoBanWindow: 300,
			autoBanStrikeThreshold: 5,
			autoBanDurations: [300, 1800, 21600, 86400],
		};
	}

	async function load(): Promise<void> {
		loading = true;
		try {
			const policy = await loadProtectionPolicy(api, scope, applicationId, siteId);
			version = policy.version ?? 1;
			carriedRateLimits = policy.rateLimits ?? [];
			carriedBots = (policy.bots ?? {}) as Record<string, unknown>;
			const current = (policy.dos ?? {}) as Partial<FirewallDosConfig> & { required?: boolean };
			required = Boolean(current.required);
			dos = { ...defaultDos(), ...current } as FirewallDosConfig;
			bodyCaps = { ...bodyCaps, ...(dos.maxBodyBytesByGroup ?? {}) };
			autoBanDurationsText = listToLines((dos.autoBanDurations ?? []).map(String));
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load DoS settings.'));
		} finally {
			loading = false;
		}
	}

	async function save(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		saving = true;
		try {
			const durations = linesToList(autoBanDurationsText).map((line) => toInt(line, 0)).filter((s) => s > 0);
			const payload = {
				version,
				rateLimits: carriedRateLimits,
				bots: carriedBots,
				dos: {
					...dos,
					maxBodyBytesByGroup: bodyCaps,
					autoBanDurations: durations,
					// `required` is only meaningful at Application scope.
					...(scope === 'global' ? { required } : {}),
				},
			};
			const saved = await saveProtectionPolicy(api, scope, applicationId, siteId, payload);
			version = saved?.version ?? version;
			onNotify('L7 DoS protection saved.');
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to save DoS settings.'));
		} finally {
			saving = false;
		}
	}

	onMount(() => { void load(); });
</script>

{#if loading}
	<LoadingState label="Loading DoS settings…" />
{:else}
	<form class="space-y-6" onsubmit={save}>
		<div class="flex flex-wrap items-center justify-between gap-4">
			<ToggleInput label="L7 DoS protection enabled" value={dos.enabled} onchange={(v) => (dos.enabled = v)} />
			{#if scope === 'global'}
				<ToggleInput label="Required (sites cannot weaken these settings)" value={required} onchange={(v) => (required = v)} />
			{/if}
			<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>{saving ? 'Saving…' : 'Save DoS settings'}</button>
		</div>

		<section class="admin-card space-y-4 p-5">
			<h2 class="font-semibold text-text">Early structural limits</h2>
			<p class="text-sm text-text-muted">Enforced before body or upload parsing.</p>
			<div class="grid gap-4 sm:grid-cols-3">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max header count</span><TextInput name="maxHeaderCount" type="number" min={1} value={String(dos.maxHeaderCount)} oninput={(v) => (dos.maxHeaderCount = toInt(v, 100))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max header bytes</span><TextInput name="maxHeaderBytes" type="number" min={1} value={String(dos.maxHeaderBytes)} oninput={(v) => (dos.maxHeaderBytes = toInt(v, 32768))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max URI length</span><TextInput name="maxUriLength" type="number" min={1} value={String(dos.maxUriLength)} oninput={(v) => (dos.maxUriLength = toInt(v, 4096))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max query params</span><TextInput name="maxQueryParams" type="number" min={1} value={String(dos.maxQueryParams)} oninput={(v) => (dos.maxQueryParams = toInt(v, 128))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max cookie count</span><TextInput name="maxCookieCount" type="number" min={1} value={String(dos.maxCookieCount)} oninput={(v) => (dos.maxCookieCount = toInt(v, 50))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Max cookie bytes</span><TextInput name="maxCookieBytes" type="number" min={1} value={String(dos.maxCookieBytes)} oninput={(v) => (dos.maxCookieBytes = toInt(v, 16384))} /></label>
			</div>
			<h3 class="pt-2 text-sm font-medium text-text">Per-group body caps (bytes)</h3>
			<div class="grid gap-4 sm:grid-cols-4">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Auth</span><TextInput name="bodyAuth" type="number" min={1} value={String(bodyCaps.auth)} oninput={(v) => (bodyCaps.auth = toInt(v, bodyCaps.auth))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Forms</span><TextInput name="bodyForms" type="number" min={1} value={String(bodyCaps.forms)} oninput={(v) => (bodyCaps.forms = toInt(v, bodyCaps.forms))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Public API</span><TextInput name="bodyApi" type="number" min={1} value={String(bodyCaps.api)} oninput={(v) => (bodyCaps.api = toInt(v, bodyCaps.api))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Public</span><TextInput name="bodyPublic" type="number" min={1} value={String(bodyCaps.public)} oninput={(v) => (bodyCaps.public = toInt(v, bodyCaps.public))} /></label>
			</div>
		</section>

		<section class="admin-card space-y-4 p-5">
			<h2 class="font-semibold text-text">Behavioral guards</h2>
			<div class="grid gap-4 sm:grid-cols-3">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Error-storm threshold (4xx count)</span><TextInput name="errorStormThreshold" type="number" min={1} value={String(dos.errorStormThreshold)} oninput={(v) => (dos.errorStormThreshold = toInt(v, 50))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Error-storm window (seconds)</span><TextInput name="errorStormWindow" type="number" min={1} value={String(dos.errorStormWindow)} oninput={(v) => (dos.errorStormWindow = toInt(v, 60))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Error-storm action</span><SelectInput name="errorStormAction" value={dos.errorStormAction} options={ACTION_OPTIONS} onchange={(v) => (dos.errorStormAction = v)} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Expensive-route concurrency per IP</span><TextInput name="concurrencyIp" type="number" min={1} value={String(dos.expensiveRouteConcurrencyPerIp)} oninput={(v) => (dos.expensiveRouteConcurrencyPerIp = toInt(v, 10))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Expensive-route concurrency per site</span><TextInput name="concurrencySite" type="number" min={1} value={String(dos.expensiveRouteConcurrencyPerSite)} oninput={(v) => (dos.expensiveRouteConcurrencyPerSite = toInt(v, 100))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Concurrency action</span><SelectInput name="concurrencyAction" value={dos.concurrencyAction} options={ACTION_OPTIONS} onchange={(v) => (dos.concurrencyAction = v)} /></label>
			</div>
		</section>

		<section class="admin-card space-y-4 p-5">
			<h2 class="font-semibold text-text">Auto-ban escalation</h2>
			<ToggleInput label="Auto-ban enabled" value={dos.autoBanEnabled} onchange={(v) => (dos.autoBanEnabled = v)} />
			<div class="grid gap-4 sm:grid-cols-3">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Auto-ban action</span><SelectInput name="autoBanAction" value={dos.autoBanAction} options={ACTION_OPTIONS} onchange={(v) => (dos.autoBanAction = v)} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Strike window (seconds)</span><TextInput name="autoBanWindow" type="number" min={1} value={String(dos.autoBanWindow)} oninput={(v) => (dos.autoBanWindow = toInt(v, 300))} /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Strike threshold</span><TextInput name="autoBanStrikeThreshold" type="number" min={1} value={String(dos.autoBanStrikeThreshold)} oninput={(v) => (dos.autoBanStrikeThreshold = toInt(v, 5))} /></label>
			</div>
			<label class="block space-y-1 text-sm">
				<span class="text-text-muted">Escalating ban durations (seconds, one per line)</span>
				<textarea class="admin-input w-full" rows="4" value={autoBanDurationsText} oninput={(e) => (autoBanDurationsText = (e.currentTarget as HTMLTextAreaElement).value)}></textarea>
			</label>
		</section>
	</form>
{/if}
