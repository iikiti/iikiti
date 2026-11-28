<script lang="ts">
	import SearchForm from '$components/SearchForm.svelte';
	import Icon from '$components/Icon.svelte';
	import type {
		DigitalAssetCollection,
		DigitalAssetFacets,
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
	} from '$types';

	export interface AssetFilterState {
		q: string;
		mimeCategory: string;
		siteId: string;
		visibility: string;
		processingStatus: string;
		licenseStatus: string;
		sort: string;
		folderId: string | null;
		includeSubfolders: boolean;
		collectionId: string | null;
		tagIds: string[];
	}

	interface Props {
		filters: AssetFilterState;
		viewMode: 'grid' | 'list';
		sites: DigitalAssetSiteOption[];
		folders: DigitalAssetFolder[];
		collections: DigitalAssetCollection[];
		tags: DigitalAssetTag[];
		facets?: DigitalAssetFacets | null;
		totalItems: number;
		onUpdateFilters: (patch: Partial<AssetFilterState>) => void;
		onResetFilters: () => void;
		onChangeViewMode: (mode: 'grid' | 'list') => void;
		onOpenUpload: () => void;
		onOpenExternalUri: () => void;
	}

	let {
		filters,
		viewMode,
		sites,
		folders,
		collections,
		tags,
		facets = null,
		totalItems,
		onUpdateFilters,
		onResetFilters,
		onChangeViewMode,
		onOpenUpload,
		onOpenExternalUri,
	}: Props = $props();

	const MIME_PILLS = [
		{ value: 'all', label: 'All', icon: 'layers' },
		{ value: 'image', label: 'Images', icon: 'image' },
		{ value: 'video', label: 'Video', icon: 'film' },
		{ value: 'audio', label: 'Audio', icon: 'music' },
		{ value: 'document', label: 'Documents', icon: 'file-text' },
	];

	const activeChips = $derived.by(() => {
		const chips: Array<{ key: string; label: string; clear: () => void }> = [];
		if (filters.q.trim()) {
			chips.push({ key: 'q', label: `Search: "${filters.q.trim()}"`, clear: () => onUpdateFilters({ q: '' }) });
		}
		if (filters.mimeCategory && filters.mimeCategory !== 'all') {
			chips.push({
				key: 'mime',
				label: `Type: ${filters.mimeCategory}`,
				clear: () => onUpdateFilters({ mimeCategory: 'all' }),
			});
		}
		if (filters.siteId) {
			const site = sites.find((s) => String(s.id) === String(filters.siteId));
			chips.push({
				key: 'site',
				label: `Site: ${site?.name ?? filters.siteId}`,
				clear: () => onUpdateFilters({ siteId: '' }),
			});
		}
		if (filters.visibility) {
			chips.push({
				key: 'visibility',
				label: `Visibility: ${filters.visibility}`,
				clear: () => onUpdateFilters({ visibility: '' }),
			});
		}
		if (filters.processingStatus) {
			chips.push({
				key: 'status',
				label: `Status: ${filters.processingStatus}`,
				clear: () => onUpdateFilters({ processingStatus: '' }),
			});
		}
		if (filters.licenseStatus) {
			const licenseLabels: Record<string, string> = {
				valid: 'Valid license',
				expiring_or_expired: 'Expiring / Expired',
				expiring_soon: 'Expiring soon',
				expired: 'Expired',
				missing: 'No license',
			};
			chips.push({
				key: 'license',
				label: `License: ${licenseLabels[filters.licenseStatus] ?? filters.licenseStatus.replace(/_/g, ' ')}`,
				clear: () => onUpdateFilters({ licenseStatus: '' }),
			});
		}
		if (filters.folderId) {
			const label =
				filters.folderId === 'unfiled'
					? 'Unfiled'
					: (folders.find((f) => String(f.id) === String(filters.folderId))?.name ?? `Folder #${filters.folderId}`);
			chips.push({
				key: 'folder',
				label: `Folder: ${label}`,
				clear: () => onUpdateFilters({ folderId: null, includeSubfolders: false }),
			});
		}
		if (filters.collectionId) {
			const col = collections.find((c) => String(c.id) === String(filters.collectionId));
			chips.push({
				key: 'collection',
				label: `Collection: ${col?.name ?? filters.collectionId}`,
				clear: () => onUpdateFilters({ collectionId: null }),
			});
		}
		for (const tagId of filters.tagIds) {
			const tag = tags.find((t) => String(t.id) === String(tagId));
			chips.push({
				key: `tag:${tagId}`,
				label: `Tag: ${tag?.name ?? tagId}`,
				clear: () => onUpdateFilters({ tagIds: filters.tagIds.filter((id) => id !== tagId) }),
			});
		}
		return chips;
	});

	function facetCountForMime(value: string): number | null {
		const map = facets?.mimeCategory ?? facets?.mimeCategories;
		if (!map) return null;
		if (value === 'all') {
			if (typeof facets?.totalCount === 'number') return facets.totalCount;
			return Object.values(map).reduce((sum, n) => sum + (Number(n) || 0), 0);
		}
		const val = map[value];
		return typeof val === 'number' ? val : null;
	}
</script>

<div class="space-y-3 rounded-xl border border-border bg-surface p-3.5">
	<!-- Primary Row: Search + View Mode + Primary Actions -->
	<div class="flex flex-wrap items-center justify-between gap-3">
		<div class="min-w-56 flex-1">
			<SearchForm
				value={filters.q}
				placeholder="Search title, filename, alt text, tags…"
				onSearch={(q) => {
					if (q !== filters.q) {
						onUpdateFilters({ q });
					}
				}}
			/>
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<div class="inline-flex rounded-lg border border-border bg-bg p-0.5" role="group" aria-label="View mode">
				<button
					type="button"
					class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors {viewMode === 'grid'
						? 'bg-surface text-accent shadow-sm'
						: 'text-text-muted hover:text-text'}"
					aria-pressed={viewMode === 'grid'}
					title="Grid view"
					onclick={() => onChangeViewMode('grid')}
				>
					<Icon name="layout-grid" size={14} />
					<span class="hidden sm:inline">Grid</span>
				</button>
				<button
					type="button"
					class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors {viewMode === 'list'
						? 'bg-surface text-accent shadow-sm'
						: 'text-text-muted hover:text-text'}"
					aria-pressed={viewMode === 'list'}
					title="List view"
					onclick={() => onChangeViewMode('list')}
				>
					<Icon name="list" size={14} />
					<span class="hidden sm:inline">List</span>
				</button>
			</div>

			<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm gap-1.5" onclick={onOpenExternalUri}>
				<Icon name="link-2" size={14} />
				<span>Add External URL</span>
			</button>

			<button type="button" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" onclick={onOpenUpload}>
				<Icon name="upload" size={14} />
				<span>Upload Files</span>
			</button>
		</div>
	</div>

	<!-- Secondary Row: MIME Pills + Faceted Dropdowns -->
	<div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
		<div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by media type">
			{#each MIME_PILLS as pill (pill.value)}
				{@const isActive = (filters.mimeCategory || 'all') === pill.value}
				{@const count = facetCountForMime(pill.value)}
				<button
					type="button"
					class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors {isActive
						? 'border-accent bg-accent-subtle text-accent'
						: 'border-border bg-bg text-text-muted hover:text-text'}"
					aria-pressed={isActive}
					onclick={() => onUpdateFilters({ mimeCategory: pill.value })}
				>
					<Icon name={pill.icon} size={12} />
					<span>{pill.label}</span>
					{#if count !== null}
						<span class="opacity-70">{count}</span>
					{/if}
				</button>
			{/each}
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<select
				class="admin-input admin-input-sm w-auto min-w-32 text-xs"
				aria-label="Filter by site grant"
				value={filters.siteId}
				onchange={(e) => onUpdateFilters({ siteId: e.currentTarget.value })}
			>
				<option value="">All sites</option>
				{#each sites as site (String(site.id))}
					<option value={String(site.id)}>{site.name || site.domain}</option>
				{/each}
			</select>

			<select
				class="admin-input admin-input-sm w-auto text-xs"
				aria-label="Filter by visibility"
				value={filters.visibility}
				onchange={(e) => onUpdateFilters({ visibility: e.currentTarget.value })}
			>
				<option value="">All visibility</option>
				<option value="public">Public</option>
				<option value="private">Private</option>
			</select>

			<select
				class="admin-input admin-input-sm w-auto text-xs"
				aria-label="Filter by processing status"
				value={filters.processingStatus}
				onchange={(e) => onUpdateFilters({ processingStatus: e.currentTarget.value })}
			>
				<option value="">All statuses</option>
				<option value="complete">Complete</option>
				<option value="processing">Processing</option>
				<option value="pending">Pending</option>
				<option value="failed">Failed</option>
			</select>

			<select
				class="admin-input admin-input-sm w-auto text-xs"
				aria-label="Filter by rights/license status"
				value={filters.licenseStatus}
				onchange={(e) => onUpdateFilters({ licenseStatus: e.currentTarget.value })}
			>
				<option value="">All rights</option>
				<option value="valid">Valid license</option>
				<option value="expiring_or_expired">Expiring / Expired</option>
				<option value="expiring_soon">Expiring soon</option>
				<option value="expired">Expired</option>
				<option value="missing">No license</option>
			</select>

			<select
				class="admin-input admin-input-sm w-auto text-xs"
				aria-label="Sort assets"
				value={filters.sort}
				onchange={(e) => onUpdateFilters({ sort: e.currentTarget.value })}
			>
				<option value="newest">Newest first</option>
				<option value="oldest">Oldest first</option>
				<option value="title_asc">Title A–Z</option>
				<option value="title_desc">Title Z–A</option>
				<option value="size_desc">Largest first</option>
				<option value="size_asc">Smallest first</option>
			</select>
		</div>
	</div>

	<!-- Active Filter Chips -->
	{#if activeChips.length > 0}
		<div class="flex flex-wrap items-center gap-1.5 border-t border-border pt-2.5 text-xs">
			<span class="text-text-muted">{totalItems} matching:</span>
			{#each activeChips as chip (chip.key)}
				<span class="inline-flex items-center gap-1 rounded-full bg-accent-subtle px-2.5 py-0.5 text-accent">
					<span>{chip.label}</span>
					<button
						type="button"
						class="rounded-full hover:text-text"
						aria-label={`Remove ${chip.label} filter`}
						onclick={chip.clear}
					>
						<Icon name="x" size={11} />
					</button>
				</span>
			{/each}
			<button
				type="button"
				class="ml-1 text-text-muted underline hover:text-text"
				onclick={onResetFilters}
			>
				Clear all
			</button>
		</div>
	{/if}
</div>
