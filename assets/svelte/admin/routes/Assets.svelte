<script lang="ts">
	import { onMount } from 'svelte';
	import PageHeader from '$components/PageHeader.svelte';
	import Button from '$components/Button.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import LoadingState from '$components/LoadingState.svelte';

	interface Props {
		api: any;
		debug?: boolean;
	}

	interface SiteOption {
		id: number | string;
		name: string;
		domain: string;
	}

	interface AssetRecord {
		id: number | string;
		type: 'upload' | 'external';
		title: string;
		mimeType: string;
		originalName: string | null;
		size: number | string | null;
		uri: string | null;
		visibility: 'private' | 'public';
		siteIds: Array<number | string>;
		processingStatus: string;
		workflowInstances: Array<{ workflow: string; version: number; status: string; executionState: string; attempts: number; error: string | null }>;
		variants: Array<{ key: string; mimeType: string; width: number | null; height: number | null; url: string }>;
		fileUrl: string;
	}

	let { api }: Props = $props();
	let assets: AssetRecord[] = $state([]);
	let sites: SiteOption[] = $state([]);
	let totalItems = $state(0);
	let page = $state(1);
	let loading = $state(true);
	let saving = $state(false);
	let error: string | null = $state(null);
	let message: string | null = $state(null);
	let mode: 'upload' | 'uri' = $state('upload');
	let editing: AssetRecord | null = $state(null);
	let title = $state('');
	let uri = $state('');
	let mimeType = $state('');
	let visibility: 'private' | 'public' = $state('private');
	let siteIds: string[] = $state([]);
	let file: File | null = $state(null);

	const siteOptions = $derived(sites.map((site) => ({ ...site, id: String(site.id) })));
	const totalPages = $derived(Math.max(1, Math.ceil(totalItems / 25)));

	async function load() {
		loading = true;
		error = null;
		try {
			const [assetData, siteData] = await Promise.all([
				api.request(`/admin/assets?page=${page}&itemsPerPage=25`),
				api.request('/admin/assets/sites'),
			]);
			assets = assetData.assets ?? [];
			totalItems = assetData.totalItems ?? assets.length;
			sites = siteData.sites ?? [];
			if (siteIds.length === 0 && siteData.defaultSiteId != null) {
				siteIds = [String(siteData.defaultSiteId)];
			}
		} catch (e: any) {
			error = e.message ?? 'Failed to load digital assets';
		} finally {
			loading = false;
		}
	}

	onMount(load);

	function resetForm() {
		editing = null;
		title = '';
		uri = '';
		mimeType = '';
		visibility = 'private';
		file = null;
	}

	function editAsset(asset: AssetRecord) {
		editing = asset;
		title = asset.title;
		uri = asset.uri ?? '';
		mimeType = asset.mimeType;
		visibility = asset.visibility;
		siteIds = asset.siteIds.map(String);
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	async function submit(event: SubmitEvent) {
		event.preventDefault();
		saving = true;
		error = null;
		message = null;
		try {
			if (editing) {
				const changes: Record<string, unknown> = { title, visibility, siteIds };
				if (editing.type === 'external') {
					changes.uri = uri;
				}
				await api.request(`/admin/assets/${editing.id}`, {
					method: 'PUT',
					body: JSON.stringify(changes),
				});
				message = 'Asset updated.';
			} else if (mode === 'upload') {
				if (!file) {
					throw new Error('Select a file to upload.');
				}
				const form = new FormData();
				form.set('file', file);
				form.set('title', title);
				form.set('visibility', visibility);
				form.set('siteIds', JSON.stringify(siteIds));
				await api.request('/admin/assets/upload', { method: 'POST', body: form });
				message = 'Asset uploaded. Processing will continue in the background.';
			} else {
				await api.request('/admin/assets/uri', {
					method: 'POST',
					body: JSON.stringify({ title, uri, mimeType, visibility, siteIds }),
				});
				message = 'External asset added.';
			}
			resetForm();
			page = 1;
			await load();
		} catch (e: any) {
			error = e.message ?? 'Failed to save asset';
		} finally {
			saving = false;
		}
	}

	async function removeAsset(asset: AssetRecord) {
		if (!window.confirm(`Delete “${asset.title}” and its stored files?`)) {
			return;
		}
		try {
			await api.request(`/admin/assets/${asset.id}`, { method: 'DELETE' });
			message = 'Asset deleted.';
			await load();
		} catch (e: any) {
			error = e.message ?? 'Failed to delete asset';
		}
	}

	function siteLabels(ids: Array<string | number>): string {
		return ids.map((id) => siteOptions.find((site) => site.id === String(id))?.name ?? String(id)).join(', ');
	}

	function formatSize(size: number | string | null): string {
		const value = Number(size ?? 0);
		if (value < 1024) return `${value} B`;
		if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
		return `${(value / (1024 * 1024)).toFixed(1)} MB`;
	}
</script>

<PageHeader title="Digital Assets" description="Upload files or register HTTPS assets; site grants control where each asset can be used." />

{#if error}
	<div class="mb-4"><ErrorBoundary message={error} onRetry={load} /></div>
{/if}
{#if message}
	<p class="mb-4 rounded border border-border bg-surface p-3 text-sm text-text">{message}</p>
{/if}

<form class="mb-8 space-y-4 rounded-lg border border-border bg-surface p-5" onsubmit={submit}>
	<div class="flex flex-wrap items-center justify-between gap-3">
		<h2 class="text-lg font-semibold">{editing ? `Edit ${editing.title}` : 'Add an asset'}</h2>
		{#if editing}
			<Button variant="secondary" onclick={resetForm}>Cancel edit</Button>
		{:else}
			<div class="flex gap-2">
				<Button variant={mode === 'upload' ? 'primary' : 'secondary'} onclick={() => (mode = 'upload')}>Upload file</Button>
				<Button variant={mode === 'uri' ? 'primary' : 'secondary'} onclick={() => (mode = 'uri')}>External URI</Button>
			</div>
		{/if}
	</div>

	<label class="block text-sm text-text">
		<span class="mb-1 block">Title</span>
		<input class="admin-input w-full" bind:value={title} maxlength="255" required />
	</label>

	{#if editing}
		{#if editing.type === 'external'}
			<label class="block text-sm text-text">
				<span class="mb-1 block">HTTPS URI</span>
				<input class="admin-input w-full" type="url" bind:value={uri} required />
			</label>
		{/if}
	{:else if mode === 'upload'}
		<label class="block text-sm text-text">
			<span class="mb-1 block">File (maximum 25 MB)</span>
			<input class="admin-input w-full" type="file" required onchange={(event) => (file = (event.currentTarget as HTMLInputElement).files?.[0] ?? null)} />
		</label>
	{:else}
		<div class="grid gap-4 md:grid-cols-2">
			<label class="block text-sm text-text">
				<span class="mb-1 block">HTTPS URI</span>
				<input class="admin-input w-full" type="url" bind:value={uri} required />
			</label>
			<label class="block text-sm text-text">
				<span class="mb-1 block">MIME type (optional)</span>
				<input class="admin-input w-full" bind:value={mimeType} placeholder="application/pdf" />
			</label>
		</div>
	{/if}

	<div class="grid gap-4 md:grid-cols-2">
		<label class="block text-sm text-text">
			<span class="mb-1 block">Visibility</span>
			<select class="admin-input w-full" bind:value={visibility}>
				<option value="private">Private (authenticated users only)</option>
				<option value="public">Public to granted sites</option>
			</select>
		</label>
		<label class="block text-sm text-text">
			<span class="mb-1 block">Sites with access</span>
			<select class="admin-input w-full min-h-24" multiple bind:value={siteIds}>
				{#each siteOptions as site (site.id)}
					<option value={site.id}>{site.name} — {site.domain}</option>
				{/each}
			</select>
			<span class="mt-1 block text-xs text-text-muted">New assets are granted to the current site by default. Use Ctrl/Cmd to select multiple sites.</span>
		</label>
	</div>

	<div class="flex justify-end">
		<Button variant="primary" type="submit" disabled={saving}>{saving ? 'Saving…' : editing ? 'Save changes' : 'Add asset'}</Button>
	</div>
</form>

{#if loading}
	<LoadingState label="Loading assets…" />
{:else}
	<div class="overflow-x-auto rounded-lg border border-border">
		<table class="admin-table min-w-full divide-y divide-border">
			<thead><tr><th>Title</th><th>Type</th><th>Sites</th><th>Visibility</th><th>Processing</th><th>Actions</th></tr></thead>
			<tbody class="divide-y divide-border">
				{#each assets as asset (asset.id)}
					<tr>
						<td>
							<div class="font-medium text-text">{asset.title}</div>
							<div class="text-xs text-text-muted">{asset.mimeType} · {formatSize(asset.size)}</div>
							{#if asset.uri}<a class="text-xs text-accent underline" href={asset.uri} target="_blank" rel="noopener noreferrer">External source</a>{:else}<a class="text-xs text-accent underline" href={asset.fileUrl} target="_blank" rel="noopener noreferrer">Open file</a>{/if}
						</td>
						<td>{asset.type}</td>
						<td>{siteLabels(asset.siteIds) || 'No site grants'}</td>
						<td>{asset.visibility}</td>
						<td>
							<div>{asset.processingStatus}</div>
			{#each asset.workflowInstances as run (`${run.workflow}:${run.version}`)}
				<div class="text-xs text-text-muted">{run.workflow} v{run.version}: {run.status} ({run.executionState}){#if run.error} — {run.error}{/if}</div>
			{/each}
						</td>
						<td><div class="flex gap-2"><Button variant="secondary" onclick={() => editAsset(asset)}>Edit</Button><Button variant="danger" onclick={() => removeAsset(asset)}>Delete</Button></div></td>
					</tr>
				{:else}
					<tr><td colspan="6" class="py-8 text-center text-text-muted">No assets yet.</td></tr>
				{/each}
			</tbody>
		</table>
	</div>
	<div class="mt-4 flex items-center justify-between text-sm text-text-muted">
		<span>Page {page} of {totalPages}; {totalItems} assets</span>
		<div class="flex gap-2">
			<Button variant="secondary" disabled={page <= 1} onclick={() => { page -= 1; load(); }}>Previous</Button>
			<Button variant="secondary" disabled={page >= totalPages} onclick={() => { page += 1; load(); }}>Next</Button>
		</div>
	</div>
{/if}
