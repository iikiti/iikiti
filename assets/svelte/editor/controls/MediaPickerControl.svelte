<script lang="ts">
	import Icon from '$components/Icon.svelte';
	import MediaPickerModal from '$components/MediaPickerModal.svelte';
	import {
		clampUnit,
		formatObjectPosition,
		isImageMimeType,
		normalizeMediaValue,
		resolvePreviewUrl,
	} from '$lib/mediaHelpers.js';
	import { apiBase, apiToken, authHeader } from '../state.js';

	interface Props {
		field: Record<string, unknown>;
		value: unknown;
		onChange: (value: unknown) => void;
	}

	let { field, value, onChange }: Props = $props();

	let pickerOpen = $state(false);
	let editingFocalPoint = $state(false);
	let previewBroken = $state(false);

	const controlId = $derived(String(field.controlId ?? field.key ?? 'media'));
	const labelId = $derived(String(field.labelId ?? `${String(field.key ?? 'media')}-label`));
	const accept = $derived(typeof field.accept === 'string' && field.accept.trim() ? field.accept.trim() : 'image/*');
	const allowExternalUrl = $derived(field.allowExternalUrl !== false);
	const valueFormat = $derived(field.valueFormat === 'url' ? 'url' : 'object');
	const media = $derived(normalizeMediaValue(value));
	const previewSrc = $derived(resolvePreviewUrl(media));
	const hasMedia = $derived(media.source !== 'empty');
	const isVisualImage = $derived(
		hasMedia && Boolean(previewSrc) && !previewBroken && (!media.mimeType || isImageMimeType(media.mimeType)),
	);
	const canEditFocalPoint = $derived(isVisualImage && valueFormat === 'object');
	const focalLabel = $derived(formatObjectPosition(media.focalPoint));
	const fetchHeaders = $derived(() => authHeader($apiToken));

	$effect(() => {
		void previewSrc;
		previewBroken = false;
	});

	$effect(() => {
		if (!canEditFocalPoint) {
			editingFocalPoint = false;
		}
	});

	function handleSelect(selected: Record<string, unknown>) {
		if (valueFormat === 'url') {
			onChange(typeof selected.url === 'string' ? selected.url : '');
			return;
		}
		onChange(selected);
	}

	function handleClear() {
		editingFocalPoint = false;
		onChange(
			field.emptyValue !== undefined
				? field.emptyValue
				: valueFormat === 'url'
					? ''
					: { source: 'empty', assetId: null, url: '' },
		);
	}

	function emitFocalPoint(x: number, y: number) {
		if (!canEditFocalPoint) return;
		const nextX = Number(clampUnit(x).toFixed(3));
		const nextY = Number(clampUnit(y).toFixed(3));
		const base = value && typeof value === 'object' && !Array.isArray(value)
			? { ...(value as Record<string, unknown>) }
			: {
				source: media.source,
				...(media.assetId !== null ? { assetId: media.assetId } : {}),
				...(media.publicToken ? { publicToken: media.publicToken } : {}),
				url: media.url,
				...(media.previewUrl ? { previewUrl: media.previewUrl } : {}),
				...(media.thumbnailUrl ? { thumbnailUrl: media.thumbnailUrl } : {}),
				...(media.mimeType ? { mimeType: media.mimeType } : {}),
				...(media.width !== null ? { width: media.width } : {}),
				...(media.height !== null ? { height: media.height } : {}),
				...(media.dominantColor ? { dominantColor: media.dominantColor } : {}),
				...(media.defaultAlt ? { defaultAlt: media.defaultAlt } : {}),
			};
		onChange({ ...base, focalPoint: { x: nextX, y: nextY } });
	}

	function handleFocalClick(event: MouseEvent) {
		if (!editingFocalPoint) return;
		const target = event.currentTarget as HTMLElement;
		const rect = target.getBoundingClientRect();
		if (rect.width <= 0 || rect.height <= 0) return;
		emitFocalPoint((event.clientX - rect.left) / rect.width, (event.clientY - rect.top) / rect.height);
	}

	function handleFocalKeydown(event: KeyboardEvent) {
		if (!editingFocalPoint) return;
		const step = event.shiftKey ? 0.1 : 0.05;
		let { x, y } = media.focalPoint;
		if (event.key === 'ArrowLeft') x -= step;
		else if (event.key === 'ArrowRight') x += step;
		else if (event.key === 'ArrowUp') y -= step;
		else if (event.key === 'ArrowDown') y += step;
		else return;
		event.preventDefault();
		emitFocalPoint(x, y);
	}
</script>

<div class="iikiti-media-control" role="group" aria-labelledby={labelId}>
	{#if hasMedia}
		<div class="iikiti-media-control__card">
			<div
				class="iikiti-media-control__preview"
				class:iikiti-media-control__preview--picking={editingFocalPoint}
				style={media.dominantColor ? `background-color: ${media.dominantColor}` : undefined}
				role={editingFocalPoint ? 'slider' : undefined}
				tabindex={editingFocalPoint ? 0 : undefined}
				aria-label={editingFocalPoint ? `Set focal point, currently ${focalLabel}` : undefined}
				aria-valuetext={editingFocalPoint ? focalLabel : undefined}
				onclick={handleFocalClick}
				onkeydown={handleFocalKeydown}
			>
				{#if isVisualImage}
					<img
						src={previewSrc}
						alt={media.defaultAlt || ''}
						style={`object-position: ${focalLabel}`}
						onerror={() => (previewBroken = true)}
					/>
					<span
						class="iikiti-media-control__dot"
						class:iikiti-media-control__dot--active={editingFocalPoint}
						style={`left: ${media.focalPoint.x * 100}%; top: ${media.focalPoint.y * 100}%`}
						aria-hidden="true"
					></span>
				{:else}
					<span class="iikiti-media-control__file-icon">
						<Icon name={previewBroken ? 'image-off' : 'file-text'} size={24} />
					</span>
				{/if}
			</div>

			<div class="iikiti-media-control__meta">
				<div class="iikiti-media-control__badges">
					<span class="iikiti-media-control__badge">
						{media.source === 'asset' ? (media.assetId ? `Asset #${media.assetId}` : 'DAM Asset') : 'External URL'}
					</span>
					{#if media.width && media.height}
						<span class="iikiti-media-control__meta-item">{media.width}×{media.height}</span>
					{/if}
					{#if media.mimeType}
						<span class="iikiti-media-control__meta-item">{media.mimeType}</span>
					{/if}
				</div>
				{#if media.url}
					<p class="iikiti-media-control__url" title={media.url}>{media.url}</p>
				{/if}
				{#if media.defaultAlt}
					<p class="iikiti-media-control__alt">
						Default alt: <span>{media.defaultAlt}</span>
					</p>
				{/if}
			</div>

			{#if editingFocalPoint}
				<div class="iikiti-media-control__focal-bar">
					<span class="iikiti-media-control__focal-readout">
						<Icon name="crosshair" size={14} />
						<span>Focal point: {focalLabel}</span>
					</span>
					<button
						type="button"
						class="iikiti-media-control__btn iikiti-media-control__btn--sm"
						onclick={() => emitFocalPoint(0.5, 0.5)}
					>
						Reset 50% 50%
					</button>
				</div>
			{/if}
		</div>
	{/if}

	<div class="iikiti-media-control__actions">
		<button
			type="button"
			id={controlId}
			class="iikiti-media-control__btn iikiti-media-control__btn--primary"
			onclick={() => (pickerOpen = true)}
		>
			<Icon name="image" size={15} />
			<span>{hasMedia ? 'Replace' : 'Choose Media'}</span>
		</button>

		{#if canEditFocalPoint}
			<button
				type="button"
				class="iikiti-media-control__btn"
				class:iikiti-media-control__btn--active={editingFocalPoint}
				aria-pressed={editingFocalPoint}
				title="Click preview image to adjust focal point"
				onclick={() => (editingFocalPoint = !editingFocalPoint)}
			>
				<Icon name="crosshair" size={15} />
				<span>Focal point</span>
			</button>
		{/if}

		{#if hasMedia}
			<button
				type="button"
				class="iikiti-media-control__btn iikiti-media-control__btn--ghost"
				onclick={handleClear}
			>
				<Icon name="x" size={15} />
				<span>Clear</span>
			</button>
		{/if}
	</div>
</div>

<MediaPickerModal
	open={pickerOpen}
	onClose={() => (pickerOpen = false)}
	onSelect={handleSelect}
	selectedAssetId={media.assetId}
	{accept}
	{allowExternalUrl}
	initialUrl={media.source === 'url' ? media.url : ''}
	apiEndpoint="/api/editor/assets"
	apiBase={$apiBase}
	{fetchHeaders}
	title={String(field.label ?? 'Choose media')}
/>

<style>
	.iikiti-media-control {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.iikiti-media-control__card {
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: var(--ik-radius, 8px);
		background: var(--ik-panel-bg, #f9f7f2);
		overflow: hidden;
	}

	.iikiti-media-control__preview {
		position: relative;
		width: 100%;
		aspect-ratio: 16 / 10;
		background: color-mix(in srgb, var(--ik-panel-text, #3a3830) 7%, transparent);
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
	}

	.iikiti-media-control__preview--picking {
		cursor: crosshair;
		outline: 2px solid var(--ik-accent, #a6613c);
		outline-offset: -2px;
	}

	.iikiti-media-control__preview img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
		pointer-events: none;
		user-select: none;
	}

	.iikiti-media-control__file-icon {
		color: var(--ik-panel-text-muted, #7a7669);
	}

	.iikiti-media-control__dot {
		position: absolute;
		width: 12px;
		height: 12px;
		border-radius: 9999px;
		border: 2px solid #ffffff;
		background: var(--ik-accent, #a6613c);
		box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.45);
		transform: translate(-50%, -50%);
		pointer-events: none;
		transition: transform 120ms ease;
	}

	.iikiti-media-control__dot--active {
		width: 16px;
		height: 16px;
		box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.55), 0 0 0 4px rgba(255, 255, 255, 0.65);
	}

	.iikiti-media-control__meta {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 8px;
	}

	.iikiti-media-control__badges {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
	}

	.iikiti-media-control__badge {
		padding: 1px 6px;
		border-radius: 4px;
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 14%, transparent);
		color: var(--ik-accent, #a6613c);
		font-size: 0.75rem;
		font-weight: 600;
	}

	.iikiti-media-control__meta-item {
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, #7a7669);
	}

	.iikiti-media-control__url {
		margin: 0;
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, #7a7669);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.iikiti-media-control__alt {
		margin: 0;
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, #7a7669);
	}

	.iikiti-media-control__alt span {
		color: var(--ik-panel-text, #3a3830);
		font-style: italic;
	}

	.iikiti-media-control__focal-bar {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 6px 8px;
		border-top: 1px solid var(--ik-panel-border, #ddd6cb);
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 8%, transparent);
		font-size: 0.75rem;
	}

	.iikiti-media-control__focal-readout {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-weight: 500;
	}

	.iikiti-media-control__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.iikiti-media-control__btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 5px;
		padding: 6px 10px;
		border: 1px solid var(--ik-panel-border, #ddd6cb);
		border-radius: 6px;
		background: var(--ik-panel-bg, #f9f7f2);
		color: var(--ik-panel-text, #3a3830);
		font: inherit;
		font-size: 0.8125rem;
		font-weight: 500;
		cursor: pointer;
	}

	.iikiti-media-control__btn:hover {
		border-color: var(--ik-accent, #a6613c);
	}

	.iikiti-media-control__btn--sm {
		padding: 3px 8px;
		font-size: 0.75rem;
	}

	.iikiti-media-control__btn--primary {
		border-color: transparent;
		background: var(--ik-accent, #a6613c);
		color: #ffffff;
	}

	.iikiti-media-control__btn--primary:hover {
		background: var(--ik-accent-hover, #945231);
	}

	.iikiti-media-control__btn--active {
		border-color: var(--ik-accent, #a6613c);
		background: color-mix(in srgb, var(--ik-accent, #a6613c) 14%, transparent);
		color: var(--ik-accent, #a6613c);
	}

	.iikiti-media-control__btn--ghost {
		border-color: transparent;
		background: transparent;
		color: var(--ik-panel-text-muted, #7a7669);
	}

	.iikiti-media-control__btn--ghost:hover {
		color: var(--ik-panel-text, #3a3830);
		border-color: var(--ik-panel-border, #ddd6cb);
	}
</style>
