<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import BatchProgress from '$components/BatchProgress.svelte';

	interface SiteOption { id: string; name: string; domain: string; }
	interface TransferType { type: string; label: string; properties: string[]; asset: boolean; }
	interface ManifestHeader {
		version: number;
		sourceInstanceId: string;
		sourceSiteId: string;
		site: { name: string; domain: string };
		types: string[];
		supportedTypes: string[];
		unsupportedTypes: string[];
		availableProperties: Record<string, string[]>;
	}
	interface Props { api: ApiClient; }
	let { api }: Props = $props();
	let activeTab = $state<'export' | 'import'>('export');
	let sites = $state<SiteOption[]>([]);
	let selectedSiteId = $state('');
	let exportTypes = $state<TransferType[]>([]);
	let selectedExportTypes = $state<string[]>([]);
	let exportProperties = $state<Record<string, string[]>>({});
	let sourceUrl = $state('');
	let exportId = $state('');
	let sourceToken = $state('');
	let manifest = $state<ManifestHeader | null>(null);
	let selectedImportTypes = $state<string[]>([]);
	let importProperties = $state<Record<string, string[]>>({});
	let destinationMode = $state<'existing' | 'create'>('existing');
	let targetSiteId = $state('');
	let newSiteName = $state('');
	let newSiteDomain = $state('');
	let maxAttempts = $state(3);
	let canCreateSite = $state(false);
	let exportJobId = $state('');
	let importJobId = $state('');
	let manifestUrl = $state('');
	let loading = $state(true);
	let busy = $state(false);
	let error = $state('');
	let notice = $state('');

	async function loadSites(): Promise<void> {
		try {
			const response = await api.request('/admin/site-transfer/sites');
			sites = response.sites ?? [];
			canCreateSite = Boolean(response.canCreateSite);
			if (!selectedSiteId && sites.length > 0) selectedSiteId = sites[0].id;
			if (sites.length === 0 && canCreateSite) destinationMode = 'create';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load sites.';
		} finally {
			loading = false;
		}
	}

	async function loadCatalog(): Promise<void> {
		if (!selectedSiteId) return;
		busy = true;
		error = '';
		try {
			const response = await api.request(`/admin/site-transfer/catalog?siteId=${encodeURIComponent(selectedSiteId)}`);
			exportTypes = response.types ?? [];
			selectedExportTypes = exportTypes.map((type) => type.type);
			exportProperties = Object.fromEntries(exportTypes.map((type) => [type.type, [...type.properties]]));
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to load site object types.';
		} finally {
			busy = false;
		}
	}

	function toggleType(type: string, selected: boolean, importing = false): void {
		const current = importing ? selectedImportTypes : selectedExportTypes;
		const next = selected ? [...new Set([...current, type])] : current.filter((entry) => entry !== type);
		if (importing) selectedImportTypes = next;
		else selectedExportTypes = next;
	}

	function toggleProperty(type: string, property: string, selected: boolean, importing = false): void {
		const currentMap = importing ? importProperties : exportProperties;
		const current = currentMap[type] ?? [];
		const next = selected ? [...new Set([...current, property])] : current.filter((entry) => entry !== property);
		const updated = { ...currentMap, [type]: next };
		if (importing) importProperties = updated;
		else exportProperties = updated;
	}

	async function createExport(): Promise<void> {
		busy = true;
		error = '';
		notice = '';
		try {
			const response = await api.request('/admin/site-transfer/exports', {
				method: 'POST',
				body: JSON.stringify({ siteId: selectedSiteId, types: selectedExportTypes, properties: Object.fromEntries(selectedExportTypes.map((type) => [type, exportProperties[type] ?? []])) }),
			});
			exportJobId = String(response.jobId);
			manifestUrl = `/api/admin/site-transfer/exports/${encodeURIComponent(exportJobId)}/manifest`;
			notice = `Export job ${exportJobId} queued. The manifest is available when processing completes.`;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to create site export.';
		} finally {
			busy = false;
		}
	}

	async function previewImport(): Promise<void> {
		busy = true;
		error = '';
		notice = '';
		manifest = null;
		try {
			const response = await api.request('/admin/site-transfer/imports/preview', {
				method: 'POST',
				body: JSON.stringify({ sourceUrl, exportId, sourceToken }),
			});
			manifest = response.manifest;
			selectedImportTypes = [...(manifest.supportedTypes ?? manifest.types)];
			importProperties = Object.fromEntries(selectedImportTypes.map((type) => [type, [...(manifest?.availableProperties[type] ?? [])]]));
			newSiteName = manifest.site.name;
			notice = 'Source export verified. Select the data to import and its destination.';
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to inspect source export.';
		} finally {
			busy = false;
		}
	}

	async function createImport(): Promise<void> {
		busy = true;
		error = '';
		notice = '';
		try {
			const destination = destinationMode === 'existing' ? { targetSiteId } : { newSite: { name: newSiteName, domain: newSiteDomain } };
			const response = await api.request('/admin/site-transfer/imports', {
				method: 'POST',
				body: JSON.stringify({
					sourceUrl,
					exportId,
					sourceToken,
					...destination,
					types: selectedImportTypes,
					properties: Object.fromEntries(selectedImportTypes.map((type) => [type, importProperties[type] ?? []])),
					maxAttempts,
				}),
			});
			importJobId = String(response.jobId);
			sourceToken = '';
			notice = `Import job ${importJobId} queued for destination site ${response.destinationSiteId}.`;
		} catch (cause) {
			error = cause instanceof Error ? cause.message : 'Unable to create site import.';
		} finally {
			busy = false;
		}
	}

	async function copyManifestLink(): Promise<void> {
		try {
			await navigator.clipboard.writeText(`${window.location.origin}${manifestUrl}`);
			notice = 'Authenticated manifest API link copied.';
		} catch {
			error = 'Clipboard access is unavailable; copy the displayed API path manually.';
		}
	}

	onMount(() => {
		void loadSites();
	});
</script>

<div class="space-y-6 p-6">
	<header>
		<h1 class="text-2xl font-semibold text-text">Site Transfer</h1>
		<p class="mt-1 max-w-3xl text-sm text-text-muted">Move site content and configuration between iikiti servers. DAM files are fetched from authenticated source API links, never packaged in the manifest.</p>
	</header>
	{#if error}<p class="rounded-md border border-danger/30 bg-danger/5 p-3 text-sm text-danger" role="alert">{error}</p>{/if}
	{#if notice}<p class="rounded-md border border-success/30 bg-success/5 p-3 text-sm text-success" role="status">{notice}</p>{/if}
	{#if loading}<p class="text-sm text-text-muted">Loading site access…</p>{/if}

	<div class="flex gap-2 border-b border-border">
		<button class="border-b-2 px-3 py-2 text-sm {activeTab === 'export' ? 'border-accent text-accent' : 'border-transparent text-text-muted'}" onclick={() => activeTab = 'export'}>Export</button>
		<button class="border-b-2 px-3 py-2 text-sm {activeTab === 'import' ? 'border-accent text-accent' : 'border-transparent text-text-muted'}" onclick={() => activeTab = 'import'}>Import</button>
	</div>

	{#if activeTab === 'export'}
		<section class="max-w-5xl space-y-5 rounded-xl border border-border bg-surface p-5">
			<h2 class="text-lg font-semibold text-text">Create site export</h2>
			<label class="block max-w-md space-y-1 text-sm"><span class="text-text-muted">Source site</span>
				<select class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={selectedSiteId} onchange={() => void loadCatalog()}>
					<option value="">Select site</option>{#each sites as site (site.id)}<option value={site.id}>{site.name} · {site.domain}</option>{/each}
				</select>
			</label>
			{#if selectedSiteId && exportTypes.length === 0}<button class="rounded-md border border-border px-3 py-2 text-sm text-text" disabled={busy} onclick={() => void loadCatalog()}>Load object types</button>{/if}
			{#if exportTypes.length > 0}
				<div class="space-y-3">
					<h3 class="text-sm font-semibold text-text">Object types and properties</h3>
					{#each exportTypes as type (type.type)}
						<details open class="rounded-lg border border-border p-4">
							<summary class="cursor-pointer list-none"><label class="inline-flex items-center gap-2 font-medium text-text"><input type="checkbox" checked={selectedExportTypes.includes(type.type)} onchange={(event) => toggleType(type.type, event.currentTarget.checked)} />{type.label}{type.asset ? ' · DAM' : ''}</label></summary>
							{#if type.properties.length > 0}
								<div class="mt-3 grid gap-2 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-3">
									{#each type.properties as property (property)}<label class="flex items-center gap-2 text-xs text-text-muted"><input type="checkbox" checked={(exportProperties[type.type] ?? []).includes(property)} onchange={(event) => toggleProperty(type.type, property, event.currentTarget.checked)} />{property}</label>{/each}
								</div>
							{:else}<p class="mt-3 text-xs text-text-muted">This type has no transferable properties.</p>{/if}
						</details>
					{/each}
				</div>
				<button class="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={busy || selectedExportTypes.length === 0} onclick={() => void createExport()}>{busy ? 'Queueing…' : 'Start export'}</button>
			{/if}
		</section>
		{#if exportJobId}
			<section class="space-y-4 rounded-xl border border-border bg-surface p-5">
				<BatchProgress {api} batchId={exportJobId} />
				<div class="flex flex-wrap items-center gap-3 border-t border-border pt-4">
					<span class="text-sm text-text-muted">Manifest API path:</span><code class="rounded bg-surface-muted px-2 py-1 text-xs">{manifestUrl}</code>
					<button class="rounded-md border border-border px-3 py-1.5 text-xs text-text" onclick={() => void copyManifestLink()}>Copy link</button>
					<span class="text-xs text-text-muted">Transfer the export ID and source API token to the importing server.</span>
				</div>
			</section>
		{/if}
	{:else}
		<section class="max-w-5xl space-y-5 rounded-xl border border-border bg-surface p-5">
			<h2 class="text-lg font-semibold text-text">Import from another server</h2>
			<div class="grid gap-4 md:grid-cols-2">
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Source HTTPS URL</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={sourceUrl} placeholder="https://cms.example.org" /></label>
				<label class="block space-y-1 text-sm"><span class="text-text-muted">Source export ID</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={exportId} inputmode="numeric" /></label>
			</div>
			<label class="block max-w-xl space-y-1 text-sm"><span class="text-text-muted">Source API token</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 font-mono text-text" bind:value={sourceToken} type="password" autocomplete="off" /></label>
			<div class="flex flex-wrap items-center gap-3">
				<button class="rounded-md border border-border px-4 py-2 text-sm text-text disabled:opacity-50" disabled={busy || !sourceUrl || !exportId || !sourceToken} onclick={() => void previewImport()}>{busy ? 'Checking source…' : 'Inspect source export'}</button>
				<label class="flex items-center gap-2 text-sm text-text-muted">Retry attempts<input class="w-20 rounded-md border border-border bg-surface px-2 py-1 text-text" type="number" min="1" max="25" bind:value={maxAttempts} /></label>
			</div>

			{#if manifest}
				<div class="space-y-4 border-t border-border pt-5">
					<div><h3 class="font-semibold text-text">{manifest.site.name}</h3><p class="text-xs text-text-muted">Source site #{manifest.sourceSiteId} · manifest v{manifest.version}</p></div>
					<p class="text-xs text-text-muted">Destination site name and domain stay local to this server; other selected Site properties can be imported.</p>
					{#if sites.length > 0}
						<div class="flex flex-wrap gap-4 text-sm">
							<label class="flex items-center gap-2"><input type="radio" value="existing" bind:group={destinationMode} />Use existing site</label>
							{#if canCreateSite}<label class="flex items-center gap-2"><input type="radio" value="create" bind:group={destinationMode} />Create new site</label>{/if}
						</div>
					{/if}
					{#if destinationMode === 'existing'}
						<label class="block max-w-md space-y-1 text-sm"><span class="text-text-muted">Destination site</span><select class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={targetSiteId}><option value="">Select site</option>{#each sites as site (site.id)}<option value={site.id}>{site.name} · {site.domain}</option>{/each}</select></label>
					{:else}
						<div class="grid gap-4 md:grid-cols-2"><label class="block space-y-1 text-sm"><span class="text-text-muted">New site name</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={newSiteName} /></label><label class="block space-y-1 text-sm"><span class="text-text-muted">Destination domain</span><input class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text" bind:value={newSiteDomain} placeholder="www.example.org" /></label></div>
					{/if}
					{#if manifest.unsupportedTypes?.length}<p class="text-xs text-warning">Unsupported source types will be omitted and require a matching local plugin: {manifest.unsupportedTypes.join(', ')}</p>{/if}
					<div class="space-y-3"><h3 class="text-sm font-semibold text-text">Import types and properties</h3>
						{#each (manifest.supportedTypes ?? manifest.types) as type (type)}
							<details open class="rounded-lg border border-border p-4">
								<summary class="cursor-pointer list-none"><label class="inline-flex items-center gap-2 font-medium text-text"><input type="checkbox" checked={selectedImportTypes.includes(type)} onchange={(event) => toggleType(type, event.currentTarget.checked, true)} />{type.split('\\').pop()}</label></summary>
								{#if manifest.availableProperties[type]?.length}
									<div class="mt-3 grid gap-2 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-3">{#each manifest.availableProperties[type] as property (property)}<label class="flex items-center gap-2 text-xs text-text-muted"><input type="checkbox" checked={(importProperties[type] ?? []).includes(property)} onchange={(event) => toggleProperty(type, property, event.currentTarget.checked, true)} />{property}</label>{/each}</div>
								{/if}
							</details>
						{/each}
					</div>
					<button class="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={busy || selectedImportTypes.length === 0 || (destinationMode === 'existing' && !targetSiteId) || (destinationMode === 'create' && !newSiteDomain)} onclick={() => void createImport()}>{busy ? 'Queueing import…' : 'Start import'}</button>
				</div>
			{/if}
		</section>
		{#if importJobId}<section class="rounded-xl border border-border bg-surface p-5"><BatchProgress {api} batchId={importJobId} /></section>{/if}
	{/if}
</div>
