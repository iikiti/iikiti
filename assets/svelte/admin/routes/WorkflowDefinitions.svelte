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

	interface DefinitionVersion {
		id: string | number;
		version: number;
		policyKey: string | null;
	}

	interface WorkflowDefinition {
		id: string | number;
		key: string;
		name: string;
		ownerType: 'core' | 'site' | 'plugin';
		ownerKey: string;
		currentVersion: number;
		enabled: boolean;
		archived: boolean;
		canEdit: boolean;
		siteIds: Array<string | number>;
		versions: DefinitionVersion[];
		graph?: Record<string, unknown>;
	}

	let { api }: Props = $props();
	let sites = $state<SiteOption[]>([]);
	let definitions = $state<WorkflowDefinition[]>([]);
	let selectedSiteId = $state('');
	let selected = $state<WorkflowDefinition | null>(null);
	let key = $state('');
	let name = $state('');
	let ownerType = $state<'site' | 'core'>('site');
	let executionMode = $state<'durable' | 'session'>('durable');
	let graphText = $state('');
	let policyKeys = $state<string[]>([]);
	let actions = $state<string[]>([]);
	let assignmentResolvers = $state<string[]>([]);
	let mfaFactors = $state<string[]>([]);
	let assetProcessors = $state<string[]>([]);
	let subjectResolvers = $state<string[]>([]);
	let canCreateCore = $state(false);
	let showAllDefinitions = $state(false);
	let loading = $state(true);
	let saving = $state(false);
	let error = $state('');
	let message = $state('');

	const defaultGraph = (): string => JSON.stringify({
		type: 'workflow',
		places: [
			{ id: 'start', metadata: { label: 'Start' } },
			{ id: 'complete', metadata: { label: 'Complete' } },
		],
		transitions: [{ name: 'finish', from: 'start', to: 'complete' }],
		initialPlaces: ['start'],
		finalPlaces: ['complete'],
		metadata: { mode: 'durable', policyKey: 'core.site_workflow' },
	}, null, 2);

	async function loadSites(): Promise<void> {
		const response = await api.request('/admin/workflows/sites');
		sites = response.sites ?? [];
		canCreateCore = response.canCreateCore ?? false;
		if ('' === selectedSiteId) {
			selectedSiteId = String(response.defaultSiteId ?? sites[0]?.id ?? '');
		}
	}

	async function loadDefinitions(): Promise<void> {
		if ('' === selectedSiteId) {
			definitions = [];
			return;
		}
		const query = showAllDefinitions ? '?all=1' : `?siteId=${encodeURIComponent(selectedSiteId)}`;
		const response = await api.request(`/admin/workflows/definitions${query}`);
		definitions = response.definitions ?? [];
	}

	async function loadCatalog(): Promise<void> {
		const response = await api.request('/admin/workflows/catalog');
		policyKeys = response.policies ?? [];
		actions = response.actions ?? [];
		assignmentResolvers = response.assignmentResolvers ?? [];
		mfaFactors = response.mfaFactors ?? [];
		assetProcessors = response.assetProcessors ?? [];
		subjectResolvers = response.subjectResolvers ?? [];
	}

	async function load(): Promise<void> {
		loading = true;
		error = '';
		try {
			await loadSites();
			await Promise.all([loadDefinitions(), loadCatalog()]);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load workflow definitions.';
		} finally {
			loading = false;
		}
	}

	async function selectDefinition(definition: WorkflowDefinition): Promise<void> {
		selected = definition;
		key = definition.key;
		name = definition.name;
		ownerType = definition.ownerType === 'core' ? 'core' : 'site';
		error = '';
		try {
			const scope = showAllDefinitions ? '?siteId=' : `?siteId=${encodeURIComponent(selectedSiteId)}`;
			const response = await api.request(`/admin/workflows/definitions/${definition.id}${scope}`);
			selected = response;
			graphText = JSON.stringify(response.graph ?? {}, null, 2);
			executionMode = response.graph?.metadata?.mode === 'session' ? 'session' : 'durable';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load the selected definition.';
		}
	}

	function newDefinition(): void {
		selected = null;
		key = '';
		name = '';
		ownerType = 'site';
		executionMode = 'durable';
		graphText = defaultGraph();
		message = '';
		error = '';
	}

	async function save(): Promise<void> {
		error = '';
		message = '';
		let graph: Record<string, unknown>;
		try {
			graph = JSON.parse(graphText);
			const metadata = (graph.metadata && typeof graph.metadata === 'object' ? graph.metadata : {}) as Record<string, unknown>;
			metadata.mode = executionMode;
			if (key === 'mfa_authentication') metadata.policyKey = 'core.mfa_proof_gate';
			graph.metadata = metadata;
			graphText = JSON.stringify(graph, null, 2);
		} catch {
			error = 'Graph JSON is invalid.';
			return;
		}
		saving = true;
		try {
			if (selected) {
				const response = await api.request(`/admin/workflows/definitions/${selected.id}`, {
					method: 'PUT',
					body: JSON.stringify({ siteId: selectedSiteId, name, graph }),
				});
				message = `Saved version ${response.version.version}.`;
			} else {
				const payload: Record<string, unknown> = { ownerType, key, name, graph, siteId: selectedSiteId };
				if ('core' === ownerType) {
					payload.siteIds = [selectedSiteId];
				}
				const response = await api.request('/admin/workflows/definitions', { method: 'POST', body: JSON.stringify(payload) });
				selected = response;
				message = `Created ${response.key} version ${response.currentVersion}.`;
			}
			await loadDefinitions();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to save workflow definition.';
		} finally {
			saving = false;
		}
	}

	async function assignToSite(definition: WorkflowDefinition): Promise<void> {
		error = '';
		try {
			await api.request(`/admin/workflows/definitions/${definition.id}/assignments/${encodeURIComponent(selectedSiteId)}`, { method: 'POST' });
			message = 'Workflow assigned to this site.';
			await loadDefinitions();
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to assign workflow.';
		}
	}

	async function forkDefinition(definition: WorkflowDefinition): Promise<void> {
		error = '';
		try {
			const forkKey = `site.${selectedSiteId}.${definition.key}`.replaceAll('/', '.');
			const response = await api.request(`/admin/workflows/definitions/${definition.id}/fork`, {
				method: 'POST',
				body: JSON.stringify({ siteId: selectedSiteId, key: forkKey, name: `${definition.name} (site copy)` }),
			});
			await loadDefinitions();
			await selectDefinition(response);
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to fork workflow.';
		}
	}

	onMount(() => {
		void load();
	});
</script>

<section class="space-y-5 p-6">
	<header class="flex flex-wrap items-end justify-between gap-4">
		<div>
			<p class="text-xs uppercase tracking-[0.24em] text-text-muted">Workflow studio</p>
			<h1 class="mt-1 text-2xl font-semibold text-text">Workflow definitions</h1>
			<p class="mt-1 max-w-3xl text-sm text-text-muted">Versioned graph definitions compile to Symfony Workflow transitions. References to policies, actions and assignments must resolve to registered code keys.</p>
		</div>
				<div class="flex items-center gap-3">
					{#if canCreateCore}
						<label class="flex items-center gap-2 text-sm text-text-muted"><input type="checkbox" bind:checked={showAllDefinitions} onchange={() => { selected = null; void loadDefinitions(); }} />All definitions</label>
					{/if}
					<label class="text-sm text-text-muted" for="workflow-site">Site</label>
			<select id="workflow-site" class="admin-input" bind:value={selectedSiteId} onchange={() => { selected = null; void loadDefinitions(); }}>
				{#each sites as site (site.id)}
					<option value={String(site.id)}>{site.name} ({site.domain})</option>
				{/each}
			</select>
			<button class="iikiti-btn iikiti-btn--primary" onclick={newDefinition}>New workflow</button>
		</div>
	</header>

	{#if error}
		<div class="rounded border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">{error}</div>
	{/if}
	{#if message}
		<div class="rounded border border-success/30 bg-success/5 px-4 py-3 text-sm text-success">{message}</div>
	{/if}

	{#if loading}
		<div class="text-sm text-text-muted">Loading workflows…</div>
	{:else}
		<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(32rem,1.2fr)]">
			<aside class="rounded-lg border border-border bg-surface p-4">
				<h2 class="mb-3 text-sm font-semibold text-text">Available definitions</h2>
				{#if definitions.length === 0}
					<p class="text-sm text-text-muted">No definitions are assigned to this site.</p>
				{:else}
					<ul class="space-y-2">
						{#each definitions as definition (definition.id)}
							<li class="rounded border border-border p-3">
								<div class="flex items-start justify-between gap-2">
									<button class="text-left" onclick={() => void selectDefinition(definition)}>
										<span class="block font-medium text-text">{definition.name}</span>
										<span class="mt-1 block font-mono text-xs text-text-muted">{definition.ownerType}:{definition.ownerKey} / {definition.key} · v{definition.currentVersion}</span>
									</button>
									<span class="rounded px-2 py-1 text-xs {definition.enabled ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'}">{definition.enabled ? 'Enabled' : 'Disabled'}</span>
								</div>
								{#if !definition.canEdit && !definition.siteIds.some((id) => String(id) === selectedSiteId)}
									<button class="mt-2 text-xs text-accent hover:underline" onclick={() => void assignToSite(definition)}>Assign to this site</button>
								{:else if !definition.canEdit && definition.ownerType !== 'site'}
									<button class="mt-2 text-xs text-accent hover:underline" onclick={() => void forkDefinition(definition)}>Fork to this site</button>
								{/if}
							</li>
						{/each}
					</ul>
				{/if}
			</aside>

			<div class="rounded-lg border border-border bg-surface p-5">
				<div class="mb-4 flex items-center justify-between">
					<h2 class="text-sm font-semibold text-text">{selected ? `Edit ${selected.key}` : 'New definition'}</h2>
					{#if selected}
						<span class="text-xs text-text-muted">New saves create immutable versions.</span>
					{/if}
				</div>
				<div class="grid gap-4 sm:grid-cols-2">
					<label class="block text-sm text-text-muted">Key
						<input class="admin-input mt-1 w-full font-mono" bind:value={key} disabled={selected !== null} placeholder="approval.review" />
					</label>
					<label class="block text-sm text-text-muted">Name
						<input class="admin-input mt-1 w-full" bind:value={name} placeholder="Approval" />
					</label>
					{#if !selected && canCreateCore}
						<label class="block text-sm text-text-muted">Ownership
							<select class="admin-input mt-1 w-full" bind:value={ownerType}>
								<option value="site">This site</option>
								<option value="core">Core / shared</option>
							</select>
						</label>
					{/if}
					<label class="block text-sm text-text-muted">Execution mode
						<select class="admin-input mt-1 w-full" bind:value={executionMode}>
							<option value="durable">Durable workflow / tasks</option>
							<option value="session">Interactive session flow</option>
						</select>
					</label>
				</div>
				<label class="mt-4 block text-sm text-text-muted">Graph document (JSON)
					<textarea class="admin-input mt-1 min-h-[28rem] w-full font-mono text-xs" bind:value={graphText} spellcheck="false"></textarea>
				</label>
				<div class="mt-4 grid gap-3 text-xs text-text-muted sm:grid-cols-3">
					<div><strong class="text-text">Policies</strong><p class="mt-1">{policyKeys.join(', ') || 'None registered'}</p></div>
					<div><strong class="text-text">Actions</strong><p class="mt-1">{actions.join(', ') || 'None registered'}</p></div>
					<div><strong class="text-text">Assignment resolvers</strong><p class="mt-1">{assignmentResolvers.join(', ') || 'None registered'}</p></div>
					<div><strong class="text-text">Subject resolvers</strong><p class="mt-1">{subjectResolvers.join(', ') || 'None registered'}</p></div>
					<div><strong class="text-text">MFA factors</strong><p class="mt-1">{mfaFactors.join(', ') || 'None registered'}</p></div>
					<div><strong class="text-text">Asset processors</strong><p class="mt-1">{assetProcessors.join(', ') || 'None registered'}</p></div>
				</div>
				<div class="mt-5 flex justify-end gap-3">
					<button class="iikiti-btn iikiti-btn--secondary" onclick={load}>Refresh</button>
					<button class="iikiti-btn iikiti-btn--primary" disabled={saving || !key || !name} onclick={() => void save()}>{saving ? 'Saving…' : 'Save version'}</button>
				</div>
			</div>
		</div>
	{/if}
</section>
