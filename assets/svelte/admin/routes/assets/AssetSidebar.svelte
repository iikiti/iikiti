<script lang="ts">
	import Icon from '$components/Icon.svelte';
	import { buildFolderTree, canMoveFolder } from '$lib/damHelpers.js';
	import type {
		DigitalAssetCollection,
		DigitalAssetFacets,
		DigitalAssetFolder,
		DigitalAssetTag,
	} from '$types';

	export type SmartViewId = 'all' | 'unfiled' | 'failed' | 'expiring';

	interface Props {
		folders: DigitalAssetFolder[];
		collections: DigitalAssetCollection[];
		tags: DigitalAssetTag[];
		facets?: DigitalAssetFacets | null;
		activeSmartView: SmartViewId | null;
		activeFolderId: string | null;
		includeSubfolders: boolean;
		activeCollectionId: string | null;
		selectedTagIds: string[];
		onSelectSmartView: (view: SmartViewId) => void;
		onSelectFolder: (folderId: string | null) => void;
		onToggleIncludeSubfolders: (value: boolean) => void;
		onSelectCollection: (collectionId: string | null) => void;
		onToggleTag: (tagId: string) => void;
		onCreateFolder: (payload: { name: string; parentId: string | null }) => Promise<void>;
		onRenameFolder: (id: string | number, name: string) => Promise<void>;
		onReparentFolder: (id: string | number, parentId: string | null) => Promise<void>;
		onDeleteFolder: (folder: DigitalAssetFolder) => Promise<void>;
		onCreateCollection: (payload: { name: string; description?: string }) => Promise<void>;
		onDeleteCollection: (collection: DigitalAssetCollection) => Promise<void>;
		onCreateTag: (payload: { name: string }) => Promise<void>;
		onDeleteTag: (tag: DigitalAssetTag) => Promise<void>;
		onMoveAssetsToFolder: (assetIds: string[], folderId: string | null) => Promise<void>;
		onAddAssetsToCollection: (assetIds: string[], collectionId: string) => Promise<void>;
	}

	let {
		folders,
		collections,
		tags,
		facets = null,
		activeSmartView,
		activeFolderId,
		includeSubfolders,
		activeCollectionId,
		selectedTagIds,
		onSelectSmartView,
		onSelectFolder,
		onToggleIncludeSubfolders,
		onSelectCollection,
		onToggleTag,
		onCreateFolder,
		onRenameFolder,
		onReparentFolder,
		onDeleteFolder,
		onCreateCollection,
		onDeleteCollection,
		onCreateTag,
		onDeleteTag,
		onMoveAssetsToFolder,
		onAddAssetsToCollection,
	}: Props = $props();

	let expandedFolders = $state<Record<string, boolean>>({});
	let dragOverTarget = $state<string | null>(null);
	let draggedFolderId = $state<string | null>(null);

	// Inline folder creation / rename
	let creatingFolderParentId = $state<string | null | undefined>(undefined);
	let newFolderName = $state('');
	let renamingFolderId = $state<string | null>(null);
	let renameFolderValue = $state('');
	let busyFolder = $state(false);

	// Inline collection creation
	let creatingCollection = $state(false);
	let newCollectionName = $state('');
	let busyCollection = $state(false);

	// Inline tag creation
	let creatingTag = $state(false);
	let newTagName = $state('');
	let busyTag = $state(false);

	const folderTree = $derived(buildFolderTree(folders));

	const failedCount = $derived(
		facets?.processingStatus?.failed ?? facets?.processingStatuses?.failed ?? null,
	);
	const expiringCount = $derived.by(() => {
		const map = facets?.licenseStatus ?? facets?.licenseStatuses;
		if (map) {
			if (typeof map.expiring_or_expired === 'number') {
				return map.expiring_or_expired;
			}
			const exp = Number(map.expiring_soon ?? map.expiring ?? 0);
			const expired = Number(map.expired ?? 0);
			return exp + expired;
		}
		if (typeof facets?.expiringCount === 'number' || typeof facets?.expiredCount === 'number') {
			return Number(facets?.expiringCount ?? 0) + Number(facets?.expiredCount ?? 0);
		}
		return null;
	});

	function isExpanded(id: string): boolean {
		return expandedFolders[id] !== false;
	}

	function toggleExpanded(id: string) {
		expandedFolders = { ...expandedFolders, [id]: !isExpanded(id) };
	}

	function parseDraggedAssetIds(event: DragEvent): string[] {
		const raw = event.dataTransfer?.getData('application/x-iikiti-asset-ids');
		if (!raw) return [];
		try {
			const parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed.map(String).filter(Boolean) : [];
		} catch {
			return [];
		}
	}

	function parseDraggedFolderId(event: DragEvent): string | null {
		const raw = event.dataTransfer?.getData('application/x-iikiti-folder-id');
		return raw ? String(raw) : draggedFolderId;
	}

	function hasDamDragPayload(event: DragEvent): boolean {
		const types = Array.from(event.dataTransfer?.types ?? []);
		return (
			types.includes('application/x-iikiti-asset-ids') ||
			types.includes('application/x-iikiti-folder-id')
		);
	}

	function handleFolderDragOver(event: DragEvent, targetKey: string, targetFolderId: string | null) {
		if (!hasDamDragPayload(event)) return;
		const srcFolder = draggedFolderId;
		if (srcFolder && !canMoveFolder(folders, srcFolder, targetFolderId)) {
			return;
		}
		event.preventDefault();
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'move';
		}
		dragOverTarget = targetKey;
	}

	async function handleFolderDrop(event: DragEvent, targetFolderId: string | null) {
		event.preventDefault();
		dragOverTarget = null;

		const assetIds = parseDraggedAssetIds(event);
		if (assetIds.length > 0) {
			await onMoveAssetsToFolder(assetIds, targetFolderId);
			return;
		}

		const srcFolder = parseDraggedFolderId(event);
		draggedFolderId = null;
		if (srcFolder && canMoveFolder(folders, srcFolder, targetFolderId)) {
			await onReparentFolder(srcFolder, targetFolderId);
		}
	}

	function handleCollectionDragOver(event: DragEvent, collectionId: string) {
		const types = Array.from(event.dataTransfer?.types ?? []);
		if (!types.includes('application/x-iikiti-asset-ids')) return;
		event.preventDefault();
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy';
		}
		dragOverTarget = `col:${collectionId}`;
	}

	async function handleCollectionDrop(event: DragEvent, collectionId: string) {
		event.preventDefault();
		dragOverTarget = null;
		const assetIds = parseDraggedAssetIds(event);
		if (assetIds.length > 0) {
			await onAddAssetsToCollection(assetIds, collectionId);
		}
	}

	async function submitNewFolder(event: SubmitEvent) {
		event.preventDefault();
		const name = newFolderName.trim();
		if (!name || busyFolder) return;
		busyFolder = true;
		try {
			await onCreateFolder({ name, parentId: creatingFolderParentId ?? null });
			newFolderName = '';
			creatingFolderParentId = undefined;
		} finally {
			busyFolder = false;
		}
	}

	async function submitRenameFolder(event: SubmitEvent, folderId: string | number) {
		event.preventDefault();
		const name = renameFolderValue.trim();
		if (!name || busyFolder) return;
		busyFolder = true;
		try {
			await onRenameFolder(folderId, name);
			renamingFolderId = null;
			renameFolderValue = '';
		} finally {
			busyFolder = false;
		}
	}

	async function submitNewCollection(event: SubmitEvent) {
		event.preventDefault();
		const name = newCollectionName.trim();
		if (!name || busyCollection) return;
		busyCollection = true;
		try {
			await onCreateCollection({ name });
			newCollectionName = '';
			creatingCollection = false;
		} finally {
			busyCollection = false;
		}
	}

	async function submitNewTag(event: SubmitEvent) {
		event.preventDefault();
		const name = newTagName.trim();
		if (!name || busyTag) return;
		busyTag = true;
		try {
			await onCreateTag({ name });
			newTagName = '';
			creatingTag = false;
		} finally {
			busyTag = false;
		}
	}
</script>

{#snippet folderNode(node: any, depth: number)}
	{@const idStr = String(node.id)}
	{@const hasChildren = Array.isArray(node.children) && node.children.length > 0}
	{@const isActive = activeFolderId === idStr}
	{@const isDropTarget = dragOverTarget === `folder:${idStr}`}

	<div class="select-none">
		{#if renamingFolderId === idStr}
			<form
				class="flex items-center gap-1 py-1 pr-1"
				style={`padding-left: ${depth * 14 + 8}px`}
				onsubmit={(e) => void submitRenameFolder(e, node.id)}
			>
				<input
					type="text"
					class="admin-input admin-input-sm flex-1 py-1 text-xs"
					bind:value={renameFolderValue}
					maxlength="120"
					aria-label="Rename folder"
				/>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm px-2 py-1" disabled={busyFolder} title="Save">
					<Icon name="check" size={12} />
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-1"
					onclick={() => (renamingFolderId = null)}
					title="Cancel"
				>
					<Icon name="x" size={12} />
				</button>
			</form>
		{:else}
			<div
				class="group flex items-center gap-1 rounded-md px-1.5 py-1 text-sm transition-colors {isActive
					? 'bg-accent-subtle text-accent font-medium'
					: 'text-text hover:bg-surface-hover'} {isDropTarget ? 'ring-2 ring-accent bg-accent-subtle' : ''}"
				style={`padding-left: ${depth * 14 + 6}px`}
				draggable="true"
				ondragstart={(e) => {
					draggedFolderId = idStr;
					e.dataTransfer?.setData('application/x-iikiti-folder-id', idStr);
					if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
				}}
				ondragend={() => {
					draggedFolderId = null;
					dragOverTarget = null;
				}}
				ondragover={(e) => handleFolderDragOver(e, `folder:${idStr}`, idStr)}
				ondragleave={() => {
					if (dragOverTarget === `folder:${idStr}`) dragOverTarget = null;
				}}
				ondrop={(e) => void handleFolderDrop(e, idStr)}
			>
				{#if hasChildren}
					<button
						type="button"
						class="flex h-4 w-4 shrink-0 items-center justify-center rounded text-text-muted hover:text-text"
						aria-label={isExpanded(idStr) ? `Collapse ${node.name}` : `Expand ${node.name}`}
						aria-expanded={isExpanded(idStr)}
						onclick={() => toggleExpanded(idStr)}
					>
						<Icon name={isExpanded(idStr) ? 'chevron-down' : 'chevron-right'} size={13} />
					</button>
				{:else}
					<span class="inline-block w-4 shrink-0" aria-hidden="true"></span>
				{/if}

				<button
					type="button"
					class="flex min-w-0 flex-1 items-center gap-1.5 py-0.5 text-left"
					aria-current={isActive ? 'true' : undefined}
					onclick={() => onSelectFolder(idStr)}
				>
					<Icon name="folder" size={14} class="shrink-0 opacity-80" />
					<span class="flex-1 truncate" title={node.name}>{node.name}</span>
					{#if typeof node.assetCount === 'number'}
						<span class="text-xs text-text-muted">{node.assetCount}</span>
					{/if}
				</button>

				<div class="hidden items-center gap-0.5 group-hover:flex group-focus-within:flex">
					<button
						type="button"
						class="rounded p-0.5 text-text-muted hover:bg-surface hover:text-text"
						title="New subfolder"
						aria-label={`Create subfolder in ${node.name}`}
						onclick={() => {
							creatingFolderParentId = idStr;
							newFolderName = '';
							expandedFolders = { ...expandedFolders, [idStr]: true };
						}}
					>
						<Icon name="folder-plus" size={12} />
					</button>
					<button
						type="button"
						class="rounded p-0.5 text-text-muted hover:bg-surface hover:text-text"
						title="Rename folder"
						aria-label={`Rename ${node.name}`}
						onclick={() => {
							renamingFolderId = idStr;
							renameFolderValue = node.name;
						}}
					>
						<Icon name="pencil" size={12} />
					</button>
					<button
						type="button"
						class="rounded p-0.5 text-text-muted hover:bg-danger-subtle hover:text-danger"
						title="Delete folder"
						aria-label={`Delete ${node.name}`}
						onclick={() => void onDeleteFolder(node)}
					>
						<Icon name="trash-2" size={12} />
					</button>
				</div>
			</div>
		{/if}

		{#if creatingFolderParentId === idStr}
			<form
				class="flex items-center gap-1 py-1 pr-1"
				style={`padding-left: ${(depth + 1) * 14 + 8}px`}
				onsubmit={(e) => void submitNewFolder(e)}
			>
				<input
					type="text"
					class="admin-input admin-input-sm flex-1 py-1 text-xs"
					placeholder="Subfolder name…"
					bind:value={newFolderName}
					maxlength="120"
					aria-label="New subfolder name"
				/>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm px-2 py-1" disabled={busyFolder}>
					<Icon name="check" size={12} />
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-1"
					onclick={() => (creatingFolderParentId = undefined)}
				>
					<Icon name="x" size={12} />
				</button>
			</form>
		{/if}

		{#if hasChildren && isExpanded(idStr)}
			<div>
				{#each node.children as child (String(child.id))}
					{@render folderNode(child, depth + 1)}
				{/each}
			</div>
		{/if}
	</div>
{/snippet}

<aside class="w-full shrink-0 space-y-5 rounded-xl border border-border bg-surface p-3.5 lg:w-64" aria-label="Asset organization">
	<!-- Smart Views -->
	<section class="space-y-1">
		<h3 class="px-2 text-xs font-semibold tracking-wider text-text-muted uppercase">Views</h3>
		<button
			type="button"
			class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm transition-colors {activeSmartView === 'all'
				? 'bg-accent-subtle text-accent font-medium'
				: 'text-text hover:bg-surface-hover'}"
			onclick={() => onSelectSmartView('all')}
		>
			<span class="flex items-center gap-2">
				<Icon name="layers" size={14} />
				<span>All Assets</span>
			</span>
			{#if typeof facets?.totalCount === 'number'}
				<span class="text-xs text-text-muted">{facets.totalCount}</span>
			{/if}
		</button>

		<button
			type="button"
			class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm transition-colors {activeSmartView === 'unfiled'
				? 'bg-accent-subtle text-accent font-medium'
				: 'text-text hover:bg-surface-hover'} {dragOverTarget === 'unfiled' ? 'ring-2 ring-accent bg-accent-subtle' : ''}"
			ondragover={(e) => handleFolderDragOver(e, 'unfiled', null)}
			ondragleave={() => {
				if (dragOverTarget === 'unfiled') dragOverTarget = null;
			}}
			ondrop={(e) => void handleFolderDrop(e, null)}
			onclick={() => onSelectSmartView('unfiled')}
		>
			<span class="flex items-center gap-2">
				<Icon name="inbox" size={14} />
				<span>Unfiled</span>
			</span>
			{#if typeof facets?.unfiledCount === 'number'}
				<span class="text-xs text-text-muted">{facets.unfiledCount}</span>
			{/if}
		</button>

		<button
			type="button"
			class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm transition-colors {activeSmartView === 'failed'
				? 'bg-danger-subtle text-danger font-medium'
				: 'text-text hover:bg-surface-hover'}"
			onclick={() => onSelectSmartView('failed')}
		>
			<span class="flex items-center gap-2">
				<Icon name="alert-triangle" size={14} />
				<span>Processing Failed</span>
			</span>
			{#if failedCount !== null && failedCount > 0}
				<span class="rounded-full bg-danger-subtle px-1.5 py-0.5 text-[11px] font-medium text-danger">{failedCount}</span>
			{/if}
		</button>

		<button
			type="button"
			class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm transition-colors {activeSmartView === 'expiring'
				? 'bg-warning-subtle text-warning font-medium'
				: 'text-text hover:bg-surface-hover'}"
			onclick={() => onSelectSmartView('expiring')}
		>
			<span class="flex items-center gap-2">
				<Icon name="clock" size={14} />
				<span>Expiring / Expired</span>
			</span>
			{#if expiringCount !== null && expiringCount > 0}
				<span class="rounded-full bg-warning-subtle px-1.5 py-0.5 text-[11px] font-medium text-warning">{expiringCount}</span>
			{/if}
		</button>
	</section>

	<!-- Folder Tree -->
	<section class="space-y-1.5 border-t border-border pt-4">
		<div class="flex items-center justify-between px-2">
			<h3 class="text-xs font-semibold tracking-wider text-text-muted uppercase">Folders</h3>
			<button
				type="button"
				class="rounded p-1 text-text-muted hover:bg-surface-hover hover:text-text"
				title="New folder"
				aria-label="Create root folder"
				onclick={() => {
					creatingFolderParentId = null;
					newFolderName = '';
				}}
			>
				<Icon name="folder-plus" size={14} />
			</button>
		</div>

		{#if creatingFolderParentId === null}
			<form class="flex items-center gap-1 px-1" onsubmit={(e) => void submitNewFolder(e)}>
				<input
					type="text"
					class="admin-input admin-input-sm flex-1 py-1 text-xs"
					placeholder="Folder name…"
					bind:value={newFolderName}
					maxlength="120"
					aria-label="New folder name"
				/>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm px-2 py-1" disabled={busyFolder}>
					<Icon name="check" size={12} />
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-1"
					onclick={() => (creatingFolderParentId = undefined)}
				>
					<Icon name="x" size={12} />
				</button>
			</form>
		{/if}

		{#if folderTree.length > 0}
			<div aria-label="Asset folders" class="space-y-0.5">
				{#each folderTree as node (String(node.id))}
					{@render folderNode(node, 0)}
				{/each}
			</div>
		{:else}
			<p class="px-2 text-xs text-text-muted">No folders yet. Drag assets onto folders once created.</p>
		{/if}

		{#if activeFolderId && activeFolderId !== 'unfiled'}
			<label class="mt-2 flex cursor-pointer items-center gap-2 px-2 text-xs text-text-muted">
				<input
					type="checkbox"
					checked={includeSubfolders}
					onchange={(e) => onToggleIncludeSubfolders(e.currentTarget.checked)}
				/>
				<span>Include subfolders</span>
			</label>
		{/if}
	</section>

	<!-- Collections -->
	<section class="space-y-1.5 border-t border-border pt-4">
		<div class="flex items-center justify-between px-2">
			<h3 class="text-xs font-semibold tracking-wider text-text-muted uppercase">Collections</h3>
			<button
				type="button"
				class="rounded p-1 text-text-muted hover:bg-surface-hover hover:text-text"
				title="New collection"
				aria-label="Create collection"
				onclick={() => {
					creatingCollection = !creatingCollection;
					newCollectionName = '';
				}}
			>
				<Icon name="plus" size={14} />
			</button>
		</div>

		{#if creatingCollection}
			<form class="flex items-center gap-1 px-1" onsubmit={(e) => void submitNewCollection(e)}>
				<input
					type="text"
					class="admin-input admin-input-sm flex-1 py-1 text-xs"
					placeholder="Collection name…"
					bind:value={newCollectionName}
					maxlength="120"
					aria-label="New collection name"
				/>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm px-2 py-1" disabled={busyCollection}>
					<Icon name="check" size={12} />
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-1"
					onclick={() => (creatingCollection = false)}
				>
					<Icon name="x" size={12} />
				</button>
			</form>
		{/if}

		{#if collections.length > 0}
			<div class="space-y-0.5">
				{#each collections as col (String(col.id))}
					{@const colId = String(col.id)}
					{@const isActive = activeCollectionId === colId}
					{@const isDrop = dragOverTarget === `col:${colId}`}
					<div
						class="group flex items-center justify-between gap-1 rounded-md px-2 py-1 text-sm transition-colors {isActive
							? 'bg-accent-subtle text-accent font-medium'
							: 'text-text hover:bg-surface-hover'} {isDrop ? 'ring-2 ring-accent bg-accent-subtle' : ''}"
						ondragover={(e) => handleCollectionDragOver(e, colId)}
						ondragleave={() => {
							if (dragOverTarget === `col:${colId}`) dragOverTarget = null;
						}}
						ondrop={(e) => void handleCollectionDrop(e, colId)}
					>
						<button
							type="button"
							class="flex min-w-0 flex-1 items-center justify-between gap-2 py-0.5 text-left"
							aria-pressed={isActive}
							onclick={() => onSelectCollection(isActive ? null : colId)}
						>
							<span class="flex min-w-0 items-center gap-2">
								<Icon name="sparkles" size={13} class="shrink-0 opacity-80" />
								<span class="truncate" title={col.name}>{col.name}</span>
							</span>
							{#if typeof col.assetCount === 'number'}
								<span class="text-xs text-text-muted">{col.assetCount}</span>
							{/if}
						</button>
						<button
							type="button"
							class="hidden shrink-0 rounded p-0.5 text-text-muted hover:bg-danger-subtle hover:text-danger group-hover:inline-flex group-focus-within:inline-flex"
							title="Delete collection"
							aria-label={`Delete collection ${col.name}`}
							onclick={() => void onDeleteCollection(col)}
						>
							<Icon name="trash-2" size={12} />
						</button>
					</div>
				{/each}
			</div>
		{:else}
			<p class="px-2 text-xs text-text-muted">No collections yet.</p>
		{/if}
	</section>

	<!-- Tags -->
	<section class="space-y-2 border-t border-border pt-4">
		<div class="flex items-center justify-between px-2">
			<h3 class="text-xs font-semibold tracking-wider text-text-muted uppercase">Tags</h3>
			<button
				type="button"
				class="rounded p-1 text-text-muted hover:bg-surface-hover hover:text-text"
				title="New tag"
				aria-label="Create tag"
				onclick={() => {
					creatingTag = !creatingTag;
					newTagName = '';
				}}
			>
				<Icon name="plus" size={14} />
			</button>
		</div>

		{#if creatingTag}
			<form class="flex items-center gap-1 px-1" onsubmit={(e) => void submitNewTag(e)}>
				<input
					type="text"
					class="admin-input admin-input-sm flex-1 py-1 text-xs"
					placeholder="Tag name…"
					bind:value={newTagName}
					maxlength="64"
					aria-label="New tag name"
				/>
				<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm px-2 py-1" disabled={busyTag}>
					<Icon name="check" size={12} />
				</button>
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-1"
					onclick={() => (creatingTag = false)}
				>
					<Icon name="x" size={12} />
				</button>
			</form>
		{/if}

		{#if tags.length > 0}
			<div class="flex flex-wrap gap-1.5 px-1">
				{#each tags as tag (String(tag.id))}
					{@const tagId = String(tag.id)}
					{@const isSelected = selectedTagIds.includes(tagId)}
					<span
						class="group inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors {isSelected
							? 'border-accent bg-accent-subtle text-accent font-medium'
							: 'border-border bg-bg text-text hover:border-border-strong'}"
					>
						<button
							type="button"
							class="inline-flex items-center gap-1"
							onclick={() => onToggleTag(tagId)}
						>
							<Icon name="tag" size={10} />
							<span>{tag.name}</span>
						</button>
						<button
							type="button"
							class="ml-0.5 hidden rounded-full text-text-muted hover:text-danger group-hover:inline-flex group-focus-within:inline-flex"
							title={`Delete tag ${tag.name}`}
							aria-label={`Delete tag ${tag.name}`}
							onclick={() => void onDeleteTag(tag)}
						>
							<Icon name="x" size={10} />
						</button>
					</span>
				{/each}
			</div>
		{:else}
			<p class="px-2 text-xs text-text-muted">No tags yet.</p>
		{/if}
	</section>
</aside>
