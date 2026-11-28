<script lang="ts">
	import { untrack } from 'svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import { isValidHttpsUrl } from '$lib/mediaHelpers.js';
	import { buildFolderTree, flattenFolderTree } from '$lib/damHelpers.js';
	import type { ApiClient } from '$lib/api';
	import type {
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
	} from '$types';

	interface Props {
		api: ApiClient;
		open: boolean;
		folders: DigitalAssetFolder[];
		tags: DigitalAssetTag[];
		sites: DigitalAssetSiteOption[];
		defaultSiteId?: string | number | null;
		defaultFolderId?: string | null;
		onClose: () => void;
		onCreated: () => Promise<void>;
	}

	let {
		api,
		open,
		folders,
		tags,
		sites,
		defaultSiteId = null,
		defaultFolderId = null,
		onClose,
		onCreated,
	}: Props = $props();

	let title = $state('');
	let uri = $state('');
	let mimeType = $state('');
	let visibility = $state<'private' | 'public'>('private');
	let folderId = $state('');
	let siteIds = $state<string[]>([]);
	let tagIds = $state<string[]>([]);
	let saving = $state(false);
	let formError = $state('');

	const flatFolders = $derived(flattenFolderTree(buildFolderTree(folders)));

	let wasOpen = false;
	$effect(() => {
		if (open && !wasOpen) {
			wasOpen = true;
			untrack(() => {
				title = '';
				uri = '';
				mimeType = '';
				visibility = 'private';
				folderId = defaultFolderId && defaultFolderId !== 'unfiled' ? String(defaultFolderId) : '';
				siteIds = defaultSiteId != null ? [String(defaultSiteId)] : sites[0] ? [String(sites[0].id)] : [];
				tagIds = [];
				formError = '';
			});
		} else if (!open && wasOpen) {
			wasOpen = false;
		}
	});

	async function submit(event: SubmitEvent) {
		event.preventDefault();
		const trimmedUri = uri.trim();
		if (!isValidHttpsUrl(trimmedUri)) {
			formError = 'Enter a valid HTTPS URL (https://…).';
			return;
		}
		saving = true;
		formError = '';
		try {
			const payload: Record<string, any> = {
				uri: trimmedUri,
				title: title.trim() || trimmedUri,
				visibility,
				siteIds,
			};
			if (mimeType.trim()) payload.mimeType = mimeType.trim();
			if (folderId) payload.folderId = folderId;
			if (tagIds.length > 0) payload.tagIds = tagIds;
			await api.createDigitalAssetUri(payload);
			await onCreated();
			onClose();
		} catch (e: any) {
			formError = e?.message ?? 'Failed to add external URL asset.';
		} finally {
			saving = false;
		}
	}
</script>

{#if open}
	<ModalDialog title="Add External HTTPS Asset" open={true} {onClose} width={560}>
		<form class="space-y-4 p-2" onsubmit={submit}>
			{#if formError}
				<div class="rounded-lg border border-danger/40 bg-danger-subtle p-2.5 text-sm text-danger" role="alert">
					{formError}
				</div>
			{/if}

			<label class="block space-y-1 text-sm">
				<span class="text-text-muted">Title</span>
				<input class="admin-input admin-input-md" bind:value={title} maxlength="255" placeholder="Brand guidelines PDF" required />
			</label>

			<div class="grid gap-3 sm:grid-cols-2">
				<label class="block space-y-1 text-sm">
					<span class="text-text-muted">HTTPS URI</span>
					<input
						class="admin-input admin-input-md"
						type="url"
						bind:value={uri}
						placeholder="https://cdn.example.com/media/hero.webp"
						required
					/>
				</label>

				<label class="block space-y-1 text-sm">
					<span class="text-text-muted">MIME Type (optional)</span>
					<input class="admin-input admin-input-md" bind:value={mimeType} placeholder="application/pdf" />
				</label>
			</div>

			<div class="grid gap-3 sm:grid-cols-2">
				<label class="block space-y-1 text-sm">
					<span class="text-text-muted">Visibility</span>
					<select class="admin-input admin-input-md" bind:value={visibility}>
						<option value="private">Private (authenticated only)</option>
						<option value="public">Public to granted sites</option>
					</select>
				</label>

				<label class="block space-y-1 text-sm">
					<span class="text-text-muted">Folder</span>
					<select class="admin-input admin-input-md" bind:value={folderId}>
						<option value="">Unfiled</option>
						{#each flatFolders as folder (String(folder.id))}
							<option value={String(folder.id)}>
								{folder.depth ? `${'— '.repeat(folder.depth)}${folder.name}` : folder.name}
							</option>
						{/each}
					</select>
				</label>
			</div>

			<div class="space-y-1.5 text-sm">
				<span class="block text-text-muted">Sites with Access</span>
				<div class="flex flex-wrap gap-1.5">
					{#each sites as site (String(site.id))}
						{@const id = String(site.id)}
						{@const checked = siteIds.includes(id)}
						<label
							class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs {checked
								? 'border-accent bg-accent-subtle text-accent font-medium'
								: 'border-border bg-bg text-text'}"
						>
							<input
								type="checkbox"
								{checked}
								onchange={() => {
									siteIds = checked ? siteIds.filter((s) => s !== id) : [...siteIds, id];
								}}
							/>
							<span>{site.name || site.domain}</span>
						</label>
					{/each}
				</div>
			</div>

			{#if tags.length > 0}
				<div class="space-y-1.5 text-sm">
					<span class="block text-text-muted">Tags (optional)</span>
					<div class="flex flex-wrap gap-1.5">
						{#each tags as tag (String(tag.id))}
							{@const id = String(tag.id)}
							{@const active = tagIds.includes(id)}
							<button
								type="button"
								class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs {active
									? 'border-accent bg-accent-subtle text-accent font-medium'
									: 'border-border bg-bg text-text-muted'}"
								onclick={() => {
									tagIds = active ? tagIds.filter((t) => t !== id) : [...tagIds, id];
								}}
							>
								<span>{tag.name}</span>
							</button>
						{/each}
					</div>
				</div>
			{/if}

			<div class="flex justify-end gap-2 border-t border-border pt-3">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={onClose} disabled={saving}>
					Cancel
				</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={saving}>
					{saving ? 'Adding…' : 'Add External Asset'}
				</button>
			</div>
		</form>
	</ModalDialog>
{/if}
