<script lang="ts">
	import ModalDialog from '$components/ModalDialog.svelte';
	import Icon from '$components/Icon.svelte';
	import Badge from '$components/Badge.svelte';
	import { formatBytes } from '$lib/mediaHelpers.js';
	import {
		buildFolderTree,
		computeFileSha256,
		createUploadQueueManager,
		flattenFolderTree,
	} from '$lib/damHelpers.js';
	import type { ApiClient } from '$lib/api';
	import type {
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
	} from '$types';

	interface Props {
		api: ApiClient;
		open: boolean;
		initialFiles?: File[];
		folders: DigitalAssetFolder[];
		tags: DigitalAssetTag[];
		sites: DigitalAssetSiteOption[];
		defaultSiteId?: string | number | null;
		defaultFolderId?: string | null;
		onClose: () => void;
		onUploadsComplete: (completedCount: number) => void;
	}

	let {
		api,
		open,
		initialFiles = [],
		folders,
		tags,
		sites,
		defaultSiteId = null,
		defaultFolderId = null,
		onClose,
		onUploadsComplete,
	}: Props = $props();

	let fileInputEl = $state<HTMLInputElement | null>(null);
	let dropHover = $state(false);
	let targetFolderId = $state('');
	let targetVisibility = $state<'private' | 'public'>('private');
	let targetSiteIds = $state<string[]>([]);
	let targetTagIds = $state<string[]>([]);
	let completedInSession = $state(0);

	const flatFolders = $derived(flattenFolderTree(buildFolderTree(folders)));

	let snapshot = $state({
		items: [] as any[],
		counts: {
			total: 0,
			queued: 0,
			hashing: 0,
			duplicate_prompt: 0,
			uploading: 0,
			done: 0,
			error: 0,
			cancelled: 0,
		},
		aggregateProgress: 0,
		isActive: false,
	});

	const queue = createUploadQueueManager({
		concurrency: 3,
		hashFile: (file) => computeFileSha256(file),
		checkDuplicate: async (checksum, item) => {
			try {
				return await api.checkDigitalAssetDuplicate({
					checksum,
					siteIds: item.meta?.siteIds ?? [],
				});
			} catch {
				return null;
			}
		},
		uploadFile: async (item, { onProgress, signal }) => {
			const formData = new FormData();
			formData.set('file', item.file);
			formData.set('title', item.title || item.name);
			formData.set('visibility', item.meta?.visibility || 'private');
			formData.set('siteIds', JSON.stringify(item.meta?.siteIds ?? []));
			if (item.meta?.folderId) {
				formData.set('folderId', String(item.meta.folderId));
			}
			if (Array.isArray(item.meta?.tagIds) && item.meta.tagIds.length > 0) {
				formData.set('tagIds', JSON.stringify(item.meta.tagIds));
			}
			if (item.duplicatePolicy === 'allow') {
				formData.set('duplicatePolicy', 'allow');
			}
			const result = await api.uploadDigitalAssetWithProgress(formData, onProgress, signal);
			completedInSession += 1;
			return result;
		},
		onChange: (next) => {
			snapshot = next;
		},
	});

	// Initialize default target settings when modal opens
	let wasOpen = false;
	$effect(() => {
		if (open && !wasOpen) {
			wasOpen = true;
			completedInSession = 0;
			targetFolderId = defaultFolderId && defaultFolderId !== 'unfiled' ? String(defaultFolderId) : '';
			if (targetSiteIds.length === 0) {
				if (defaultSiteId != null) {
					targetSiteIds = [String(defaultSiteId)];
				} else if (sites.length > 0) {
					targetSiteIds = [String(sites[0].id)];
				}
			}
			if (initialFiles && initialFiles.length > 0) {
				enqueueFiles(initialFiles);
			}
		} else if (!open && wasOpen) {
			wasOpen = false;
		}
	});

	function currentMeta() {
		return {
			folderId: targetFolderId || null,
			visibility: targetVisibility,
			siteIds: [...targetSiteIds],
			tagIds: [...targetTagIds],
		};
	}

	function enqueueFiles(files: FileList | File[]) {
		const list = Array.from(files ?? []);
		if (list.length === 0) return;
		queue.enqueue(list, currentMeta());
	}

	function toggleSite(siteId: string) {
		if (targetSiteIds.includes(siteId)) {
			targetSiteIds = targetSiteIds.filter((id) => id !== siteId);
		} else {
			targetSiteIds = [...targetSiteIds, siteId];
		}
	}

	function toggleTag(tagId: string) {
		if (targetTagIds.includes(tagId)) {
			targetTagIds = targetTagIds.filter((id) => id !== tagId);
		} else {
			targetTagIds = [...targetTagIds, tagId];
		}
	}

	function handleClose() {
		if (completedInSession > 0 || snapshot.counts.done > 0) {
			onUploadsComplete(Math.max(completedInSession, snapshot.counts.done));
		}
		queue.clearCompleted();
		onClose();
	}

	function statusLabel(item: any): string {
		switch (item.status) {
			case 'queued':
				return 'Queued';
			case 'hashing':
				return 'Checking hash…';
			case 'duplicate_prompt':
				return 'Duplicate detected';
			case 'uploading':
				return `Uploading ${item.progress}%`;
			case 'done':
				return item.duplicatePolicy === 'reuse' ? 'Reused existing' : 'Uploaded';
			case 'error':
				return 'Failed';
			case 'cancelled':
				return item.duplicatePolicy === 'skip' ? 'Skipped' : 'Cancelled';
			default:
				return item.status;
		}
	}

	function statusBadge(status: string): 'success' | 'warning' | 'danger' | 'info' | 'primary' {
		if (status === 'done') return 'success';
		if (status === 'duplicate_prompt') return 'warning';
		if (status === 'error') return 'danger';
		if (status === 'uploading' || status === 'hashing') return 'info';
		return 'primary';
	}
</script>

{#if open}
	<ModalDialog title="Upload Digital Assets" open={true} onClose={handleClose} width={760}>
		<div class="space-y-4 p-2">
			<!-- Upload target defaults -->
			<div class="grid gap-3 rounded-lg border border-border bg-bg p-3 sm:grid-cols-2">
				<label class="block space-y-1 text-xs">
					<span class="font-medium text-text-muted">Target Folder</span>
					<select class="admin-input admin-input-sm text-xs" bind:value={targetFolderId}>
						<option value="">Unfiled (root)</option>
						{#each flatFolders as folder (String(folder.id))}
							<option value={String(folder.id)}>
								{folder.depth ? `${'— '.repeat(folder.depth)}${folder.name}` : folder.name}
							</option>
						{/each}
					</select>
				</label>

				<label class="block space-y-1 text-xs">
					<span class="font-medium text-text-muted">Visibility</span>
					<select class="admin-input admin-input-sm text-xs" bind:value={targetVisibility}>
						<option value="private">Private (authenticated users only)</option>
						<option value="public">Public to granted sites</option>
					</select>
				</label>

				<div class="space-y-1 text-xs sm:col-span-2">
					<span class="block font-medium text-text-muted">Site Grants</span>
					<div class="flex flex-wrap gap-1.5">
						{#each sites as site (String(site.id))}
							{@const siteId = String(site.id)}
							{@const checked = targetSiteIds.includes(siteId)}
							<label
								class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors {checked
									? 'border-accent bg-accent-subtle text-accent font-medium'
									: 'border-border bg-surface text-text'}"
							>
								<input type="checkbox" {checked} onchange={() => toggleSite(siteId)} />
								<span>{site.name || site.domain}</span>
							</label>
						{/each}
					</div>
				</div>

				{#if tags.length > 0}
					<div class="space-y-1 text-xs sm:col-span-2">
						<span class="block font-medium text-text-muted">Apply Tags (optional)</span>
						<div class="flex flex-wrap gap-1.5">
							{#each tags as tag (String(tag.id))}
								{@const tagId = String(tag.id)}
								{@const active = targetTagIds.includes(tagId)}
								<button
									type="button"
									class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors {active
										? 'border-accent bg-accent-subtle text-accent font-medium'
										: 'border-border bg-surface text-text-muted hover:text-text'}"
									onclick={() => toggleTag(tagId)}
								>
									{#if tag.color}
										<span class="h-2 w-2 rounded-full" style={`background-color: ${tag.color}`}></span>
									{/if}
									<span>{tag.name}</span>
								</button>
							{/each}
						</div>
					</div>
				{/if}
			</div>

			<!-- Dropzone area -->
			<div
				class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition-colors {dropHover
					? 'border-accent bg-accent-subtle/60'
					: 'border-border-strong bg-bg/60 hover:border-accent'}"
				role="button"
				tabindex="0"
				aria-label="Drop files here or click to choose files"
				ondragover={(e) => {
					e.preventDefault();
					dropHover = true;
				}}
				ondragleave={() => (dropHover = false)}
				ondrop={(e) => {
					e.preventDefault();
					dropHover = false;
					if (e.dataTransfer?.files?.length) {
						enqueueFiles(e.dataTransfer.files);
					}
				}}
				onclick={() => fileInputEl?.click()}
				onkeydown={(e) => {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						fileInputEl?.click();
					}
				}}
			>
				<input
					bind:this={fileInputEl}
					type="file"
					multiple
					class="sr-only"
					onchange={(e) => {
						const files = e.currentTarget.files;
						if (files && files.length > 0) {
							enqueueFiles(files);
							e.currentTarget.value = '';
						}
					}}
				/>
				<div class="rounded-full bg-accent-subtle p-3 text-accent">
					<Icon name="upload" size={22} />
				</div>
				<p class="text-sm font-medium text-text">Drag and drop files here, or click to browse</p>
				<p class="text-xs text-text-muted">
					Uploads up to 3 files concurrently with pre-flight SHA-256 duplicate detection.
				</p>
			</div>

			<!-- Aggregate Progress -->
			{#if snapshot.items.length > 0}
				<div class="space-y-2 rounded-lg border border-border bg-surface p-3">
					<div class="flex flex-wrap items-center justify-between gap-2 text-xs">
						<span class="font-medium text-text">
							Upload Queue ({snapshot.counts.done}/{snapshot.counts.total} complete)
						</span>
						<span class="text-text-muted">
							{snapshot.aggregateProgress}% overall
							{#if snapshot.counts.duplicate_prompt > 0}
								· <span class="font-medium text-warning">{snapshot.counts.duplicate_prompt} awaiting decision</span>
							{/if}
							{#if snapshot.counts.error > 0}
								· <span class="font-medium text-danger">{snapshot.counts.error} failed</span>
							{/if}
						</span>
					</div>
					<div class="h-2 w-full overflow-hidden rounded-full bg-bg" role="progressbar" aria-valuenow={snapshot.aggregateProgress} aria-valuemin={0} aria-valuemax={100}>
						<div
							class="h-full rounded-full bg-accent transition-all duration-200"
							style={`width: ${snapshot.aggregateProgress}%`}
						></div>
					</div>
				</div>

				<!-- Queue list -->
				<ul class="max-h-72 space-y-2 overflow-y-auto pr-1">
					{#each snapshot.items as item (item.id)}
						<li class="space-y-2 rounded-lg border border-border bg-surface p-3 text-sm">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<div class="min-w-0 flex-1">
									<div class="truncate font-medium text-text" title={item.name}>{item.name}</div>
									<div class="text-xs text-text-muted">{formatBytes(item.size)} · {item.mimeType || 'Unknown type'}</div>
								</div>

								<div class="flex items-center gap-1.5">
									<Badge status={statusBadge(item.status)}>{statusLabel(item)}</Badge>

									{#if item.status === 'queued' || item.status === 'hashing' || item.status === 'uploading'}
										<button
											type="button"
											class="admin-btn admin-btn-ghost admin-btn-sm px-2 py-1 text-xs"
											onclick={() => queue.cancel(item.id)}
										>
											Cancel
										</button>
									{:else if item.status === 'error' || item.status === 'cancelled'}
										<button
											type="button"
											class="admin-btn admin-btn-secondary admin-btn-sm px-2 py-1 text-xs"
											onclick={() => queue.retry(item.id)}
										>
											Retry
										</button>
									{/if}

									{#if item.status !== 'uploading' && item.status !== 'hashing'}
										<button
											type="button"
											class="rounded p-1 text-text-muted hover:text-danger"
											aria-label={`Remove ${item.name}`}
											onclick={() => queue.remove(item.id)}
										>
											<Icon name="x" size={14} />
										</button>
									{/if}
								</div>
							</div>

							{#if item.status === 'uploading' || item.status === 'hashing'}
								<div class="h-1.5 w-full overflow-hidden rounded-full bg-bg">
									<div
										class="h-full rounded-full bg-accent transition-all duration-150 {item.status === 'hashing' ? 'animate-pulse w-1/3' : ''}"
										style={item.status === 'uploading' ? `width: ${item.progress}%` : undefined}
									></div>
								</div>
							{/if}

							{#if item.status === 'duplicate_prompt'}
								{@const match = item.duplicateMatches?.[0]}
								<div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning/40 bg-warning-subtle px-3 py-2 text-xs text-text">
									<div class="flex items-center gap-2">
										<Icon name="alert-triangle" size={15} class="shrink-0 text-warning" />
										<span>
											<strong>Duplicate detected:</strong>
											{match?.title || match?.originalName || `Asset #${match?.id ?? ''}`}
											{#if item.duplicateMatches.length > 1}
												(+{item.duplicateMatches.length - 1} more)
											{/if}
										</span>
									</div>
									<div class="flex flex-wrap items-center gap-1.5">
										<button
											type="button"
											class="admin-btn admin-btn-primary admin-btn-sm"
											onclick={() => queue.resolveDuplicate(item.id, 'reuse')}
										>
											Reuse Existing
										</button>
										<button
											type="button"
											class="admin-btn admin-btn-secondary admin-btn-sm"
											onclick={() => queue.resolveDuplicate(item.id, 'upload_anyway')}
										>
											Upload Anyway
										</button>
										<button
											type="button"
											class="admin-btn admin-btn-ghost admin-btn-sm"
											onclick={() => queue.resolveDuplicate(item.id, 'skip')}
										>
											Skip
										</button>
									</div>
								</div>
							{/if}

							{#if item.status === 'error' && item.error}
								<p class="text-xs text-danger" role="alert">{item.error}</p>
							{/if}
						</li>
					{/each}
				</ul>
			{/if}

			<!-- Footer -->
			<div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
				<button
					type="button"
					class="admin-btn admin-btn-ghost admin-btn-sm"
					disabled={snapshot.counts.done === 0 && snapshot.counts.cancelled === 0}
					onclick={() => queue.clearCompleted()}
				>
					Clear Completed
				</button>

				<button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick={handleClose}>
					{snapshot.isActive ? 'Minimize / Close' : 'Done'}
				</button>
			</div>
		</div>
	</ModalDialog>
{/if}
