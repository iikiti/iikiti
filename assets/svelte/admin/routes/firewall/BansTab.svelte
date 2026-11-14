<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import type { FirewallIpBan, FirewallScope } from '$types';
	import LoadingState from '$components/LoadingState.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import TextInput from '$components/TextInput.svelte';
	import TextareaInput from '$components/TextareaInput.svelte';
	import Badge from '$components/Badge.svelte';
	import DataTable from '$components/DataTable.svelte';
	import { errorMessage, formatTimestamp, toInt } from './firewallHelpers';

	interface Props {
		api: ApiClient;
		scope: FirewallScope;
		siteId: string | null;
		onNotify: (message: string) => void;
		onError: (message: string) => void;
	}

	let { api, scope, siteId, onNotify, onError }: Props = $props();

	let loading = $state(true);
	let bans = $state<FirewallIpBan[]>([]);
	let banOpen = $state(false);
	let saving = $state(false);
	let formError = $state('');
	let banCidr = $state('');
	let banReason = $state('');
	let banTtl = $state(3600);

	const isGlobal = $derived(scope === 'global');

	const columns = [
		{ key: 'cidr', label: 'IP / CIDR' },
		{ key: 'reasonCode', label: 'Reason code' },
		{ key: 'reasonMessage', label: 'Reason' },
		{ key: 'strikes', label: 'Strikes' },
		{ key: 'expiresAt', label: 'Expires', render: (value: string | null) => formatTimestamp(value) },
	];

	async function load(): Promise<void> {
		loading = true;
		try {
			bans = await api.getFirewallBans(scope, siteId);
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to load active bans.'));
		} finally {
			loading = false;
		}
	}

	async function createBan(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		if (!banCidr.trim()) {
			formError = 'IP or CIDR is required.';
			return;
		}
		saving = true;
		formError = '';
		try {
			await api.createFirewallBan({
				cidr: banCidr.trim(),
				reasonCode: 'manual',
				reasonMessage: banReason.trim() || 'Manual ban from the admin console',
				ttlSeconds: toInt(banTtl, 3600),
			});
			onNotify('Ban created.');
			banOpen = false;
			banCidr = '';
			banReason = '';
			await load();
		} catch (cause) {
			formError = errorMessage(cause, 'Unable to create ban.');
		} finally {
			saving = false;
		}
	}

	async function unban(ban: FirewallIpBan): Promise<void> {
		try {
			await api.deleteFirewallBan(scope, siteId, ban.id);
			onNotify(`Unbanned ${ban.cidr}.`);
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to remove ban.'));
		}
	}

	/** Promotes an active ban to a permanent block rule in the same scope. */
	async function promote(ban: FirewallIpBan): Promise<void> {
		try {
			await api.createFirewallIpRule(scope, siteId, {
				cidr: ban.cidr,
				action: 'block',
				inheritance: 'default',
				reason: `Promoted from ban (${ban.reasonCode})`,
				enabled: true,
				priority: 0,
			});
			await api.deleteFirewallBan(scope, siteId, ban.id);
			onNotify(`Promoted ${ban.cidr} to a permanent block rule.`);
			await load();
		} catch (cause) {
			onError(errorMessage(cause, 'Unable to promote ban.'));
		}
	}

	onMount(() => { void load(); });
</script>

<div class="space-y-4">
	<div class="flex items-center justify-between">
		<p class="text-sm text-text-muted">Temporary and manual bans are enforced before body inspection.</p>
		{#if isGlobal}
			<button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick={() => { formError = ''; banOpen = true; }}>Ban IP/CIDR</button>
		{/if}
	</div>

	{#if loading}
		<LoadingState label="Loading bans…" />
	{:else}
		<DataTable data={bans} {columns} />
		<ul class="space-y-2">
			{#each bans as ban (ban.id)}
				<li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface p-3 text-sm">
					<div class="flex items-center gap-2">
						<code class="text-text">{ban.cidr}</code>
						<Badge status="danger">{ban.reasonCode || 'ban'}</Badge>
						<span class="text-text-muted">strikes {ban.strikes}</span>
					</div>
					<div class="flex gap-3">
						<button type="button" class="admin-link" onclick={() => void unban(ban)}>Unban</button>
						{#if isGlobal}
							<button type="button" class="admin-link" onclick={() => void promote(ban)}>Promote to permanent block rule</button>
						{/if}
					</div>
				</li>
			{/each}
		</ul>
	{/if}
</div>

{#if banOpen}
	<ModalDialog title="Ban IP/CIDR" onClose={() => (banOpen = false)}>
		<form class="space-y-4" onsubmit={createBan}>
			{#if formError}<p class="text-sm text-danger" role="alert">{formError}</p>{/if}
			<label class="block space-y-1 text-sm"><span class="text-text-muted">IP or CIDR</span>
				<TextInput name="cidr" value={banCidr} required oninput={(v) => (banCidr = String(v ?? ''))} placeholder="198.51.100.7" />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Duration (seconds)</span>
				<TextInput name="ttl" type="number" min={60} value={String(banTtl)} oninput={(v) => (banTtl = toInt(v, 3600))} />
			</label>
			<label class="block space-y-1 text-sm"><span class="text-text-muted">Reason</span>
				<TextareaInput name="reason" value={banReason} oninput={(v) => (banReason = String(v ?? ''))} />
			</label>
			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={() => (banOpen = false)}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" disabled={saving}>{saving ? 'Banning…' : 'Ban'}</button>
			</div>
		</form>
	</ModalDialog>
{/if}
