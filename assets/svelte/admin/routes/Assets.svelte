<script lang="ts">
	import { onMount } from 'svelte';
	import type { ApiClient } from '$lib/api';
	import PageHeader from '$components/PageHeader.svelte';
	import ErrorBoundary from '$components/ErrorBoundary.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import EmptyState from '$components/EmptyState.svelte';
	import Pagination from '$components/Pagination.svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import Icon from '$components/Icon.svelte';
	import AssetSidebar, { type SmartViewId } from './assets/AssetSidebar.svelte';
	import AssetToolbar, { type AssetFilterState } from './assets/AssetToolbar.svelte';
	import AssetGrid from './assets/AssetGrid.svelte';
	import AssetList from './assets/AssetList.svelte';
	import BulkActionBar from './assets/BulkActionBar.svelte';
	import UploadDropzone from './assets/UploadDropzone.svelte';
	import ExternalUriModal from './assets/ExternalUriModal.svelte';
	import AssetDetailModal from './assets/AssetDetailModal.svelte';
	import {
		buildAssetQueryParams,
		createSelectionRange,
		toggleSelectAll,
		toggleSelection,
	} from '$lib/damHelpers.js';
	import type {
		DigitalAsset,
		DigitalAssetCollection,
		DigitalAssetFacets,
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
		DigitalAssetUsage,
	} from '$types';

	const VIEW_MODE_KEY = 'iikiti.dam.viewMode';

	interface Props {
		api: ApiClient;
		debug?: boolean;
	}

	let { api }: Props = $props();

	// Workspace data
	let assets = $state<DigitalAsset[]>([]);
	let sites = $state<DigitalAssetSiteOption[]>([]);
	let defaultSiteId = $state<string | number | null>(null);
	let defaultLocale = $state<string | null>(null);
	let supportedContentLocales = $state<string[]>([]);
	let folders = $state<DigitalAssetFolder[]>([]);
	let collections = $state<DigitalAssetCollection[]>([]);
	let tags = $state<DigitalAssetTag[]>([]);
	let facets = $state<DigitalAssetFacets | null>(null);

	// Pagination & status
	let totalItems = $state(0);
	let page = $state(1);
	const itemsPerPage = 25;
	let loading = $state(true);
	let error = $state<string | null>(null);
	let notice = $state<string | null>(null);
	let loadSeq = 0;

	// Filters & view mode
	let viewMode = $state<'grid' | 'list'>('grid');
	let activeSmartView = $state<SmartViewId | null>('all');
	let filters = $state<AssetFilterState>({
		q: '',
		mimeCategory: 'all',
		siteId: '',
		visibility: '',
		processingStatus: '',
		licenseStatus: '',
		sort: 'newest',
		folderId: null,
		includeSubfolders: false,
		collectionId: null,
		tagIds: [],
	});

	// Selection state
	let selectedIds = $state<Set<string>>(new Set());
	let lastSelectedId = $state<string | null>(null);

	// Modals & full-window drop overlay
	let uploadOpen = $state(false);
	let droppedFiles = $state<File[]>([]);
	let windowFileDragDepth = $state(0);
	let externalUriOpen = $state(false);
	let inspectingAsset = $state<DigitalAsset | null>(null);

	// Single-asset delete & 409 in-use conflict modal
	let deletingAsset = $state<DigitalAsset | null>(null);
	let deleteConflictError = $state('');
	let deleteConflictUsageCount = $state(0);
	let deleteConflictUsages = $state<DigitalAssetUsage[]>([]);
	let forceSingleDelete = $state(false);
	let deletingBusy = $state(false);

	const totalPages = $derived(Math.max(1, Math.ceil(totalItems / itemsPerPage)));
	const visibleIds = $derived(assets.map((asset) => String(asset.id)));
	const foldersById = $derived(new Map(folders.map((f) => [String(f.id), f])));
	const tagsById = $derived(new Map(tags.map((t) => [String(t.id), t])));

	function readStoredViewMode(): 'grid' | 'list' {
		try {
			const stored = localStorage.getItem(VIEW_MODE_KEY);
			return stored === 'list' ? 'list' : 'grid';
		} catch {
			return 'grid';
		}
	}

	function setViewMode(mode: 'grid' | 'list') {
		viewMode = mode;
		try {
			localStorage.setItem(VIEW_MODE_KEY, mode);
		} catch {
			/* ignore storage errors */
		}
	}

	function showNotice(message: string) {
		notice = message;
		error = null;
	}

	async function loadOrganization(): Promise<void> {
		const [foldersRes, collectionsRes, tagsRes] = await Promise.allSettled([
			api.getAssetFolders(),
			api.getAssetCollections(),
			api.getAssetTags(),
		]);
		if (foldersRes.status === 'fulfilled' && Array.isArray(foldersRes.value)) {
			folders = foldersRes.value;
		}
		if (collectionsRes.status === 'fulfilled' && Array.isArray(collectionsRes.value)) {
			collections = collectionsRes.value;
		}
		if (tagsRes.status === 'fulfilled' && Array.isArray(tagsRes.value)) {
			tags = tagsRes.value;
		}
	}

	async function loadSites(): Promise<void> {
		const siteData = await api.getDigitalAssetSites();
		sites = siteData?.sites ?? [];
		defaultSiteId = siteData?.defaultSiteId ?? null;
		defaultLocale = siteData?.defaultLocale ?? null;
		supportedContentLocales = Array.isArray(siteData?.supportedContentLocales)
			? siteData.supportedContentLocales
			: [];
	}

	async function loadAssets(targetPage = page): Promise<void> {
		const seq = ++loadSeq;
		loading = true;
		error = null;
		try {
			const query = buildAssetQueryParams({
				...filters,
				page: targetPage,
				itemsPerPage,
			});
			const data = await api.getDigitalAssets(query);
			if (seq !== loadSeq) return;
			assets = Array.isArray(data?.assets) ? data.assets : [];
			totalItems = Number(data?.totalItems ?? assets.length);
			page = Number(data?.page ?? targetPage) || 1;
			facets = data?.facets ?? null;
		} catch (e: any) {
			if (seq === loadSeq) {
				error = e?.message ?? 'Failed to load digital assets.';
			}
		} finally {
			if (seq === loadSeq) {
				loading = false;
			}
		}
	}

	async function initialize(): Promise<void> {
		viewMode = readStoredViewMode();
		loading = true;
		error = null;
		try {
			await Promise.all([loadSites(), loadOrganization(), loadAssets(1)]);
		} catch (e: any) {
			error = e?.message ?? 'Failed to initialize Digital Assets workspace.';
			loading = false;
		}
	}

	onMount(() => {
		void initialize();
	});

	// Filter mutations ------------------------------------------------------

	function updateFilters(patch: Partial<AssetFilterState>) {
		filters = { ...filters, ...patch };
		if (
			patch.folderId !== undefined ||
			patch.collectionId !== undefined ||
			patch.processingStatus !== undefined ||
			patch.licenseStatus !== undefined
		) {
			syncSmartViewHighlight();
		}
		page = 1;
		selectedIds = new Set();
		lastSelectedId = null;
		void loadAssets(1);
	}

	function syncSmartViewHighlight() {
		if (filters.folderId === 'unfiled' && !filters.collectionId) {
			activeSmartView = 'unfiled';
		} else if (filters.processingStatus === 'failed' && !filters.folderId && !filters.collectionId) {
			activeSmartView = 'failed';
		} else if (
			(filters.licenseStatus === 'expiring_or_expired' ||
				filters.licenseStatus === 'expiring_soon' ||
				filters.licenseStatus === 'expired') &&
			!filters.folderId &&
			!filters.collectionId
		) {
			activeSmartView = 'expiring';
		} else if (!filters.folderId && !filters.collectionId && !filters.processingStatus && !filters.licenseStatus) {
			activeSmartView = 'all';
		} else {
			activeSmartView = null;
		}
	}

	function resetAllFilters() {
		activeSmartView = 'all';
		filters = {
			q: '',
			mimeCategory: 'all',
			siteId: '',
			visibility: '',
			processingStatus: '',
			licenseStatus: '',
			sort: 'newest',
			folderId: null,
			includeSubfolders: false,
			collectionId: null,
			tagIds: [],
		};
		page = 1;
		selectedIds = new Set();
		lastSelectedId = null;
		void loadAssets(1);
	}

	function selectSmartView(view: SmartViewId) {
		activeSmartView = view;
		const base: Partial<AssetFilterState> = {
			folderId: null,
			includeSubfolders: false,
			collectionId: null,
			processingStatus: '',
			licenseStatus: '',
		};
		if (view === 'unfiled') {
			base.folderId = 'unfiled';
		} else if (view === 'failed') {
			base.processingStatus = 'failed';
		} else if (view === 'expiring') {
			base.licenseStatus = 'expiring_or_expired';
		}
		filters = { ...filters, ...base };
		page = 1;
		selectedIds = new Set();
		void loadAssets(1);
	}

	function selectFolder(folderId: string | null) {
		activeSmartView = folderId === null ? 'all' : null;
		filters = {
			...filters,
			folderId,
			collectionId: null,
			includeSubfolders: folderId ? filters.includeSubfolders : false,
		};
		page = 1;
		selectedIds = new Set();
		void loadAssets(1);
	}

	function selectCollection(collectionId: string | null) {
		activeSmartView = collectionId === null ? 'all' : null;
		filters = {
			...filters,
			collectionId,
			folderId: null,
			includeSubfolders: false,
		};
		page = 1;
		selectedIds = new Set();
		void loadAssets(1);
	}

	function toggleTagFilter(tagId: string) {
		const exists = filters.tagIds.includes(tagId);
		const nextTagIds = exists
			? filters.tagIds.filter((id) => id !== tagId)
			: [...filters.tagIds, tagId];
		updateFilters({ tagIds: nextTagIds });
	}

	// Selection handlers ----------------------------------------------------

	function handleToggleSelect(id: string, shiftKey: boolean) {
		if (shiftKey && lastSelectedId) {
			const range = createSelectionRange(visibleIds, lastSelectedId, id);
			const merged = new Set(selectedIds);
			for (const item of range) {
				merged.add(item);
			}
			selectedIds = merged;
		} else {
			selectedIds = toggleSelection(selectedIds, id);
		}
		lastSelectedId = id;
	}

	function handleToggleSelectAll() {
		selectedIds = toggleSelectAll(selectedIds, visibleIds);
	}

	// Sidebar CRUD & Drag-to-Folder ----------------------------------------

	async function handleCreateFolder(payload: { name: string; parentId: string | null }) {
		try {
			await api.createAssetFolder(payload);
			await loadOrganization();
			showNotice(`Folder “${payload.name}” created.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to create folder.';
		}
	}

	async function handleRenameFolder(id: string | number, name: string) {
		try {
			await api.updateAssetFolder(id, { name });
			await loadOrganization();
			showNotice(`Folder renamed to “${name}”.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to rename folder.';
		}
	}

	async function handleReparentFolder(id: string | number, parentId: string | null) {
		try {
			await api.updateAssetFolder(id, { parentId });
			await loadOrganization();
			showNotice('Folder moved.');
		} catch (e: any) {
			error = e?.message ?? 'Failed to move folder.';
		}
	}

	async function handleDeleteFolder(folder: DigitalAssetFolder) {
		if (!window.confirm(`Delete folder “${folder.name}”? Assets inside will become unfiled.`)) {
			return;
		}
		try {
			await api.deleteAssetFolder(folder.id);
			if (filters.folderId === String(folder.id)) {
				filters = { ...filters, folderId: null, includeSubfolders: false };
				activeSmartView = 'all';
			}
			await Promise.all([loadOrganization(), loadAssets(page)]);
			showNotice(`Folder “${folder.name}” deleted.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to delete folder.';
		}
	}

	async function handleCreateCollection(payload: { name: string; description?: string }) {
		try {
			await api.createAssetCollection(payload);
			await loadOrganization();
			showNotice(`Collection “${payload.name}” created.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to create collection.';
		}
	}

	async function handleDeleteCollection(collection: DigitalAssetCollection) {
		if (!window.confirm(`Delete collection “${collection.name}”? Assets themselves will not be deleted.`)) {
			return;
		}
		try {
			await api.deleteAssetCollection(collection.id);
			if (filters.collectionId === String(collection.id)) {
				filters = { ...filters, collectionId: null };
				activeSmartView = 'all';
			}
			await Promise.all([loadOrganization(), loadAssets(page)]);
			showNotice(`Collection “${collection.name}” deleted.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to delete collection.';
		}
	}

	async function handleCreateTag(payload: { name: string }) {
		try {
			await api.createAssetTag(payload);
			await loadOrganization();
			showNotice(`Tag “${payload.name}” created.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to create tag.';
		}
	}

	async function handleDeleteTag(tag: DigitalAssetTag) {
		if (!window.confirm(`Delete tag “${tag.name}”?`)) return;
		try {
			await api.deleteAssetTag(tag.id);
			if (filters.tagIds.includes(String(tag.id))) {
				filters = { ...filters, tagIds: filters.tagIds.filter((id) => id !== String(tag.id)) };
			}
			await Promise.all([loadOrganization(), loadAssets(page)]);
			showNotice(`Tag “${tag.name}” deleted.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to delete tag.';
		}
	}

	async function handleMoveAssetsToFolder(assetIds: string[], folderId: string | null) {
		try {
			await api.bulkDigitalAssetAction({
				assetIds,
				action: 'move_folder',
				params: { folderId },
			});
			selectedIds = new Set();
			await Promise.all([loadOrganization(), loadAssets(page)]);
			const targetLabel = folderId ? (foldersById.get(folderId)?.name ?? 'folder') : 'Unfiled';
			showNotice(`Moved ${assetIds.length} asset${assetIds.length === 1 ? '' : 's'} to ${targetLabel}.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to move assets.';
		}
	}

	async function handleAddAssetsToCollection(assetIds: string[], collectionId: string) {
		try {
			await api.addAssetsToCollection(collectionId, assetIds);
			selectedIds = new Set();
			await loadOrganization();
			const col = collections.find((c) => String(c.id) === collectionId);
			showNotice(`Added ${assetIds.length} asset${assetIds.length === 1 ? '' : 's'} to ${col?.name ?? 'collection'}.`);
		} catch (e: any) {
			error = e?.message ?? 'Failed to add assets to collection.';
		}
	}

	// Asset actions ---------------------------------------------------------

	async function handleReprocessAsset(asset: DigitalAsset) {
		try {
			await api.reprocessDigitalAsset(asset.id);
			showNotice(`Queued reprocessing for “${asset.title}”.`);
			await loadAssets(page);
		} catch (e: any) {
			error = e?.message ?? 'Failed to reprocess asset.';
		}
	}

	function requestDeleteAsset(asset: DigitalAsset) {
		deletingAsset = asset;
		deleteConflictError = '';
		deleteConflictUsageCount = 0;
		deleteConflictUsages = [];
		forceSingleDelete = false;
	}

	async function confirmSingleDelete(event: SubmitEvent) {
		event.preventDefault();
		if (!deletingAsset || deletingBusy) return;
		deletingBusy = true;
		deleteConflictError = '';
		try {
			await api.deleteDigitalAsset(deletingAsset.id, forceSingleDelete);
			const removedId = String(deletingAsset.id);
			const removedTitle = deletingAsset.title;
			deletingAsset = null;
			if (inspectingAsset && String(inspectingAsset.id) === removedId) {
				inspectingAsset = null;
			}
			if (selectedIds.has(removedId)) {
				const next = new Set(selectedIds);
				next.delete(removedId);
				selectedIds = next;
			}
			await Promise.all([loadOrganization(), loadAssets(page)]);
			showNotice(`Deleted “${removedTitle}”.`);
		} catch (e: any) {
			if (e?.status === 409 && e?.body) {
				deleteConflictError = e.body.error ?? 'This asset is currently referenced by content.';
				deleteConflictUsageCount = Number(e.body.usageCount ?? (e.body.usages?.length ?? 0));
				deleteConflictUsages = Array.isArray(e.body.usages) ? e.body.usages : [];
			} else {
				deleteConflictError = e?.message ?? 'Failed to delete asset.';
			}
		} finally {
			deletingBusy = false;
		}
	}

	function handleAssetUpdated(updated: DigitalAsset) {
		assets = assets.map((a) => (String(a.id) === String(updated.id) ? { ...a, ...updated } : a));
		if (inspectingAsset && String(inspectingAsset.id) === String(updated.id)) {
			inspectingAsset = { ...inspectingAsset, ...updated };
		}
		void loadOrganization();
	}

	// Global OS File Drag-and-Drop Overlay ----------------------------------

	function isExternalFileDrag(event: DragEvent): boolean {
		const types = Array.from(event.dataTransfer?.types ?? []);
		return (
			types.includes('Files') &&
			!types.includes('application/x-iikiti-asset-ids') &&
			!types.includes('application/x-iikiti-folder-id')
		);
	}

	function onWindowDragEnter(event: DragEvent) {
		if (uploadOpen || !isExternalFileDrag(event)) return;
		event.preventDefault();
		windowFileDragDepth += 1;
	}

	function onWindowDragOver(event: DragEvent) {
		if (uploadOpen || !isExternalFileDrag(event)) return;
		event.preventDefault();
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy';
		}
	}

	function onWindowDragLeave(event: DragEvent) {
		if (uploadOpen || !isExternalFileDrag(event)) return;
		if (event.relatedTarget === null) {
			windowFileDragDepth = 0;
			return;
		}
		windowFileDragDepth = Math.max(0, windowFileDragDepth - 1);
	}

	function onWindowDrop(event: DragEvent) {
		if (uploadOpen || !isExternalFileDrag(event)) return;
		event.preventDefault();
		windowFileDragDepth = 0;
		const files = Array.from(event.dataTransfer?.files ?? []);
		if (files.length > 0) {
			droppedFiles = files;
			uploadOpen = true;
		}
	}
</script>

<svelte:window
	ondragenter={onWindowDragEnter}
	ondragover={onWindowDragOver}
	ondragleave={onWindowDragLeave}
	ondrop={onWindowDrop}
	ondragend={() => (windowFileDragDepth = 0)}
	onblur={() => (windowFileDragDepth = 0)}
/>

<!-- Full-window file drop overlay -->
{#if windowFileDragDepth > 0 && !uploadOpen}
	<div
		class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs"
		aria-hidden="true"
	>
		<div class="flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-accent bg-surface px-12 py-10 text-center shadow-xl">
			<div class="rounded-full bg-accent-subtle p-4 text-accent">
				<Icon name="upload" size={32} />
			</div>
			<p class="text-lg font-semibold text-text">Drop files anywhere to upload</p>
			<p class="text-sm text-text-muted">Files will be queued with SHA-256 duplicate protection.</p>
		</div>
	</div>
{/if}

<PageHeader
	title="Digital Assets"
	description="Organize, process, localize, version, and share images, video, audio, and documents across your sites."
/>

{#if error}
	<div class="mb-4">
		<ErrorBoundary message={error} onRetry={() => void loadAssets(page)} />
	</div>
{/if}

{#if notice}
	<div class="mb-4 flex items-center justify-between gap-2 rounded-lg border border-success/40 bg-success-subtle px-3.5 py-2.5 text-sm text-success" role="status">
		<span>{notice}</span>
		<button type="button" class="rounded p-0.5 hover:opacity-75" aria-label="Dismiss message" onclick={() => (notice = null)}>
			<Icon name="x" size={14} />
		</button>
	</div>
{/if}

<div class="flex flex-col items-start gap-5 lg:flex-row">
	<AssetSidebar
		{folders}
		{collections}
		{tags}
		{facets}
		{activeSmartView}
		activeFolderId={filters.folderId}
		includeSubfolders={filters.includeSubfolders}
		activeCollectionId={filters.collectionId}
		selectedTagIds={filters.tagIds}
		onSelectSmartView={selectSmartView}
		onSelectFolder={selectFolder}
		onToggleIncludeSubfolders={(value) => updateFilters({ includeSubfolders: value })}
		onSelectCollection={selectCollection}
		onToggleTag={toggleTagFilter}
		onCreateFolder={handleCreateFolder}
		onRenameFolder={handleRenameFolder}
		onReparentFolder={handleReparentFolder}
		onDeleteFolder={handleDeleteFolder}
		onCreateCollection={handleCreateCollection}
		onDeleteCollection={handleDeleteCollection}
		onCreateTag={handleCreateTag}
		onDeleteTag={handleDeleteTag}
		onMoveAssetsToFolder={handleMoveAssetsToFolder}
		onAddAssetsToCollection={handleAddAssetsToCollection}
	/>

	<div class="min-w-0 flex-1 space-y-4">
		<AssetToolbar
			{filters}
			{viewMode}
			{sites}
			{folders}
			{collections}
			{tags}
			{facets}
			{totalItems}
			onUpdateFilters={updateFilters}
			onResetFilters={resetAllFilters}
			onChangeViewMode={setViewMode}
			onOpenUpload={() => {
				droppedFiles = [];
				uploadOpen = true;
			}}
			onOpenExternalUri={() => (externalUriOpen = true)}
		/>

		{#if loading && assets.length === 0}
			<LoadingState label="Loading digital assets…" />
		{:else if assets.length === 0}
			<div class="rounded-xl border border-border bg-surface p-4">
				<EmptyState
					icon="image"
					title="No digital assets found"
					description="Drag and drop files here, upload new assets, or clear your active filters."
				/>
				<div class="mb-6 flex flex-wrap justify-center gap-2">
					<button
						type="button"
						class="admin-btn admin-btn-primary admin-btn-sm gap-1.5"
						onclick={() => {
							droppedFiles = [];
							uploadOpen = true;
						}}
					>
						<Icon name="upload" size={14} />
						<span>Upload Files</span>
					</button>
					<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick={resetAllFilters}>
						Reset Filters
					</button>
				</div>
			</div>
		{:else}
			<div class:opacity-60={loading} class="transition-opacity">
				{#if viewMode === 'grid'}
					<AssetGrid
						{assets}
						{selectedIds}
						{tagsById}
						onToggleSelect={handleToggleSelect}
						onToggleSelectAll={handleToggleSelectAll}
						onInspect={(asset) => (inspectingAsset = asset)}
					/>
				{:else}
					<AssetList
						{assets}
						{selectedIds}
						{sites}
						{foldersById}
						{tagsById}
						onToggleSelect={handleToggleSelect}
						onToggleSelectAll={handleToggleSelectAll}
						onInspect={(asset) => (inspectingAsset = asset)}
						onReprocess={(asset) => void handleReprocessAsset(asset)}
						onDelete={requestDeleteAsset}
					/>
				{/if}
			</div>

			<Pagination
				currentPage={page}
				{totalPages}
				{totalItems}
				{itemsPerPage}
				onPageChange={(nextPage) => {
					page = nextPage;
					selectedIds = new Set();
					void loadAssets(nextPage);
				}}
			/>
		{/if}

		<BulkActionBar
			{api}
			{selectedIds}
			{folders}
			{collections}
			{tags}
			{sites}
			onClearSelection={() => {
				selectedIds = new Set();
				lastSelectedId = null;
			}}
			onPartialDelete={async (succeededIds, msg) => {
				const removed = new Set(succeededIds.map(String));
				selectedIds = new Set(Array.from(selectedIds).filter((id) => !removed.has(id)));
				if (lastSelectedId && removed.has(lastSelectedId)) {
					lastSelectedId = null;
				}
				showNotice(msg);
				await Promise.all([loadOrganization(), loadAssets(page)]);
			}}
			onActionComplete={async (msg) => {
				showNotice(msg);
				await Promise.all([loadOrganization(), loadAssets(page)]);
			}}
			onError={(msg) => (error = msg)}
		/>
	</div>
</div>

<!-- Multi-file Upload Queue Modal -->
<UploadDropzone
	{api}
	open={uploadOpen}
	initialFiles={droppedFiles}
	{folders}
	{tags}
	{sites}
	{defaultSiteId}
	defaultFolderId={filters.folderId}
	onClose={() => {
		uploadOpen = false;
		droppedFiles = [];
	}}
	onUploadsComplete={(count) => {
		if (count > 0) {
			showNotice(`Uploaded ${count} asset${count === 1 ? '' : 's'}. Background processing started.`);
			page = 1;
			void Promise.all([loadOrganization(), loadAssets(1)]);
		}
	}}
/>

<!-- External HTTPS URI Modal -->
<ExternalUriModal
	{api}
	open={externalUriOpen}
	{folders}
	{tags}
	{sites}
	{defaultSiteId}
	defaultFolderId={filters.folderId}
	onClose={() => (externalUriOpen = false)}
	onCreated={async () => {
		showNotice('External asset added.');
		page = 1;
		await Promise.all([loadOrganization(), loadAssets(1)]);
	}}
/>

<!-- Rich Asset Detail Modal -->
<AssetDetailModal
	{api}
	asset={inspectingAsset}
	open={inspectingAsset !== null}
	{sites}
	{folders}
	{tags}
	{defaultLocale}
	{supportedContentLocales}
	onClose={() => (inspectingAsset = null)}
	onAssetUpdated={handleAssetUpdated}
	onRequestDelete={(asset) => requestDeleteAsset(asset)}
/>

<!-- Single Asset Delete & 409 In-Use Conflict Modal -->
{#if deletingAsset}
	<ModalDialog
		title={`Delete “${deletingAsset.title}”`}
		open={true}
		onClose={() => {
			if (!deletingBusy) deletingAsset = null;
		}}
		width={520}
	>
		<form class="space-y-4 p-2" onsubmit={confirmSingleDelete}>
			<p class="text-sm text-text">
				Permanently delete <strong>{deletingAsset.title}</strong> and its stored derivative files?
			</p>

			{#if deleteConflictError}
				<div class="rounded-lg border border-danger/40 bg-danger-subtle p-3 text-sm text-danger" role="alert">
					{deleteConflictError}
				</div>
			{/if}

			{#if deleteConflictUsageCount > 0 || deleteConflictUsages.length > 0}
				<div class="max-h-52 space-y-2 overflow-y-auto rounded-lg border border-warning/40 bg-warning-subtle/50 p-3 text-xs">
					<div class="font-semibold text-warning">
						Referenced in {deleteConflictUsageCount || deleteConflictUsages.length} place{(deleteConflictUsageCount || deleteConflictUsages.length) === 1 ? '' : 's'}:
					</div>
					<ul class="list-disc space-y-1 pl-4 text-text">
						{#each deleteConflictUsages as usage, idx (idx)}
							<li>
								<strong>{usage.entityType}</strong>: {usage.title || usage.label || `#${usage.entityId}`}
								{#if usage.isDraft}
									<span class="text-warning">(Draft)</span>
								{/if}
							</li>
						{/each}
					</ul>
				</div>

				<label class="flex cursor-pointer items-center gap-2 rounded-lg border border-danger/30 bg-danger-subtle/40 p-2.5 text-sm text-text">
					<input type="checkbox" bind:checked={forceSingleDelete} />
					<span><strong>Force Delete:</strong> remove anyway despite active references</span>
				</label>
			{/if}

			<div class="flex justify-end gap-2">
				<button
					type="button"
					class="admin-btn admin-btn-secondary admin-btn-sm"
					disabled={deletingBusy}
					onclick={() => (deletingAsset = null)}
				>
					Cancel
				</button>
				<button
					type="submit"
					class="admin-btn admin-btn-danger admin-btn-sm"
					disabled={deletingBusy || ((deleteConflictUsageCount > 0 || deleteConflictUsages.length > 0) && !forceSingleDelete)}
				>
					{deletingBusy ? 'Deleting…' : forceSingleDelete ? 'Force Delete' : 'Delete Asset'}
				</button>
			</div>
		</form>
	</ModalDialog>
{/if}
