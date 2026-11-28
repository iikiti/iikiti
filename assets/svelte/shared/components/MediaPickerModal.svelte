<script lang="ts">
	import ModalDialog from './ModalDialog.svelte';
	import Icon from './Icon.svelte';
	import {
		buildAssetMediaValue,
		buildUrlMediaValue,
		formatBytes,
		formatObjectPosition,
		isImageMimeType,
		isValidHttpsUrl,
		mimeMatchesAccept,
		parseAssetListResponse,
		resolveAssetEndpoint,
		resolvePreviewUrl,
	} from '$lib/mediaHelpers.js';
	import { buildFolderTree, flattenFolderTree } from '$lib/damHelpers.js';

	type TabId = 'library' | 'upload' | 'url';
	type MimeCategory = 'all' | 'image' | 'video' | 'audio' | 'document';

	const CATEGORY_ACCEPT_MAP: Record<MimeCategory, string> = {
		all: '*/*',
		image: 'image/*',
		video: 'video/*',
		audio: 'audio/*',
		document: 'application/pdf,application/msword,text/*',
	};

	interface Props {
		open?: boolean;
		onClose?: () => void;
		onSelect?: (media: ReturnType<typeof buildAssetMediaValue> | ReturnType<typeof buildUrlMediaValue>) => void;
		selectedAssetId?: number | string | null;
		accept?: string;
		allowExternalUrl?: boolean;
		initialUrl?: string;
		apiEndpoint?: string;
		fetchHeaders?: Record<string, string> | (() => Record<string, string>) | null;
		apiBase?: string;
		title?: string;
	}

	let {
		open = false,
		onClose,
		onSelect,
		selectedAssetId = null,
		accept = '*/*',
		allowExternalUrl = true,
		initialUrl = '',
		apiEndpoint = '/api/editor/assets',
		fetchHeaders = null,
		apiBase = '',
		title = 'Choose media',
	}: Props = $props();

	let activeTab = $state<TabId>('library');

	// Library state
	let assets = $state<Record<string, any>[]>([]);
	let folders = $state<Array<{ id: string | number; name: string; parentId?: string | number | null; depth?: number }>>([]);
	let tags = $state<Array<{ id?: string | number; name: string; slug?: string }>>([]);
	let orgLoaded = $state(false);
	let totalItems = $state(0);
	let page = $state(1);
	const itemsPerPage = 24;
	let searchQuery = $state('');
	let mimeCategory = $state<MimeCategory>('all');
	let selectedFolder = $state('');
	let selectedTag = $state('');
	let loading = $state(false);
	let loadError = $state('');
	let brokenPreviewIds = $state<Record<string, boolean>>({});
	let loadSeq = 0;
	let searchTimer: ReturnType<typeof setTimeout> | undefined;

	// Upload state
	let fileInputEl = $state<HTMLInputElement | null>(null);
	let uploadFile = $state<File | null>(null);
	let uploadPreviewUrl = $state('');
	let uploadTitle = $state('');
	let uploadVisibility = $state<'public' | 'private'>('public');
	let dragActive = $state(false);
	let uploading = $state(false);
	let uploadProgress = $state(0);
	let uploadError = $state('');

	// External URL state
	let urlInput = $state('');
	let urlError = $state('');
	let urlPreviewBroken = $state(false);

	const showMimeFilter = $derived(accept.trim() === '' || accept.trim() === '*/*' || accept.trim() === '*');
	const effectiveAccept = $derived(showMimeFilter ? CATEGORY_ACCEPT_MAP[mimeCategory] : accept);
	const visibleAssets = $derived.by(() =>
		assets.filter((asset) => {
			if (!effectiveAccept || effectiveAccept === '*/*') return true;
			const mime = typeof asset.mimeType === 'string' ? asset.mimeType : '';
			if (mime === '') return true;
			if (showMimeFilter && mimeCategory === 'document' && mime.toLowerCase().startsWith('application/vnd.')) {
				return true;
			}
			return mimeMatchesAccept(mime, effectiveAccept);
		}),
	);
	const totalPages = $derived(Math.max(1, Math.ceil(totalItems / itemsPerPage)));
	const urlTrimmed = $derived(urlInput.trim());
	const urlIsValid = $derived(isValidHttpsUrl(urlTrimmed));
	const canPreviewExternalAsImage = $derived(
		urlIsValid && (showMimeFilter || accept.toLowerCase().includes('image')),
	);
	const uploadMimeAccepted = $derived(
		!uploadFile || mimeMatchesAccept(uploadFile.type, accept),
	);

	function resolveHeaders(forFormData = false): Record<string, string> {
		const raw = typeof fetchHeaders === 'function' ? fetchHeaders() : fetchHeaders;
		const headers: Record<string, string> = raw ? { ...raw } : {};
		if (forFormData) {
			delete headers['Content-Type'];
			delete headers['content-type'];
		}
		return headers;
	}

	function shortFormat(mimeType: unknown): string {
		if (typeof mimeType !== 'string' || !mimeType.includes('/')) return '';
		const sub = mimeType.split('/')[1]?.split(';')[0]?.split('+')[0] ?? '';
		return sub.toUpperCase().slice(0, 6);
	}

	function assetThumbnail(asset: Record<string, any>): string {
		const normalized = buildAssetMediaValue(asset);
		return resolvePreviewUrl(normalized);
	}

	function assetIsVisual(asset: Record<string, any>): boolean {
		const idKey = String(asset.id ?? asset.publicToken ?? '');
		if (brokenPreviewIds[idKey]) return false;
		const thumb = assetThumbnail(asset);
		if (!thumb) return false;
		const mime = typeof asset.mimeType === 'string' ? asset.mimeType : '';
		return !mime || isImageMimeType(mime) || Boolean(asset.thumbnailUrl || asset.previewUrl);
	}

	function iconForAsset(asset: Record<string, any>): string {
		const mime = String(asset.mimeType ?? '').toLowerCase();
		if (mime.startsWith('image/')) return 'image-off';
		if (mime.startsWith('video/')) return 'film';
		if (mime.startsWith('audio/')) return 'music';
		return 'file-text';
	}

	async function loadOrganization(): Promise<void> {
		try {
			const orgUrl = resolveAssetEndpoint(apiBase, apiEndpoint, '/organization');
			const response = await fetch(orgUrl, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					...resolveHeaders(false),
				},
			});
			if (!response.ok) return;
			const data = await response.json().catch(() => null);
			if (!data || typeof data !== 'object') return;
			if (Array.isArray(data.folders)) {
				folders = flattenFolderTree(buildFolderTree(data.folders));
				orgLoaded = true;
			}
			if (Array.isArray(data.tags)) {
				tags = data.tags
					.filter((t: any) => t && typeof t === 'object' && (t.name || t.slug))
					.map((t: any) => ({
						id: t.id,
						name: String(t.name ?? t.slug),
						slug: String(t.slug ?? t.name),
					}));
				orgLoaded = true;
			}
		} catch {
			// Organization endpoint is optional; fall back to list facets when unavailable.
		}
	}

	async function loadAssets(targetPage = page): Promise<void> {
		const seq = ++loadSeq;
		loading = true;
		loadError = '';
		try {
			const params = new URLSearchParams();
			params.set('page', String(targetPage));
			params.set('itemsPerPage', String(itemsPerPage));
			if (searchQuery.trim()) {
				params.set('q', searchQuery.trim());
			}
			if (showMimeFilter) {
				if (mimeCategory !== 'all') {
					params.set('mimeCategory', mimeCategory);
				}
			} else {
				const tokens = accept
					.split(',')
					.map((t) => t.trim().toLowerCase())
					.filter(Boolean);
				if (tokens.length === 1 && tokens[0].includes('/')) {
					params.set('mimeType', tokens[0]);
				} else if (tokens.length > 1) {
					const prefixes = new Set(tokens.map((t) => t.split('/')[0]));
					if (prefixes.size === 1) {
						const shared = [...prefixes][0];
						if (['image', 'video', 'audio', 'text'].includes(shared)) {
							params.set('mimeCategory', shared);
						}
					}
				}
			}
			if (selectedFolder) {
				params.set('folderId', selectedFolder);
			}
			if (selectedTag) {
				const matchedTag = tags.find(
					(t) => (t.id !== undefined && String(t.id) === selectedTag) || (t.slug ?? t.name) === selectedTag,
				);
				if (matchedTag?.id !== undefined && matchedTag.id !== null) {
					params.set('tagIds', String(matchedTag.id));
				} else {
					params.set('tag', selectedTag);
				}
			}

			const url = `${resolveAssetEndpoint(apiBase, apiEndpoint)}?${params.toString()}`;
			const response = await fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					...resolveHeaders(false),
				},
			});

			const data = await response.json().catch(() => ({}));
			if (seq !== loadSeq) return;
			if (!response.ok) {
				throw new Error(data.error ?? data.title ?? `Request failed (${response.status})`);
			}

			const parsed = parseAssetListResponse(data);
			assets = parsed.assets;
			totalItems = parsed.totalItems;
			page = parsed.page;
			if (!orgLoaded) {
				if (parsed.folders.length > 0) folders = parsed.folders;
				if (parsed.tags.length > 0) tags = parsed.tags;
			}
			brokenPreviewIds = {};
		} catch (cause) {
			if (seq === loadSeq) {
				loadError = cause instanceof Error ? cause.message : 'Unable to load media library.';
			}
		} finally {
			if (seq === loadSeq) {
				loading = false;
			}
		}
	}

	function scheduleSearch() {
		clearTimeout(searchTimer);
		searchTimer = setTimeout(() => {
			page = 1;
			void loadAssets(1);
		}, 250);
	}

	function handleSelectAsset(asset: Record<string, any>) {
		onSelect?.(buildAssetMediaValue(asset));
		onClose?.();
	}

	function setSelectedUploadFile(file: File | null) {
		uploadFile = file;
		uploadError = '';
		uploadProgress = 0;
		if (file && !uploadTitle.trim()) {
			uploadTitle = file.name.replace(/\.[^.]+$/, '');
		}
	}

	function handleDrop(event: DragEvent) {
		event.preventDefault();
		dragActive = false;
		const file = event.dataTransfer?.files?.[0] ?? null;
		if (file) setSelectedUploadFile(file);
	}

	function handleFileInputChange(event: Event) {
		const input = event.currentTarget as HTMLInputElement;
		const file = input.files?.[0] ?? null;
		if (file) setSelectedUploadFile(file);
	}

	function uploadViaXhr(endpoint: string, formData: FormData, headers: Record<string, string>): Promise<Record<string, any>> {
		return new Promise((resolve, reject) => {
			const xhr = new XMLHttpRequest();
			xhr.open('POST', endpoint, true);
			xhr.withCredentials = true;
			for (const [key, val] of Object.entries(headers)) {
				xhr.setRequestHeader(key, val);
			}
			xhr.upload.onprogress = (event) => {
				if (event.lengthComputable && event.total > 0) {
					uploadProgress = Math.min(100, Math.round((event.loaded / event.total) * 100));
				}
			};
			xhr.onload = () => {
				let parsed: Record<string, any> = {};
				try {
					parsed = JSON.parse(xhr.responseText || '{}');
				} catch {
					parsed = {};
				}
				if (xhr.status >= 200 && xhr.status < 300) {
					uploadProgress = 100;
					resolve(parsed);
				} else {
					reject(new Error(parsed.error ?? parsed.title ?? `Upload failed (${xhr.status})`));
				}
			};
			xhr.onerror = () => reject(new Error('Network error while uploading file.'));
			xhr.onabort = () => reject(new Error('Upload was cancelled.'));
			xhr.send(formData);
		});
	}

	async function handleUploadSubmit(event: SubmitEvent) {
		event.preventDefault();
		if (!uploadFile || uploading) return;
		if (!uploadMimeAccepted) {
			uploadError = `Selected file type (${uploadFile.type || 'unknown'}) is not accepted here (${accept}).`;
			return;
		}

		uploading = true;
		uploadError = '';
		uploadProgress = 0;

		try {
			const formData = new FormData();
			formData.set('file', uploadFile);
			formData.set('title', uploadTitle.trim() || uploadFile.name.replace(/\.[^.]+$/, ''));
			formData.set('visibility', uploadVisibility);

			const endpoint = resolveAssetEndpoint(apiBase, apiEndpoint, '/upload');
			const headers = {
				Accept: 'application/json',
				...resolveHeaders(true),
			};

			let payload: Record<string, any>;
			if (typeof XMLHttpRequest !== 'undefined') {
				payload = await uploadViaXhr(endpoint, formData, headers);
			} else {
				const response = await fetch(endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: formData,
				});
				payload = await response.json().catch(() => ({}));
				if (!response.ok) {
					throw new Error(payload.error ?? payload.title ?? `Upload failed (${response.status})`);
				}
				uploadProgress = 100;
			}

			const createdAsset = (payload.asset && typeof payload.asset === 'object' ? payload.asset : payload) as Record<string, any>;
			onSelect?.(buildAssetMediaValue(createdAsset));
			onClose?.();
		} catch (cause) {
			uploadError = cause instanceof Error ? cause.message : 'Unable to upload file.';
		} finally {
			uploading = false;
		}
	}

	function handleExternalUrlSubmit(event: SubmitEvent) {
		event.preventDefault();
		if (!urlTrimmed) {
			urlError = 'Enter an HTTPS URL.';
			return;
		}
		if (!isValidHttpsUrl(urlTrimmed)) {
			urlError = 'Only valid https:// URLs are allowed.';
			return;
		}
		urlError = '';
		onSelect?.(buildUrlMediaValue(urlTrimmed));
		onClose?.();
	}

	// Reset and load when modal opens.
	$effect(() => {
		if (!open) return;
		activeTab = 'library';
		page = 1;
		searchQuery = '';
		mimeCategory = 'all';
		selectedFolder = '';
		selectedTag = '';
		uploadFile = null;
		uploadTitle = '';
		uploadVisibility = 'public';
		uploadError = '';
		uploadProgress = 0;
		urlInput = initialUrl || '';
		urlError = '';
		urlPreviewBroken = false;
		orgLoaded = false;
		void loadOrganization();
		void loadAssets(1);
	});

	// Maintain local preview object URL for the staged upload file.
	$effect(() => {
		if (!uploadFile || !isImageMimeType(uploadFile.type)) {
			uploadPreviewUrl = '';
			return;
		}
		const objectUrl = URL.createObjectURL(uploadFile);
		uploadPreviewUrl = objectUrl;
		return () => URL.revokeObjectURL(objectUrl);
	});

	$effect(() => {
		void urlTrimmed;
		urlPreviewBroken = false;
	});
</script>

{#if open}
	<ModalDialog {title} open={true} {onClose} width={860}>
		<div class="mp-root">
			<nav class="mp-tabs" role="tablist" aria-label="Media source">
				<button
					type="button"
					role="tab"
					class="mp-tab"
					class:mp-tab--active={activeTab === 'library'}
					aria-selected={activeTab === 'library'}
					onclick={() => (activeTab = 'library')}
				>
					<Icon name="layout-grid" size={15} />
					<span>Library</span>
				</button>
				<button
					type="button"
					role="tab"
					class="mp-tab"
					class:mp-tab--active={activeTab === 'upload'}
					aria-selected={activeTab === 'upload'}
					onclick={() => (activeTab = 'upload')}
				>
					<Icon name="upload" size={15} />
					<span>Upload</span>
				</button>
				{#if allowExternalUrl}
					<button
						type="button"
						role="tab"
						class="mp-tab"
						class:mp-tab--active={activeTab === 'url'}
						aria-selected={activeTab === 'url'}
						onclick={() => (activeTab = 'url')}
					>
						<Icon name="link-2" size={15} />
						<span>External URL</span>
					</button>
				{/if}
			</nav>

			{#if activeTab === 'library'}
				<div class="mp-panel" role="tabpanel">
					<div class="mp-toolbar">
						<div class="mp-search">
							<Icon name="search" size={15} class="mp-search__icon" />
							<input
								type="search"
								class="mp-input mp-search__input"
								placeholder="Search media library…"
								aria-label="Search media library"
								bind:value={searchQuery}
								oninput={scheduleSearch}
								onkeydown={(e) => {
									if (e.key === 'Enter') {
										e.preventDefault();
										clearTimeout(searchTimer);
										page = 1;
										void loadAssets(1);
									}
								}}
							/>
						</div>

						{#if showMimeFilter}
							<select
								class="mp-select"
								aria-label="Filter by media type"
								value={mimeCategory}
								onchange={(e) => {
									mimeCategory = e.currentTarget.value as MimeCategory;
									page = 1;
									void loadAssets(1);
								}}
							>
								<option value="all">All types</option>
								<option value="image">Images</option>
								<option value="video">Videos</option>
								<option value="audio">Audio</option>
								<option value="document">Documents</option>
							</select>
						{/if}

						{#if folders.length > 0}
							<select
								class="mp-select"
								aria-label="Filter by folder"
								value={selectedFolder}
								onchange={(e) => {
									selectedFolder = e.currentTarget.value;
									page = 1;
									void loadAssets(1);
								}}
							>
								<option value="">All folders</option>
								{#each folders as folder (String(folder.id))}
									<option value={String(folder.id)}>{folder.depth ? `${'— '.repeat(folder.depth)}${folder.name}` : folder.name}</option>
								{/each}
							</select>
						{/if}

						{#if tags.length > 0}
							<select
								class="mp-select"
								aria-label="Filter by tag"
								value={selectedTag}
								onchange={(e) => {
									selectedTag = e.currentTarget.value;
									page = 1;
									void loadAssets(1);
								}}
							>
								<option value="">All tags</option>
								{#each tags as tag (String(tag.id ?? tag.slug ?? tag.name))}
									<option value={tag.id !== undefined && tag.id !== null ? String(tag.id) : (tag.slug ?? tag.name)}>{tag.name}</option>
								{/each}
							</select>
						{/if}

						<button
							type="button"
							class="mp-btn mp-btn--ghost"
							title="Refresh library"
							aria-label="Refresh library"
							onclick={() => void loadAssets(page)}
							disabled={loading}
						>
							<Icon name="refresh-cw" size={15} />
						</button>
					</div>

					{#if loadError}
						<div class="mp-alert mp-alert--error" role="alert">
							<Icon name="alert-triangle" size={16} />
							<span>{loadError}</span>
							<button type="button" class="mp-btn mp-btn--sm" onclick={() => void loadAssets(page)}>Retry</button>
						</div>
					{:else if loading && visibleAssets.length === 0}
						<div class="mp-empty" role="status">
							<span>Loading media library…</span>
						</div>
					{:else if visibleAssets.length === 0}
						<div class="mp-empty">
							<Icon name="image" size={28} />
							<p>No matching assets found.</p>
							<button type="button" class="mp-btn mp-btn--primary" onclick={() => (activeTab = 'upload')}>
								<Icon name="upload" size={14} />
								<span>Upload a file</span>
							</button>
						</div>
					{:else}
						<div class="mp-grid" class:mp-grid--loading={loading}>
							{#each visibleAssets as asset (String(asset.id ?? asset.publicToken ?? asset.fileUrl))}
								{@const isSelected = selectedAssetId !== null && selectedAssetId !== undefined && String(asset.id) === String(selectedAssetId)}
								{@const idKey = String(asset.id ?? asset.publicToken ?? '')}
								{@const fmt = shortFormat(asset.mimeType)}
								{@const thumb = assetThumbnail(asset)}
								<button
									type="button"
									class="mp-card"
									class:mp-card--selected={isSelected}
									aria-pressed={isSelected}
									onclick={() => handleSelectAsset(asset)}
								>
									<div
										class="mp-card__thumb"
										style={asset.dominantColor ? `background-color: ${asset.dominantColor}` : undefined}
									>
										{#if assetIsVisual(asset)}
											<img
												src={thumb}
												alt={asset.defaultAlt || asset.title || ''}
												loading="lazy"
												style={`object-position: ${formatObjectPosition(asset.focalPoint)}`}
												onerror={() => {
													brokenPreviewIds = { ...brokenPreviewIds, [idKey]: true };
												}}
											/>
										{:else}
											<span class="mp-card__icon">
												<Icon name={iconForAsset(asset)} size={28} />
											</span>
										{/if}

										{#if fmt}
											<span class="mp-card__badge">{fmt}</span>
										{/if}

										{#if isSelected}
											<span class="mp-card__check" aria-label="Selected">
												<Icon name="check" size={14} />
											</span>
										{/if}
									</div>
									<div class="mp-card__meta">
										<span class="mp-card__title" title={asset.title || asset.originalName || `Asset #${asset.id}`}>
											{asset.title || asset.originalName || `Asset #${asset.id}`}
										</span>
										<span class="mp-card__sub">
											{#if asset.width && asset.height}
												{asset.width}×{asset.height}
												{#if asset.size} · {/if}
											{/if}
											{#if asset.size}
												{formatBytes(asset.size)}
											{/if}
										</span>
									</div>
								</button>
							{/each}
						</div>

						{#if totalPages > 1}
							<div class="mp-pagination">
								<button
									type="button"
									class="mp-btn mp-btn--sm"
									disabled={page <= 1 || loading}
									onclick={() => void loadAssets(page - 1)}
								>
									<Icon name="chevron-left" size={14} />
									<span>Previous</span>
								</button>
								<span class="mp-pagination__info">Page {page} of {totalPages} ({totalItems} items)</span>
								<button
									type="button"
									class="mp-btn mp-btn--sm"
									disabled={page >= totalPages || loading}
									onclick={() => void loadAssets(page + 1)}
								>
									<span>Next</span>
									<Icon name="chevron-right" size={14} />
								</button>
							</div>
						{/if}
					{/if}
				</div>
			{:else if activeTab === 'upload'}
				<form class="mp-panel mp-form" onsubmit={handleUploadSubmit}>
					<div
						class="mp-dropzone"
						class:mp-dropzone--active={dragActive}
						role="button"
						tabindex="0"
						aria-label="Drop a file here or click to choose a file"
						ondragover={(e) => {
							e.preventDefault();
							dragActive = true;
						}}
						ondragleave={() => (dragActive = false)}
						ondrop={handleDrop}
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
							class="mp-sr-only"
							accept={accept === '*/*' ? undefined : accept}
							onchange={handleFileInputChange}
						/>
						{#if uploadFile}
							<div class="mp-dropzone__selected">
								{#if uploadPreviewUrl}
									<img src={uploadPreviewUrl} alt={uploadFile.name} class="mp-dropzone__preview" />
								{:else}
									<Icon name="file-text" size={28} />
								{/if}
								<div class="mp-dropzone__file-info">
									<strong>{uploadFile.name}</strong>
									<span>{uploadFile.type || 'Unknown type'} · {formatBytes(uploadFile.size)}</span>
								</div>
							</div>
						{:else}
							<Icon name="upload" size={26} />
							<p class="mp-dropzone__prompt">Drag and drop a file here, or click to browse</p>
							{#if accept && accept !== '*/*'}
								<p class="mp-dropzone__hint">Accepted: {accept}</p>
							{/if}
						{/if}
					</div>

					{#if uploadFile && !uploadMimeAccepted}
						<div class="mp-alert mp-alert--error" role="alert">
							<Icon name="alert-triangle" size={15} />
							<span>File type "{uploadFile.type || 'unknown'}" does not match accepted types ({accept}).</span>
						</div>
					{/if}

					<div class="mp-form-row">
						<label class="mp-field">
							<span class="mp-label">Title</span>
							<input
								type="text"
								class="mp-input"
								placeholder="Descriptive asset title"
								bind:value={uploadTitle}
								disabled={uploading}
							/>
						</label>

						<label class="mp-field">
							<span class="mp-label">Visibility</span>
							<select class="mp-select" bind:value={uploadVisibility} disabled={uploading}>
								<option value="public">Public</option>
								<option value="private">Private</option>
							</select>
						</label>
					</div>

					{#if uploading}
						<div class="mp-progress" role="progressbar" aria-valuenow={uploadProgress} aria-valuemin={0} aria-valuemax={100}>
							<div class="mp-progress__bar" style={`width: ${uploadProgress}%`}></div>
							<span class="mp-progress__label">Uploading… {uploadProgress}%</span>
						</div>
					{/if}

					{#if uploadError}
						<div class="mp-alert mp-alert--error" role="alert">
							<Icon name="alert-triangle" size={15} />
							<span>{uploadError}</span>
						</div>
					{/if}

					<div class="mp-actions">
						<button type="button" class="mp-btn" onclick={() => onClose?.()} disabled={uploading}>Cancel</button>
						<button
							type="submit"
							class="mp-btn mp-btn--primary"
							disabled={!uploadFile || !uploadMimeAccepted || uploading}
						>
							<Icon name="upload" size={15} />
							<span>{uploading ? 'Uploading…' : 'Upload & Select'}</span>
						</button>
					</div>
				</form>
			{:else if activeTab === 'url' && allowExternalUrl}
				<form class="mp-panel mp-form" onsubmit={handleExternalUrlSubmit}>
					<label class="mp-field">
						<span class="mp-label">External HTTPS URL</span>
						<input
							type="url"
							class="mp-input"
							placeholder="https://example.com/media/hero.webp"
							bind:value={urlInput}
							oninput={() => (urlError = '')}
						/>
						<span class="mp-hint">Only secure <code>https://</code> URLs are accepted.</span>
					</label>

					{#if urlTrimmed && !urlIsValid}
						<div class="mp-alert mp-alert--error" role="alert">
							<Icon name="alert-triangle" size={15} />
							<span>Enter a valid URL starting with <code>https://</code>.</span>
						</div>
					{/if}

					{#if urlError}
						<div class="mp-alert mp-alert--error" role="alert">
							<Icon name="alert-triangle" size={15} />
							<span>{urlError}</span>
						</div>
					{/if}

					{#if canPreviewExternalAsImage}
						<div class="mp-url-preview">
							{#if !urlPreviewBroken}
								<img
									src={urlTrimmed}
									alt="External media preview"
									onerror={() => (urlPreviewBroken = true)}
								/>
							{:else}
								<div class="mp-url-preview__fallback">
									<Icon name="image-off" size={22} />
									<span>Preview could not be loaded (remote host may block hotlinking).</span>
								</div>
							{/if}
						</div>
					{/if}

					<div class="mp-actions">
						<button type="button" class="mp-btn" onclick={() => onClose?.()}>Cancel</button>
						<button type="submit" class="mp-btn mp-btn--primary" disabled={!urlIsValid}>
							<Icon name="check" size={15} />
							<span>Use External URL</span>
						</button>
					</div>
				</form>
			{/if}
		</div>
	</ModalDialog>
{/if}

<style>
	.mp-root {
		display: flex;
		flex-direction: column;
		gap: 12px;
		min-height: 420px;
		color: var(--ik-panel-text, var(--text, #3a3830));
		font-size: 0.875rem;
	}

	.mp-tabs {
		display: flex;
		gap: 4px;
		border-bottom: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
		padding-bottom: 0;
	}

	.mp-tab {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 8px 12px;
		margin-bottom: -1px;
		border: none;
		border-bottom: 2px solid transparent;
		background: transparent;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		font: inherit;
		font-weight: 500;
		cursor: pointer;
		transition: color 120ms ease, border-color 120ms ease;
	}

	.mp-tab:hover {
		color: var(--ik-panel-text, var(--text, #3a3830));
	}

	.mp-tab--active {
		color: var(--ik-accent, var(--accent, #a6613c));
		border-bottom-color: var(--ik-accent, var(--accent, #a6613c));
	}

	.mp-panel {
		display: flex;
		flex-direction: column;
		gap: 12px;
		flex: 1;
	}

	.mp-toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}

	.mp-search {
		position: relative;
		flex: 1 1 200px;
		display: flex;
		align-items: center;
	}

	.mp-search :global(.mp-search__icon) {
		position: absolute;
		left: 10px;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		pointer-events: none;
	}

	.mp-input,
	.mp-select {
		height: 36px;
		padding: 0 10px;
		border: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-md, 0.5rem);
		background: var(--ik-panel-bg, var(--surface, #f9f7f2));
		color: var(--ik-panel-text, var(--text, #3a3830));
		font: inherit;
		box-sizing: border-box;
	}

	.mp-input:focus,
	.mp-select:focus {
		outline: 2px solid color-mix(in srgb, var(--ik-accent, var(--accent, #a6613c)) 35%, transparent);
		border-color: var(--ik-accent, var(--accent, #a6613c));
	}

	.mp-search__input {
		width: 100%;
		padding-left: 32px;
	}

	.mp-grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 10px;
		max-height: 420px;
		overflow-y: auto;
		padding: 2px;
	}

	@media (min-width: 720px) {
		.mp-grid {
			grid-template-columns: repeat(4, minmax(0, 1fr));
		}
	}

	.mp-grid--loading {
		opacity: 0.65;
	}

	.mp-card {
		display: flex;
		flex-direction: column;
		padding: 0;
		border: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-md, 0.5rem);
		background: var(--ik-panel-bg, var(--surface, #f9f7f2));
		color: inherit;
		text-align: left;
		overflow: hidden;
		cursor: pointer;
		transition: border-color 120ms ease, box-shadow 120ms ease, transform 120ms ease;
	}

	.mp-card:hover {
		border-color: var(--ik-accent, var(--accent, #a6613c));
	}

	.mp-card--selected {
		border-color: var(--ik-accent, var(--accent, #a6613c));
		box-shadow: 0 0 0 2px var(--ik-accent, var(--accent, #a6613c));
	}

	.mp-card__thumb {
		position: relative;
		width: 100%;
		aspect-ratio: 4 / 3;
		background: var(--surface-hover, color-mix(in srgb, var(--ik-panel-text, #3a3830) 8%, transparent));
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
	}

	.mp-card__thumb img {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	.mp-card__icon {
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		display: inline-flex;
	}

	.mp-card__badge {
		position: absolute;
		left: 6px;
		bottom: 6px;
		padding: 1px 5px;
		border-radius: 4px;
		background: rgba(15, 15, 12, 0.72);
		color: #fff;
		font-size: 0.6875rem;
		font-weight: 600;
		letter-spacing: 0.03em;
	}

	.mp-card__check {
		position: absolute;
		top: 6px;
		right: 6px;
		width: 22px;
		height: 22px;
		border-radius: 9999px;
		background: var(--ik-accent, var(--accent, #a6613c));
		color: #fff;
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	.mp-card__meta {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 6px 8px;
		min-width: 0;
	}

	.mp-card__title {
		font-size: 0.8125rem;
		font-weight: 500;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.mp-card__sub {
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.mp-pagination {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
	}

	.mp-pagination__info {
		font-size: 0.8125rem;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
	}

	.mp-empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 10px;
		min-height: 220px;
		padding: 24px;
		border: 1px dashed var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-lg, 0.75rem);
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		text-align: center;
	}

	.mp-empty p {
		margin: 0;
	}

	.mp-form {
		gap: 14px;
	}

	.mp-form-row {
		display: grid;
		grid-template-columns: 1fr 160px;
		gap: 12px;
	}

	.mp-field {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.mp-label {
		font-size: 0.8125rem;
		font-weight: 500;
	}

	.mp-hint {
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
	}

	.mp-dropzone {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 8px;
		min-height: 150px;
		padding: 20px;
		border: 2px dashed var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-lg, 0.75rem);
		background: var(--surface-hover, color-mix(in srgb, var(--ik-panel-text, #3a3830) 4%, transparent));
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		cursor: pointer;
		text-align: center;
		transition: border-color 120ms ease, background-color 120ms ease;
	}

	.mp-dropzone--active,
	.mp-dropzone:hover {
		border-color: var(--ik-accent, var(--accent, #a6613c));
		background: color-mix(in srgb, var(--ik-accent, var(--accent, #a6613c)) 8%, transparent);
	}

	.mp-dropzone__prompt,
	.mp-dropzone__hint {
		margin: 0;
	}

	.mp-dropzone__hint {
		font-size: 0.75rem;
	}

	.mp-dropzone__selected {
		display: flex;
		align-items: center;
		gap: 12px;
		text-align: left;
	}

	.mp-dropzone__preview {
		width: 72px;
		height: 54px;
		object-fit: cover;
		border-radius: 6px;
		border: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
	}

	.mp-dropzone__file-info {
		display: flex;
		flex-direction: column;
		gap: 2px;
		color: var(--ik-panel-text, var(--text, #3a3830));
	}

	.mp-dropzone__file-info span {
		font-size: 0.75rem;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
	}

	.mp-progress {
		position: relative;
		height: 24px;
		border-radius: var(--radius-md, 0.5rem);
		background: var(--surface-hover, color-mix(in srgb, var(--ik-panel-text, #3a3830) 10%, transparent));
		overflow: hidden;
	}

	.mp-progress__bar {
		height: 100%;
		background: var(--ik-accent, var(--accent, #a6613c));
		transition: width 150ms ease;
	}

	.mp-progress__label {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 0.75rem;
		font-weight: 600;
		color: var(--ik-panel-text, var(--text, #3a3830));
	}

	.mp-url-preview {
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: 160px;
		max-height: 260px;
		border: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-md, 0.5rem);
		background: var(--surface-hover, color-mix(in srgb, var(--ik-panel-text, #3a3830) 5%, transparent));
		overflow: hidden;
		padding: 8px;
	}

	.mp-url-preview img {
		max-width: 100%;
		max-height: 240px;
		object-fit: contain;
		border-radius: 4px;
	}

	.mp-url-preview__fallback {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 6px;
		color: var(--ik-panel-text-muted, var(--text-muted, #7a7669));
		font-size: 0.8125rem;
		text-align: center;
	}

	.mp-alert {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 10px;
		border-radius: var(--radius-md, 0.5rem);
		font-size: 0.8125rem;
	}

	.mp-alert--error {
		border: 1px solid color-mix(in srgb, var(--danger, #dc2626) 40%, transparent);
		background: var(--danger-subtle, #fee2e2);
		color: var(--danger, #dc2626);
	}

	.mp-actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: auto;
		padding-top: 8px;
		border-top: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
	}

	.mp-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 6px;
		height: 36px;
		padding: 0 12px;
		border: 1px solid var(--ik-panel-border, var(--border, #ddd6cb));
		border-radius: var(--radius-md, 0.5rem);
		background: var(--ik-panel-bg, var(--surface, #f9f7f2));
		color: var(--ik-panel-text, var(--text, #3a3830));
		font: inherit;
		font-weight: 500;
		cursor: pointer;
		transition: background-color 120ms ease, border-color 120ms ease;
	}

	.mp-btn:hover:not(:disabled) {
		background: var(--surface-hover, color-mix(in srgb, var(--ik-panel-text, #3a3830) 8%, transparent));
	}

	.mp-btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	.mp-btn--sm {
		height: 30px;
		padding: 0 10px;
		font-size: 0.8125rem;
	}

	.mp-btn--ghost {
		border-color: transparent;
		background: transparent;
		padding: 0 8px;
	}

	.mp-btn--primary {
		border-color: transparent;
		background: var(--ik-accent, var(--accent, #a6613c));
		color: #ffffff;
	}

	.mp-btn--primary:hover:not(:disabled) {
		background: var(--ik-accent-hover, var(--accent-hover, #945231));
	}

	.mp-sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		white-space: nowrap;
		border: 0;
	}
</style>
