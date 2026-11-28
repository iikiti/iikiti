<script lang="ts">
	import Icon from '$components/Icon.svelte';
	import Badge from '$components/Badge.svelte';
	import {
		buildAssetMediaValue,
		formatBytes,
		formatObjectPosition,
		isImageMimeType,
		resolvePreviewUrl,
	} from '$lib/mediaHelpers.js';
	import { formatDuration, getExpiryStatus } from '$lib/damHelpers.js';
	import type {
		DigitalAsset,
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
	} from '$types';

	interface Props {
		assets: DigitalAsset[];
		selectedIds: Set<string>;
		sites: DigitalAssetSiteOption[];
		foldersById: Map<string, DigitalAssetFolder>;
		tagsById: Map<string, DigitalAssetTag>;
		onToggleSelect: (id: string, shiftKey: boolean) => void;
		onToggleSelectAll: () => void;
		onInspect: (asset: DigitalAsset) => void;
		onReprocess: (asset: DigitalAsset) => void;
		onDelete: (asset: DigitalAsset) => void;
	}

	let {
		assets,
		selectedIds,
		sites,
		foldersById,
		tagsById,
		onToggleSelect,
		onToggleSelectAll,
		onInspect,
		onReprocess,
		onDelete,
	}: Props = $props();

	let brokenPreviews = $state<Record<string, boolean>>({});

	const allVisibleSelected = $derived(
		assets.length > 0 && assets.every((asset) => selectedIds.has(String(asset.id))),
	);
	const someVisibleSelected = $derived(
		!allVisibleSelected && assets.some((asset) => selectedIds.has(String(asset.id))),
	);

	function previewSrc(asset: DigitalAsset): string {
		return resolvePreviewUrl(buildAssetMediaValue(asset));
	}

	function canShowImage(asset: DigitalAsset): boolean {
		const src = previewSrc(asset);
		if (!src) return false;
		const key = `${String(asset.id)}:${src}`;
		if (brokenPreviews[key]) return false;
		return isImageMimeType(asset.mimeType) || Boolean(asset.thumbnailUrl || asset.previewUrl);
	}

	function fallbackIcon(mimeType: string): string {
		const mime = String(mimeType ?? '').toLowerCase();
		if (mime.startsWith('image/')) return 'image-off';
		if (mime.startsWith('video/')) return 'film';
		if (mime.startsWith('audio/')) return 'music';
		return 'file-text';
	}

	function siteLabels(ids: Array<string | number>): string {
		if (!Array.isArray(ids) || ids.length === 0) return 'No sites';
		return ids
			.map((id) => sites.find((s) => String(s.id) === String(id))?.name ?? `#${id}`)
			.join(', ');
	}

	function folderName(asset: DigitalAsset): string | null {
		if (asset.folder?.name) return asset.folder.name;
		if (asset.folderId != null) {
			return foldersById.get(String(asset.folderId))?.name ?? null;
		}
		return null;
	}

	function resolveTags(asset: DigitalAsset): DigitalAssetTag[] {
		if (Array.isArray(asset.tags) && asset.tags.length > 0) return asset.tags;
		if (Array.isArray(asset.tagIds) && asset.tagIds.length > 0) {
			return asset.tagIds
				.map((id) => tagsById.get(String(id)))
				.filter((t): t is DigitalAssetTag => Boolean(t));
		}
		return [];
	}

	function statusBadgeType(status: string): 'success' | 'warning' | 'danger' | 'info' {
		if (status === 'complete') return 'success';
		if (status === 'failed') return 'danger';
		if (status === 'processing') return 'info';
		return 'warning';
	}

	function handleDragStart(event: DragEvent, asset: DigitalAsset) {
		const idStr = String(asset.id);
		const ids = selectedIds.has(idStr) && selectedIds.size > 0 ? Array.from(selectedIds) : [idStr];
		event.dataTransfer?.setData('application/x-iikiti-asset-ids', JSON.stringify(ids));
		event.dataTransfer?.setData('text/plain', ids.length > 1 ? `${ids.length} assets` : asset.title);
		if (event.dataTransfer) {
			event.dataTransfer.effectAllowed = 'copyMove';
		}
	}
</script>

<div class="overflow-x-auto rounded-xl border border-border bg-surface">
	<table class="admin-table min-w-full">
		<thead>
			<tr class="border-b border-border">
				<th class="w-10">
					<input
						type="checkbox"
						checked={allVisibleSelected}
						indeterminate={someVisibleSelected}
						aria-label="Select all assets on page"
						onchange={onToggleSelectAll}
					/>
				</th>
				<th>Asset</th>
				<th>Organization</th>
				<th>Sites</th>
				<th>Visibility</th>
				<th>Status</th>
				<th>Usage / Rights</th>
				<th class="text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			{#each assets as asset (String(asset.id))}
				{@const idStr = String(asset.id)}
				{@const isSelected = selectedIds.has(idStr)}
				{@const src = previewSrc(asset)}
				{@const folder = folderName(asset)}
				{@const assetTags = resolveTags(asset)}
				{@const expiryStatus = getExpiryStatus(asset.license)}
				{@const durationLabel = formatDuration(asset.durationMs)}

				<tr
					class={isSelected ? 'bg-accent-subtle/40' : ''}
					draggable="true"
					ondragstart={(e) => handleDragStart(e, asset)}
				>
					<td class="align-middle">
						<input
							type="checkbox"
							checked={isSelected}
							aria-label={`Select ${asset.title}`}
							onclick={(e) => onToggleSelect(idStr, e.shiftKey)}
						/>
					</td>
					<td>
						<div class="flex items-center gap-3">
							<button
								type="button"
								class="relative h-11 w-14 shrink-0 overflow-hidden rounded-md border border-border bg-bg"
								style={asset.dominantColor ? `background-color: ${asset.dominantColor}` : undefined}
								onclick={() => onInspect(asset)}
								aria-label={`Inspect ${asset.title}`}
							>
								{#if canShowImage(asset)}
									<img
										{src}
										alt={asset.defaultAlt || asset.title}
										loading="lazy"
										class="h-full w-full object-cover"
										style={`object-position: ${formatObjectPosition(asset.focalPoint)}`}
										onerror={() => {
											brokenPreviews = { ...brokenPreviews, [`${idStr}:${src}`]: true };
										}}
									/>
								{:else}
									<span class="flex h-full w-full items-center justify-center text-text-muted">
										<Icon name={fallbackIcon(asset.mimeType)} size={18} />
									</span>
								{/if}
							</button>

							<div class="min-w-0">
								<button
									type="button"
									class="block max-w-64 truncate text-left font-medium text-text hover:text-accent hover:underline"
									onclick={() => onInspect(asset)}
									title={asset.title}
								>
									{asset.title}
								</button>
								<div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-text-muted">
									<span>{asset.mimeType}</span>
									{#if asset.size}
										<span>·</span>
										<span>{formatBytes(asset.size)}</span>
									{/if}
									{#if asset.width && asset.height}
										<span>·</span>
										<span>{asset.width}×{asset.height}</span>
									{/if}
									{#if durationLabel}
										<span>·</span>
										<span>{durationLabel}</span>
									{/if}
								</div>
							</div>
						</div>
					</td>
					<td>
						<div class="flex flex-wrap items-center gap-1">
							{#if folder}
								<span class="inline-flex items-center gap-1 rounded bg-bg px-2 py-0.5 text-xs text-text">
									<Icon name="folder" size={11} />
									{folder}
								</span>
							{:else}
								<span class="text-xs text-text-muted">Unfiled</span>
							{/if}
							{#each assetTags.slice(0, 3) as tag (String(tag.id))}
								<span class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-text-muted">
									<Icon name="tag" size={10} />
									{tag.name}
								</span>
							{/each}
							{#if assetTags.length > 3}
								<span class="text-[11px] text-text-muted">+{assetTags.length - 3}</span>
							{/if}
						</div>
					</td>
					<td class="max-w-40 text-xs text-text-muted">
						<span class="line-clamp-2" title={siteLabels(asset.siteIds)}>{siteLabels(asset.siteIds)}</span>
					</td>
					<td>
						<Badge status={asset.visibility === 'public' ? 'info' : 'primary'} outline>
							<span class="inline-flex items-center gap-1">
								<Icon name={asset.visibility === 'public' ? 'globe' : 'lock'} size={11} />
								{asset.visibility}
							</span>
						</Badge>
					</td>
					<td>
						<div class="space-y-1">
							<Badge status={statusBadgeType(asset.processingStatus)}>
								{asset.processingStatus || 'unknown'}
							</Badge>
							{#if asset.workflowInstances?.some((w) => w.error)}
								{@const failedRun = asset.workflowInstances.find((w) => w.error)}
								<div class="max-w-44 truncate text-[11px] text-danger" title={failedRun?.error ?? ''}>
									{failedRun?.error}
								</div>
							{/if}
						</div>
					</td>
					<td>
						<div class="flex flex-wrap items-center gap-1.5 text-xs">
							<span class="inline-flex items-center gap-1 text-text-muted" title="Reference count">
								<Icon name="link" size={12} />
								{asset.usageCount ?? 0}
							</span>
							{#if expiryStatus === 'expired'}
								<Badge status="danger">Expired</Badge>
							{:else if expiryStatus === 'expiring'}
								<Badge status="warning">Expiring</Badge>
							{:else if expiryStatus === 'active'}
								<Badge status="success" outline>Licensed</Badge>
							{/if}
						</div>
					</td>
					<td class="text-right">
						<div class="inline-flex items-center justify-end gap-1">
							<button
								type="button"
								class="admin-btn admin-btn-secondary admin-btn-sm"
								title="Inspect & Edit"
								aria-label={`Inspect ${asset.title}`}
								onclick={() => onInspect(asset)}
							>
								<Icon name="eye" size={14} />
							</button>
							{#if asset.type === 'upload'}
								<button
									type="button"
									class="admin-btn admin-btn-secondary admin-btn-sm"
									title="Reprocess variants & metadata"
									aria-label={`Reprocess ${asset.title}`}
									onclick={() => onReprocess(asset)}
								>
									<Icon name="refresh-cw" size={14} />
								</button>
							{/if}
							<button
								type="button"
								class="admin-btn admin-btn-danger admin-btn-sm"
								title="Delete asset"
								aria-label={`Delete ${asset.title}`}
								onclick={() => onDelete(asset)}
							>
								<Icon name="trash-2" size={14} />
							</button>
						</div>
					</td>
				</tr>
			{/each}
		</tbody>
	</table>
</div>
