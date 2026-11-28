<script lang="ts">
	import Icon from '$components/Icon.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import Badge from '$components/Badge.svelte';
	import { buildFolderTree, flattenFolderTree } from '$lib/damHelpers.js';
	import type { ApiClient } from '$lib/api';
	import type {
		DigitalAssetCollection,
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
	} from '$types';

	interface Props {
		api: ApiClient;
		selectedIds: Set<string>;
		folders: DigitalAssetFolder[];
		collections: DigitalAssetCollection[];
		tags: DigitalAssetTag[];
		sites: DigitalAssetSiteOption[];
		onClearSelection: () => void;
		onActionComplete: (notice: string) => Promise<void>;
		onPartialDelete?: (succeededIds: string[], notice: string) => Promise<void>;
		onError: (message: string) => void;
	}

	let {
		api,
		selectedIds,
		folders,
		collections,
		tags,
		sites,
		onClearSelection,
		onActionComplete,
		onPartialDelete,
		onError,
	}: Props = $props();

	type ActiveDialog = null | 'tag' | 'folder' | 'collection' | 'visibility' | 'sites' | 'delete';

	let activeDialog = $state<ActiveDialog>(null);
	let busy = $state(false);
	let dialogError = $state('');

	// Tag dialog state
	let tagMode = $state<'tag_add' | 'tag_remove'>('tag_add');
	let chosenTagIds = $state<string[]>([]);

	// Folder dialog state
	let chosenFolderId = $state('');

	// Collection dialog state
	let chosenCollectionId = $state('');

	// Visibility dialog state
	let chosenVisibility = $state<'public' | 'private'>('public');

	// Sites dialog state
	let chosenSiteIds = $state<string[]>([]);

	// Delete dialog state (with 409 in-use conflict override)
	let deleteConflicts = $state<Array<{ id?: string | number; title?: string; reason?: string; error?: string; usageCount?: number; usages?: any[] }>>([]);
	let retryDeleteIds = $state<string[] | null>(null);
	let forceDeleteConfirmed = $state(false);

	const selectedCount = $derived(selectedIds.size);
	const selectedList = $derived(Array.from(selectedIds));
	const deleteTargetCount = $derived(retryDeleteIds ? retryDeleteIds.length : selectedCount);
	const flatFolders = $derived(flattenFolderTree(buildFolderTree(folders)));

	function openDialog(dialog: ActiveDialog) {
		dialogError = '';
		activeDialog = dialog;
		if (dialog === 'tag') {
			tagMode = 'tag_add';
			chosenTagIds = [];
		} else if (dialog === 'folder') {
			chosenFolderId = '';
		} else if (dialog === 'collection') {
			chosenCollectionId = collections[0] ? String(collections[0].id) : '';
		} else if (dialog === 'visibility') {
			chosenVisibility = 'public';
		} else if (dialog === 'sites') {
			chosenSiteIds = [];
		} else if (dialog === 'delete') {
			deleteConflicts = [];
			retryDeleteIds = null;
			forceDeleteConfirmed = false;
		}
	}

	function closeDialog() {
		if (busy) return;
		activeDialog = null;
		dialogError = '';
		deleteConflicts = [];
		retryDeleteIds = null;
		forceDeleteConfirmed = false;
	}

	function summarizeBulkResult(result: any, verb: string, fallbackCount = selectedCount): string {
		const succeeded = Array.isArray(result?.succeeded) ? result.succeeded.length : fallbackCount;
		const failed = Array.isArray(result?.failed) ? result.failed.length : 0;
		if (failed > 0) {
			return `${verb} ${succeeded} asset${succeeded === 1 ? '' : 's'} (${failed} failed).`;
		}
		return `${verb} ${succeeded} asset${succeeded === 1 ? '' : 's'}.`;
	}

	async function runReprocess() {
		if (busy || selectedCount === 0) return;
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: 'reprocess',
			});
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, 'Queued reprocessing for', requestedCount));
		} catch (e: any) {
			onError(e?.message ?? 'Failed to queue bulk reprocessing.');
		} finally {
			busy = false;
		}
	}

	async function submitTagAction(event: SubmitEvent) {
		event.preventDefault();
		if (chosenTagIds.length === 0) {
			dialogError = 'Select at least one tag.';
			return;
		}
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		dialogError = '';
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: tagMode,
				params: { tagIds: chosenTagIds },
			});
			activeDialog = null;
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, tagMode === 'tag_add' ? 'Tagged' : 'Untagged', requestedCount));
		} catch (e: any) {
			dialogError = e?.message ?? 'Unable to update tags.';
		} finally {
			busy = false;
		}
	}

	async function submitMoveFolder(event: SubmitEvent) {
		event.preventDefault();
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		dialogError = '';
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: 'move_folder',
				params: { folderId: chosenFolderId || null },
			});
			activeDialog = null;
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, 'Moved', requestedCount));
		} catch (e: any) {
			dialogError = e?.message ?? 'Unable to move assets.';
		} finally {
			busy = false;
		}
	}

	async function submitAddCollection(event: SubmitEvent) {
		event.preventDefault();
		if (!chosenCollectionId) {
			dialogError = 'Select a collection.';
			return;
		}
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		dialogError = '';
		try {
			const res = await api.addAssetsToCollection(chosenCollectionId, targetIds);
			const addedCount = Array.isArray(res?.addedAssetIds) ? res.addedAssetIds.length : requestedCount;
			activeDialog = null;
			onClearSelection();
			await onActionComplete(`Added ${addedCount} asset${addedCount === 1 ? '' : 's'} to collection.`);
		} catch (e: any) {
			dialogError = e?.message ?? 'Unable to add assets to collection.';
		} finally {
			busy = false;
		}
	}

	async function submitVisibility(event: SubmitEvent) {
		event.preventDefault();
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		dialogError = '';
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: 'set_visibility',
				params: { visibility: chosenVisibility },
			});
			activeDialog = null;
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, 'Updated visibility for', requestedCount));
		} catch (e: any) {
			dialogError = e?.message ?? 'Unable to update visibility.';
		} finally {
			busy = false;
		}
	}

	async function submitSites(event: SubmitEvent) {
		event.preventDefault();
		if (chosenSiteIds.length === 0) {
			dialogError = 'Select at least one site grant.';
			return;
		}
		const targetIds = selectedList;
		const requestedCount = targetIds.length;
		busy = true;
		dialogError = '';
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: 'set_site_grants',
				params: { siteIds: chosenSiteIds },
			});
			activeDialog = null;
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, 'Updated site grants for', requestedCount));
		} catch (e: any) {
			dialogError = e?.message ?? 'Unable to update site grants.';
		} finally {
			busy = false;
		}
	}

	async function submitDelete(event: SubmitEvent) {
		event.preventDefault();
		const targetIds = retryDeleteIds && retryDeleteIds.length > 0 ? retryDeleteIds : selectedList;
		if (targetIds.length === 0) {
			closeDialog();
			return;
		}
		busy = true;
		dialogError = '';
		try {
			const res = await api.bulkDigitalAssetAction({
				assetIds: targetIds,
				action: 'delete',
				force: forceDeleteConfirmed,
			});

			const succeededIds = Array.isArray(res?.succeeded) ? res.succeeded.map(String) : [];
			const failedList = Array.isArray(res?.failed) ? res.failed : [];
			const inUseFailures = failedList.filter(
				(f: any) => f?.status === 409 || Number(f?.usageCount ?? 0) > 0 || (Array.isArray(f?.usages) && f.usages.length > 0),
			);

			if (!forceDeleteConfirmed && inUseFailures.length > 0) {
				deleteConflicts = inUseFailures;
				const conflictIds = inUseFailures
					.map((f: any) => (f?.id !== undefined && f?.id !== null ? String(f.id) : ''))
					.filter(Boolean);
				retryDeleteIds = conflictIds.length > 0 ? conflictIds : null;

				if (succeededIds.length > 0) {
					await onPartialDelete?.(
						succeededIds,
						`Deleted ${succeededIds.length} unused asset${succeededIds.length === 1 ? '' : 's'}.`,
					);
				}

				dialogError = `${inUseFailures.length} asset${inUseFailures.length === 1 ? ' is' : 's are'} currently in use.${
					succeededIds.length > 0
						? ` ${succeededIds.length} unused asset${succeededIds.length === 1 ? ' was' : 's were'} already deleted.`
						: ''
				} Review references below or enable Force Delete.`;
				return;
			}

			activeDialog = null;
			deleteConflicts = [];
			retryDeleteIds = null;
			forceDeleteConfirmed = false;
			onClearSelection();
			await onActionComplete(summarizeBulkResult(res, 'Deleted', targetIds.length));
		} catch (e: any) {
			if (e?.status === 409 && e?.body) {
				if (Array.isArray(e.body.failed) && e.body.failed.length > 0) {
					deleteConflicts = e.body.failed;
					const conflictIds = e.body.failed
						.map((f: any) => (f?.id !== undefined && f?.id !== null ? String(f.id) : ''))
						.filter(Boolean);
					if (conflictIds.length > 0) {
						retryDeleteIds = conflictIds;
					}
				} else {
					deleteConflicts = [
						{
							reason: e.body.error ?? 'One or more assets are in use.',
							usageCount: e.body.usageCount,
							usages: e.body.usages ?? [],
						},
					];
				}
				dialogError = e.body.error ?? 'Selected assets are referenced by published or draft content.';
			} else {
				dialogError = e?.message ?? 'Unable to delete selected assets.';
			}
		} finally {
			busy = false;
		}
	}
</script>

{#if selectedCount > 0}
	<div
		class="sticky bottom-4 z-30 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent/40 bg-surface px-4 py-3 shadow-lg"
		role="region"
		aria-label="Bulk asset actions"
	>
		<div class="flex items-center gap-2.5 text-sm">
			<Badge status="primary">{selectedCount} selected</Badge>
			<button type="button" class="text-xs text-text-muted underline hover:text-text" onclick={onClearSelection}>
				Clear selection
			</button>
		</div>

		<div class="flex flex-wrap items-center gap-1.5">
			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1" disabled={busy} onclick={() => openDialog('tag')}>
				<Icon name="tags" size={13} />
				<span>Tag</span>
			</button>
			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1" disabled={busy} onclick={() => openDialog('folder')}>
				<Icon name="folder" size={13} />
				<span>Move</span>
			</button>
			<button
				type="button"
				class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
				disabled={busy || collections.length === 0}
				onclick={() => openDialog('collection')}
			>
				<Icon name="sparkles" size={13} />
				<span>Collection</span>
			</button>
			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1" disabled={busy} onclick={() => openDialog('visibility')}>
				<Icon name="lock" size={13} />
				<span>Visibility</span>
			</button>
			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1" disabled={busy} onclick={() => openDialog('sites')}>
				<Icon name="globe" size={13} />
				<span>Site Grants</span>
			</button>
			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1" disabled={busy} onclick={() => void runReprocess()}>
				<Icon name="refresh-cw" size={13} />
				<span>Reprocess</span>
			</button>
			<button type="button" class="admin-btn admin-btn-danger admin-btn-sm gap-1" disabled={busy} onclick={() => openDialog('delete')}>
				<Icon name="trash-2" size={13} />
				<span>Delete</span>
			</button>
		</div>
	</div>
{/if}

{#if activeDialog === 'tag'}
	<ModalDialog title={`Update Tags (${selectedCount} assets)`} open={true} onClose={closeDialog} width={480}>
		<form class="space-y-4 p-2" onsubmit={submitTagAction}>
			{#if dialogError}<p class="text-sm text-danger" role="alert">{dialogError}</p>{/if}

			<div class="flex gap-2">
				<button
					type="button"
					class="admin-btn admin-btn-sm {tagMode === 'tag_add' ? 'admin-btn-primary' : 'admin-btn-secondary'}"
					onclick={() => (tagMode = 'tag_add')}
				>
					Add Tags
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-sm {tagMode === 'tag_remove' ? 'admin-btn-primary' : 'admin-btn-secondary'}"
					onclick={() => (tagMode = 'tag_remove')}
				>
					Remove Tags
				</button>
			</div>

			{#if tags.length === 0}
				<p class="text-sm text-text-muted">Create a tag in the sidebar first.</p>
			{:else}
				<div class="flex flex-wrap gap-2">
					{#each tags as tag (String(tag.id))}
						{@const id = String(tag.id)}
						{@const active = chosenTagIds.includes(id)}
						<button
							type="button"
							class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors {active
								? 'border-accent bg-accent-subtle text-accent font-medium'
								: 'border-border bg-bg text-text'}"
							onclick={() => {
								chosenTagIds = active ? chosenTagIds.filter((t) => t !== id) : [...chosenTagIds, id];
							}}
						>
							<Icon name="tag" size={10} />
							<span>{tag.name}</span>
						</button>
					{/each}
				</div>
			{/if}

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={busy || tags.length === 0}>
					{busy ? 'Applying…' : 'Apply'}
				</button>
			</div>
		</form>
	</ModalDialog>
{:else if activeDialog === 'folder'}
	<ModalDialog title={`Move ${selectedCount} Asset${selectedCount === 1 ? '' : 's'} to Folder`} open={true} onClose={closeDialog} width={440}>
		<form class="space-y-4 p-2" onsubmit={submitMoveFolder}>
			{#if dialogError}<p class="text-sm text-danger" role="alert">{dialogError}</p>{/if}

			<label class="block space-y-1 text-sm">
				<span class="text-text-muted">Destination folder</span>
				<select class="admin-input admin-input-md" bind:value={chosenFolderId}>
					<option value="">Unfiled (no folder)</option>
					{#each flatFolders as folder (String(folder.id))}
						<option value={String(folder.id)}>
							{folder.depth ? `${'— '.repeat(folder.depth)}${folder.name}` : folder.name}
						</option>
					{/each}
				</select>
			</label>

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={busy}>
					{busy ? 'Moving…' : 'Move Assets'}
				</button>
			</div>
		</form>
	</ModalDialog>
{:else if activeDialog === 'collection'}
	<ModalDialog title={`Add ${selectedCount} Asset${selectedCount === 1 ? '' : 's'} to Collection`} open={true} onClose={closeDialog} width={440}>
		<form class="space-y-4 p-2" onsubmit={submitAddCollection}>
			{#if dialogError}<p class="text-sm text-danger" role="alert">{dialogError}</p>{/if}

			<label class="block space-y-1 text-sm">
				<span class="text-text-muted">Collection</span>
				<select class="admin-input admin-input-md" bind:value={chosenCollectionId}>
					{#each collections as col (String(col.id))}
						<option value={String(col.id)}>{col.name}</option>
					{/each}
				</select>
			</label>

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={busy}>
					{busy ? 'Adding…' : 'Add to Collection'}
				</button>
			</div>
		</form>
	</ModalDialog>
{:else if activeDialog === 'visibility'}
	<ModalDialog title={`Set Visibility (${selectedCount} assets)`} open={true} onClose={closeDialog} width={420}>
		<form class="space-y-4 p-2" onsubmit={submitVisibility}>
			{#if dialogError}<p class="text-sm text-danger" role="alert">{dialogError}</p>{/if}

			<label class="block space-y-1 text-sm">
				<span class="text-text-muted">Visibility</span>
				<select class="admin-input admin-input-md" bind:value={chosenVisibility}>
					<option value="public">Public to granted sites</option>
					<option value="private">Private (authenticated users only)</option>
				</select>
			</label>

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={busy}>
					{busy ? 'Updating…' : 'Update Visibility'}
				</button>
			</div>
		</form>
	</ModalDialog>
{:else if activeDialog === 'sites'}
	<ModalDialog title={`Update Site Grants (${selectedCount} assets)`} open={true} onClose={closeDialog} width={480}>
		<form class="space-y-4 p-2" onsubmit={submitSites}>
			{#if dialogError}<p class="text-sm text-danger" role="alert">{dialogError}</p>{/if}

			<div class="space-y-2">
				<span class="block text-sm text-text-muted">Select sites granted access to these assets:</span>
				<div class="space-y-1.5">
					{#each sites as site (String(site.id))}
						{@const siteId = String(site.id)}
						{@const checked = chosenSiteIds.includes(siteId)}
						<label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-bg p-2 text-sm">
							<input
								type="checkbox"
								{checked}
								onchange={() => {
									chosenSiteIds = checked
										? chosenSiteIds.filter((s) => s !== siteId)
										: [...chosenSiteIds, siteId];
								}}
							/>
							<span class="font-medium text-text">{site.name}</span>
							<span class="text-xs text-text-muted">{site.domain}</span>
						</label>
					{/each}
				</div>
			</div>

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" disabled={busy}>
					{busy ? 'Updating…' : 'Update Site Grants'}
				</button>
			</div>
		</form>
	</ModalDialog>
{:else if activeDialog === 'delete'}
	<ModalDialog title={`Delete ${deleteTargetCount} Asset${deleteTargetCount === 1 ? '' : 's'}`} open={true} onClose={closeDialog} width={540}>
		<form class="space-y-4 p-2" onsubmit={submitDelete}>
			<p class="text-sm text-text">
				This permanently removes the selected {deleteTargetCount} digital asset{deleteTargetCount === 1 ? '' : 's'} and their stored variants.
			</p>

			{#if dialogError}
				<div class="rounded-lg border border-danger/40 bg-danger-subtle p-3 text-sm text-danger" role="alert">
					{dialogError}
				</div>
			{/if}

			{#if deleteConflicts.length > 0}
				<div class="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-warning/40 bg-warning-subtle/50 p-3">
					<h4 class="text-xs font-semibold uppercase text-warning">In-use conflicts detected</h4>
					{#each deleteConflicts as conflict, idx (String(conflict.id ?? idx))}
						<div class="rounded border border-border bg-surface p-2 text-xs">
							<div class="font-medium text-text">
								{conflict.title || (conflict.id ? `Asset #${conflict.id}` : 'Referenced asset')}
								{#if conflict.usageCount}
									<span class="text-warning">({conflict.usageCount} reference{conflict.usageCount === 1 ? '' : 's'})</span>
								{/if}
							</div>
							{#if conflict.reason || conflict.error}
								<div class="text-text-muted">{conflict.reason || conflict.error}</div>
							{/if}
							{#if Array.isArray(conflict.usages) && conflict.usages.length > 0}
								<ul class="mt-1 list-disc pl-4 text-text-muted">
									{#each conflict.usages.slice(0, 5) as usage, uIdx (uIdx)}
										<li>{usage.entityType}: {usage.title || usage.label || `#${usage.entityId}`}</li>
									{/each}
								</ul>
							{/if}
						</div>
					{/each}
				</div>

				<label class="flex cursor-pointer items-center gap-2 rounded-lg border border-danger/30 bg-danger-subtle/40 p-2.5 text-sm text-text">
					<input type="checkbox" bind:checked={forceDeleteConfirmed} />
					<span><strong>Force Delete:</strong> permanently delete even while referenced by content</span>
				</label>
			{/if}

			<div class="flex justify-end gap-2">
				<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={closeDialog}>Cancel</button>
				<button
					type="submit"
					class="admin-btn admin-btn-danger admin-btn-sm"
					disabled={busy || (deleteConflicts.length > 0 && !forceDeleteConfirmed)}
				>
					{busy ? 'Deleting…' : deleteConflicts.length > 0 ? 'Force Delete Selected' : 'Delete Selected'}
				</button>
			</div>
		</form>
	</ModalDialog>
{/if}
