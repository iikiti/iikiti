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
	import { formatDuration, getExpiryStatus, shortMimeBadge } from '$lib/damHelpers.js';
	import type { DigitalAsset, DigitalAssetTag } from '$types';

	interface Props {
		assets: DigitalAsset[];
		selectedIds: Set<string>;
		tagsById: Map<string, DigitalAssetTag>;
		expiryWarningDays?: number;
		onToggleSelect: (id: string, shiftKey: boolean) => void;
		onToggleSelectAll: () => void;
		onInspect: (asset: DigitalAsset) => void;
	}

	let {
		assets,
		selectedIds,
		tagsById,
		expiryWarningDays = 14,
		onToggleSelect,
		onToggleSelectAll,
		onInspect,
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

	function resolveTags(asset: DigitalAsset): DigitalAssetTag[] {
		if (Array.isArray(asset.tags) && asset.tags.length > 0) return asset.tags;
		if (Array.isArray(asset.tagIds) && asset.tagIds.length > 0) {
			return asset.tagIds
				.map((id) => tagsById.get(String(id)))
				.filter((t): t is DigitalAssetTag => Boolean(t));
		}
		return [];
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

<div class="space-y-3">
	{#if assets.length > 0}
		<div class="flex items-center justify-between text-xs text-text-muted">
			<label class="inline-flex cursor-pointer items-center gap-2 select-none">
				<input
					type="checkbox"
					checked={allVisibleSelected}
					indeterminate={someVisibleSelected}
					onchange={onToggleSelectAll}
				/>
				<span>
					{#if selectedIds.size > 0}
						{selectedIds.size} selected (Shift+click for range)
					{:else}
						Select all on page (Shift+click for range · drag cards to folders)
					{/if}
				</span>
			</label>
		</div>
	{/if}

	<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
		{#each assets as asset (String(asset.id))}
			{@const idStr = String(asset.id)}
			{@const isSelected = selectedIds.has(idStr)}
			{@const src = previewSrc(asset)}
			{@const formatBadge = shortMimeBadge(asset.mimeType, asset.variants)}
			{@const durationLabel = formatDuration(asset.durationMs)}
			{@const expiryStatus = getExpiryStatus(asset.license, undefined, expiryWarningDays)}
			{@const assetTags = resolveTags(asset)}

			<article
				class="group relative flex flex-col overflow-hidden rounded-xl border bg-surface transition-all hover:border-border-strong hover:shadow-md {isSelected
					? 'border-accent ring-2 ring-accent/40'
					: 'border-border'}"
				draggable="true"
				ondragstart={(e) => handleDragStart(e, asset)}
			>
				<!-- Top-left: Selection checkbox (sibling outside inspect button for valid interactive nesting) -->
				<div
					class="absolute top-2 left-2 z-10 rounded-md bg-surface/90 p-1 shadow-sm backdrop-blur-xs transition-opacity {isSelected
						? 'opacity-100'
						: 'opacity-0 group-hover:opacity-100 focus-within:opacity-100'}"
				>
					<input
						type="checkbox"
						class="block h-4 w-4 cursor-pointer accent-[var(--accent)]"
						checked={isSelected}
						aria-label={`Select ${asset.title}`}
						onclick={(e) => {
							e.stopPropagation();
							onToggleSelect(idStr, e.shiftKey);
						}}
					/>
				</div>

				<!-- Full card inspect button (thumbnail + footer) -->
				<button
					type="button"
					class="flex flex-1 cursor-pointer flex-col text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent"
					aria-label={`Inspect ${asset.title}`}
					onclick={() => onInspect(asset)}
				>
					<!-- Thumbnail stage -->
					<div
						class="relative aspect-[4/3] w-full overflow-hidden bg-bg"
						style={asset.dominantColor ? `background-color: ${asset.dominantColor}` : undefined}
					>
						{#if canShowImage(asset)}
							<img
								{src}
								alt={asset.defaultAlt || asset.title}
								loading="lazy"
								class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]"
								style={`object-position: ${formatObjectPosition(asset.focalPoint)}`}
								onerror={() => {
									brokenPreviews = { ...brokenPreviews, [`${idStr}:${src}`]: true };
								}}
							/>
						{:else}
							<div class="flex h-full w-full items-center justify-center text-text-muted">
								<Icon name={fallbackIcon(asset.mimeType)} size={36} />
							</div>
						{/if}

						<!-- Top-right: Visibility + Format badge -->
						<div class="pointer-events-none absolute top-2 right-2 flex items-center gap-1">
							<span
								class="inline-flex items-center rounded bg-black/65 p-1 text-white backdrop-blur-xs"
								title={asset.visibility === 'public' ? 'Public' : 'Private'}
							>
								<Icon name={asset.visibility === 'public' ? 'globe' : 'lock'} size={11} />
							</span>
							{#if formatBadge}
								<span class="rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-white backdrop-blur-xs">
									{formatBadge}
								</span>
							{/if}
						</div>

						<!-- Bottom-left: Non-complete processing status -->
						{#if asset.processingStatus && asset.processingStatus !== 'complete'}
							<div class="pointer-events-none absolute bottom-2 left-2">
								<Badge status={asset.processingStatus === 'failed' ? 'danger' : 'warning'}>
									{asset.processingStatus}
								</Badge>
							</div>
						{/if}

						<!-- Bottom-right: Duration pill for video/audio -->
						{#if durationLabel}
							<span class="pointer-events-none absolute right-2 bottom-2 rounded bg-black/75 px-1.5 py-0.5 text-[11px] font-medium text-white">
								{durationLabel}
							</span>
						{/if}
					</div>

					<!-- Card Footer -->
					<div class="flex w-full flex-1 flex-col gap-1.5 p-2.5">
						<div class="truncate text-sm font-medium text-text" title={asset.title}>
							{asset.title}
						</div>

						<div class="flex flex-wrap items-center gap-x-1.5 text-[11px] text-text-muted">
							{#if asset.width && asset.height}
								<span>{asset.width}×{asset.height}</span>
								<span>·</span>
							{/if}
							<span>{asset.size ? formatBytes(asset.size) : asset.type === 'external' ? 'External URL' : '—'}</span>
						</div>

						{#if (asset.usageCount && asset.usageCount > 0) || expiryStatus === 'expiring' || expiryStatus === 'expired' || assetTags.length > 0}
							<div class="mt-auto flex flex-wrap items-center gap-1 pt-1">
								{#if asset.usageCount && asset.usageCount > 0}
									<span
										class="inline-flex items-center gap-0.5 rounded bg-info-subtle px-1.5 py-0.5 text-[10px] font-medium text-info"
										title={`Used in ${asset.usageCount} place${asset.usageCount === 1 ? '' : 's'}`}
									>
										<Icon name="link" size={10} />
										{asset.usageCount}
									</span>
								{/if}

								{#if expiryStatus === 'expired'}
									<span class="rounded bg-danger-subtle px-1.5 py-0.5 text-[10px] font-medium text-danger">
										Expired
									</span>
								{:else if expiryStatus === 'expiring'}
									<span class="rounded bg-warning-subtle px-1.5 py-0.5 text-[10px] font-medium text-warning">
										Expiring
									</span>
								{/if}

								{#each assetTags.slice(0, 2) as tag (String(tag.id))}
									<span class="max-w-20 truncate rounded bg-bg px-1.5 py-0.5 text-[10px] text-text-muted">
										{tag.name}
									</span>
								{/each}
								{#if assetTags.length > 2}
									<span class="text-[10px] text-text-muted">+{assetTags.length - 2}</span>
								{/if}
							</div>
						{/if}
					</div>
				</button>
			</article>
		{/each}
	</div>
</div>
