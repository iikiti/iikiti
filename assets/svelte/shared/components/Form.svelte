<script lang="ts">
	import FormField from './FormField.svelte';
	import Icon from './Icon.svelte';
	import MediaPickerModal from './MediaPickerModal.svelte';
	import {
		formatObjectPosition,
		isImageMimeType,
		normalizeMediaValue,
		resolvePreviewUrl,
	} from '$lib/mediaHelpers.js';

	interface FieldConfig {
		key: string;
		label: string;
		type: 'text' | 'textarea' | 'json' | 'select' | 'checkbox' | 'toggle' | 'number' | 'media';
		required?: boolean;
		placeholder?: string;
		options?: Array<{ value: string; label: string }>;
		accept?: string;
		allowExternalUrl?: boolean;
		valueFormat?: 'object' | 'url';
	}

	interface ApiLike {
		baseUrl?: string;
		getHeaders?: (body?: unknown) => Record<string, string>;
	}

	interface Props {
		fields: FieldConfig[];
		values?: Record<string, any>;
		errors?: Record<string, string>;
		api?: ApiLike | null;
		apiBase?: string;
		fetchHeaders?: Record<string, string> | (() => Record<string, string>) | null;
		onchange?: (values: Record<string, any>) => void;
		onsubmit?: (data: Record<string, any>) => void | boolean;
		children?: any;
	}

	let {
		fields,
		values = {},
		errors = {},
		api = null,
		apiBase = '/api',
		fetchHeaders = null,
		onchange,
		onsubmit,
		children,
	}: Props = $props();

	let formData = $state<Record<string, any>>({});
	let activeMediaField = $state<FieldConfig | null>(null);

	const resolvedApiBase = $derived(api?.baseUrl ?? apiBase);
	const resolvedFetchHeaders = $derived.by(() => {
		if (fetchHeaders) return fetchHeaders;
		if (api && typeof api.getHeaders === 'function') {
			return () => api.getHeaders!(null);
		}
		return null;
	});
	const activeMediaValue = $derived(activeMediaField ? normalizeMediaValue(formData[activeMediaField.key]) : null);

	$effect(() => {
		formData = { ...values };
	});

	$effect(() => {
		onchange?.(formData);
	});

	function handleMediaSelect(selected: Record<string, unknown>) {
		if (!activeMediaField) return;
		formData[activeMediaField.key] = activeMediaField.valueFormat === 'url'
			? (typeof selected.url === 'string' ? selected.url : '')
			: selected;
		activeMediaField = null;
	}
</script>

<form onsubmit={(e) => { e.preventDefault(); onsubmit?.(formData); }}>
	<div class="space-y-4">
		{#each fields as field (field.key)}
			<FormField {field} error={errors[field.key]}>
				{#if field.type === 'textarea'}
					<textarea
						id={field.key}
						class="admin-input w-full resize-y"
						placeholder={field.placeholder}
						bind:value={formData[field.key]}
					></textarea>
				{:else if field.type === 'json'}
					<textarea
						id={field.key}
						class="admin-input w-full font-mono text-sm resize-y"
						placeholder={field.placeholder ?? '[]'}
						bind:value={formData[field.key]}
					></textarea>
					<p class="mt-1 text-xs text-text-muted">Enter valid JSON.</p>
				{:else if field.type === 'select'}
					<select
						id={field.key}
						class="admin-input w-full"
						bind:value={formData[field.key]}
					>
						{#each field.options ?? [] as option}
							<option value={option.value}>{option.label}</option>
						{/each}
					</select>
				{:else if field.type === 'checkbox'}
						<input
							type="checkbox"
							class="h-4 w-4 text-accent rounded"
							checked={formData[field.key]}
							onchange={(e) => (formData[field.key] = e.currentTarget.checked)}
						/>
				{:else if field.type === 'toggle'}
					<button
						type="button"
						class="admin-btn"
						class:admin-btn-primary={formData[field.key]}
						class:admin-btn-secondary={!formData[field.key]}
						onclick={() => (formData[field.key] = !formData[field.key])}
					>
						{formData[field.key] ? 'Yes' : 'No'}
					</button>
				{:else if field.type === 'media'}
					{@const media = normalizeMediaValue(formData[field.key])}
					{@const previewSrc = resolvePreviewUrl(media)}
					<div class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface p-2.5" role="group" aria-labelledby={`${field.key}-label`}>
						{#if media.source !== 'empty'}
							<div
								class="flex h-14 w-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-surface-hover"
								style={media.dominantColor ? `background-color: ${media.dominantColor}` : undefined}
							>
								{#if previewSrc && (!media.mimeType || isImageMimeType(media.mimeType))}
									<img
										src={previewSrc}
										alt={media.defaultAlt || field.label}
										class="h-full w-full object-cover"
										style={`object-position: ${formatObjectPosition(media.focalPoint)}`}
									/>
								{:else}
									<Icon name="file-text" size={20} class="text-text-muted" />
								{/if}
							</div>
							<div class="min-w-0 flex-1">
								<p class="truncate text-sm font-medium text-text" title={media.url}>
									{media.source === 'asset' ? (media.assetId ? `Asset #${media.assetId}` : 'DAM Asset') : media.url}
								</p>
								<p class="truncate text-xs text-text-muted">
									{#if media.width && media.height}{media.width}×{media.height}{/if}
									{#if media.mimeType}{#if media.width && media.height} · {/if}{media.mimeType}{/if}
									{#if !media.width && !media.mimeType}{media.url}{/if}
								</p>
							</div>
						{:else}
							<p class="flex-1 text-sm text-text-muted">{field.placeholder ?? 'No media selected.'}</p>
						{/if}
						<div class="flex items-center gap-2">
							<button
								type="button"
								id={field.key}
								class="admin-btn admin-btn-sm admin-btn-secondary"
								onclick={() => (activeMediaField = field)}
							>
								{media.source !== 'empty' ? 'Replace' : 'Choose media'}
							</button>
							{#if media.source !== 'empty'}
								<button
									type="button"
									class="admin-btn admin-btn-sm admin-btn-ghost"
									onclick={() => (formData[field.key] = field.valueFormat === 'url' ? '' : null)}
								>
									Clear
								</button>
							{/if}
						</div>
					</div>
				{:else}
					<input
						id={field.key}
						type={field.type}
						class="admin-input w-full"
						placeholder={field.placeholder}
						bind:value={formData[field.key]}
					/>
				{/if}
			</FormField>
		{/each}
	</div>
	<div class="mt-4 flex justify-end gap-2">
		{@render children?.()}
	</div>
</form>

<MediaPickerModal
	open={activeMediaField !== null}
	onClose={() => (activeMediaField = null)}
	onSelect={handleMediaSelect}
	selectedAssetId={activeMediaValue?.assetId ?? null}
	accept={activeMediaField?.accept ?? '*/*'}
	allowExternalUrl={activeMediaField?.allowExternalUrl ?? true}
	initialUrl={activeMediaValue?.source === 'url' ? activeMediaValue.url : ''}
	apiEndpoint="/api/admin/assets"
	apiBase={resolvedApiBase}
	fetchHeaders={resolvedFetchHeaders}
	title={activeMediaField?.label ?? 'Choose media'}
/>
