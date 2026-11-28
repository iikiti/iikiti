<script lang="ts">
	import { untrack } from 'svelte';
	import ModalDialog from '$components/ModalDialog.svelte';
	import Tabs from '$components/Tabs.svelte';
	import Icon from '$components/Icon.svelte';
	import Badge from '$components/Badge.svelte';
	import LoadingState from '$components/LoadingState.svelte';
	import {
		buildAssetMediaValue,
		clampUnit,
		formatBytes,
		isImageMimeType,
		resolveAssetEndpoint,
		resolvePreviewUrl,
	} from '$lib/mediaHelpers.js';
	import {
		buildFolderTree,
		flattenFolderTree,
		formatDuration,
		getExpiryStatus,
	} from '$lib/damHelpers.js';
	import type { ApiClient } from '$lib/api';
	import type {
		DigitalAsset,
		DigitalAssetExpiryAction,
		DigitalAssetFolder,
		DigitalAssetSiteOption,
		DigitalAssetTag,
		DigitalAssetUsage,
		DigitalAssetVersion,
	} from '$types';

	type DetailTab = 'overview' | 'localization' | 'variants' | 'usage' | 'versions' | 'rights';

	interface Props {
		api: ApiClient;
		asset: DigitalAsset | null;
		open: boolean;
		sites: DigitalAssetSiteOption[];
		folders: DigitalAssetFolder[];
		tags: DigitalAssetTag[];
		defaultLocale?: string | null;
		supportedContentLocales?: string[];
		expiryWarningDays?: number;
		onClose: () => void;
		onAssetUpdated: (updated: DigitalAsset) => void;
		onRequestDelete?: (asset: DigitalAsset) => void;
	}

	let {
		api,
		asset,
		open,
		sites,
		folders,
		tags,
		defaultLocale = null,
		supportedContentLocales = [],
		expiryWarningDays = 14,
		onClose,
		onAssetUpdated,
		onRequestDelete,
	}: Props = $props();

	const TABS: Array<{ label: string; value: DetailTab }> = [
		{ label: 'Overview', value: 'overview' },
		{ label: 'Localization & Metadata', value: 'localization' },
		{ label: 'Variants & Sharing', value: 'variants' },
		{ label: 'Where Used', value: 'usage' },
		{ label: 'Versions', value: 'versions' },
		{ label: 'Rights & Licensing', value: 'rights' },
	];

	const LICENSE_PRESETS = [
		'All Rights Reserved',
		'CC BY 4.0',
		'CC BY-SA 4.0',
		'CC BY-NC 4.0',
		'CC0 / Public Domain',
		'Royalty-Free',
		'Rights-Managed',
		'Editorial Only',
	];

	let activeTab = $state<DetailTab>('overview');
	let currentAsset = $state<DigitalAsset | null>(null);
	let saving = $state(false);
	let reprocessing = $state(false);
	let notice = $state('');
	let error = $state('');
	let copiedKey = $state('');
	let detailSeq = 0;
	let usageSeq = 0;
	let versionsSeq = 0;
	let formDirty = $state(false);

	// Overview form state
	let title = $state('');
	let visibility = $state<'private' | 'public'>('private');
	let externalUri = $state('');
	let folderId = $state('');
	let selectedSiteIds = $state<string[]>([]);
	let selectedTagIds = $state<string[]>([]);
	let focalX = $state(0.5);
	let focalY = $state(0.5);
	let focalCleared = $state(false);
	let focalDirty = $state(false);

	// Localization form state
	let activeLocale = $state('en');
	let altMap = $state<Record<string, string>>({});
	let captionMap = $state<Record<string, string>>({});
	let descMap = $state<Record<string, string>>({});

	// Signed URL generator state
	let signedVariant = $state('original');
	let signedTtl = $state(3600);
	let signedDisposition = $state<'inline' | 'attachment'>('inline');
	let signedSiteId = $state('');
	let generatingSignedUrl = $state(false);
	let signedResult = $state<{ url: string; expiresAt: string; signature?: string } | null>(null);

	// Where Used lazy state
	let usageLoadedForId = $state<string | null>(null);
	let loadingUsage = $state(false);
	let usageCount = $state(0);
	let usages = $state<DigitalAssetUsage[]>([]);
	let usageError = $state('');

	// Versions lazy state
	let versionsLoadedForId = $state<string | null>(null);
	let loadingVersions = $state(false);
	let currentVersionNumber = $state<number | null>(null);
	let versions = $state<DigitalAssetVersion[]>([]);
	let versionsError = $state('');
	let replaceFileInput = $state<HTMLInputElement | null>(null);
	let replaceFile = $state<File | null>(null);
	let replaceChangeNote = $state('');
	let replaceAllowMimeChange = $state(false);
	let replacing = $state(false);
	let downloadingVersion = $state<number | null>(null);
	let rollingBackVersion = $state<number | null>(null);

	// Rights & Licensing state
	let licenseType = $state('');
	let licenseHolder = $state('');
	let licenseUrl = $state('');
	let licenseNotes = $state('');
	let licenseExpiresAt = $state('');
	let licenseExpiryAction = $state<DigitalAssetExpiryAction>('alert');

	const flatFolders = $derived(flattenFolderTree(buildFolderTree(folders)));

	const availableLocales = $derived.by(() => {
		const set = new Set<string>();
		if (defaultLocale) set.add(defaultLocale);
		for (const loc of supportedContentLocales) {
			if (loc) set.add(loc);
		}
		if (currentAsset) {
			for (const loc of Object.keys(currentAsset.defaultAltTranslations ?? {})) set.add(loc);
			for (const loc of Object.keys(currentAsset.defaultCaptionTranslations ?? {})) set.add(loc);
			for (const loc of Object.keys(currentAsset.descriptionTranslations ?? {})) set.add(loc);
		}
		if (set.size === 0) set.add('en');
		return Array.from(set);
	});

	const isImage = $derived(currentAsset ? isImageMimeType(currentAsset.mimeType) : false);
	const isVideo = $derived(currentAsset ? String(currentAsset.mimeType ?? '').toLowerCase().startsWith('video/') : false);
	const isAudio = $derived(currentAsset ? String(currentAsset.mimeType ?? '').toLowerCase().startsWith('audio/') : false);

	const previewUrl = $derived(currentAsset ? resolvePreviewUrl(buildAssetMediaValue(currentAsset)) : '');

	const waveformBars = $derived.by(() => {
		const raw = currentAsset?.mediaMetadata?.waveform;
		if (!Array.isArray(raw)) return [] as number[];
		return raw
			.map((v) => Number(v))
			.filter((n) => Number.isFinite(n))
			.map((n) => Math.min(1, Math.max(0.05, Math.abs(n))));
	});

	const metadataEntries = $derived.by(() => {
		const meta = currentAsset?.mediaMetadata;
		if (!meta || typeof meta !== 'object') return [] as Array<{ section: string; key: string; value: string }>;
		const rows: Array<{ section: string; key: string; value: string }> = [];
		const visit = (section: string, obj: Record<string, any>) => {
			for (const [k, v] of Object.entries(obj)) {
				if (k === 'waveform' || v === null || v === undefined || v === '') continue;
				if (typeof v === 'object' && !Array.isArray(v)) {
					visit(section ? `${section} · ${k}` : k, v);
				} else if (Array.isArray(v)) {
					rows.push({ section: section || 'General', key: k, value: v.map(String).join(', ') });
				} else {
					rows.push({ section: section || 'General', key: k, value: String(v) });
				}
			}
		};
		visit('', meta);
		return rows;
	});

	const grantedSites = $derived.by(() => {
		if (!currentAsset) return sites;
		const ids = new Set((currentAsset.siteIds ?? []).map(String));
		const matched = sites.filter((s) => ids.has(String(s.id)));
		return matched.length > 0 ? matched : sites;
	});

	const licenseExpiryStatus = $derived(
		getExpiryStatus({ expiresAt: licenseExpiresAt || null }, undefined, expiryWarningDays),
	);

	function hydrateFromAsset(next: DigitalAsset) {
		currentAsset = next;
		formDirty = false;
		title = next.title ?? '';
		visibility = next.visibility === 'public' ? 'public' : 'private';
		externalUri = next.uri ?? '';
		folderId = next.folderId != null ? String(next.folderId) : (next.folder?.id != null ? String(next.folder.id) : '');
		selectedSiteIds = (next.siteIds ?? []).map(String);
		selectedTagIds = Array.isArray(next.tagIds)
			? next.tagIds.map(String)
			: Array.isArray(next.tags)
				? next.tags.map((t) => String(t.id))
				: [];
		focalX = clampUnit(next.focalPoint?.x ?? 0.5);
		focalY = clampUnit(next.focalPoint?.y ?? 0.5);
		focalCleared = false;
		focalDirty = false;

		// Hydrate localized maps
		const fallbackLoc = defaultLocale || supportedContentLocales[0] || 'en';
		const nextAlt: Record<string, string> = { ...(next.defaultAltTranslations ?? {}) };
		if (Object.keys(nextAlt).length === 0 && next.defaultAlt) {
			nextAlt[next.defaultAltLocale || fallbackLoc] = next.defaultAlt;
		}
		const nextCaption: Record<string, string> = { ...(next.defaultCaptionTranslations ?? {}) };
		if (Object.keys(nextCaption).length === 0 && next.defaultCaption) {
			nextCaption[next.defaultCaptionLocale || fallbackLoc] = next.defaultCaption;
		}
		const nextDesc: Record<string, string> = { ...(next.descriptionTranslations ?? {}) };
		if (Object.keys(nextDesc).length === 0 && next.description) {
			nextDesc[next.descriptionLocale || fallbackLoc] = next.description;
		}
		altMap = nextAlt;
		captionMap = nextCaption;
		descMap = nextDesc;
		if (!availableLocales.includes(activeLocale)) {
			activeLocale = fallbackLoc;
		}

		// Signed URL defaults
		signedVariant = 'original';
		signedSiteId = selectedSiteIds[0] ?? (sites[0] ? String(sites[0].id) : '');

		// Rights & licensing
		const lic = next.license ?? {};
		licenseType = lic.type ?? '';
		licenseHolder = lic.holder ?? '';
		licenseUrl = lic.url ?? '';
		licenseNotes = lic.notes ?? '';
		licenseExpiresAt = lic.expiresAt ? String(lic.expiresAt).slice(0, 10) : '';
		licenseExpiryAction = lic.expiryAction === 'make_private' ? 'make_private' : 'alert';
	}

	let lastHydratedId = '';
	$effect(() => {
		if (!open || !asset) {
			lastHydratedId = '';
			detailSeq += 1;
			usageSeq += 1;
			versionsSeq += 1;
			loadingUsage = false;
			loadingVersions = false;
			formDirty = false;
			if (replaceFileInput) replaceFileInput.value = '';
			return;
		}
		const idKey = String(asset.id);
		if (idKey !== lastHydratedId) {
			lastHydratedId = idKey;
			untrack(() => {
				usageSeq += 1;
				versionsSeq += 1;
				loadingUsage = false;
				loadingVersions = false;
				activeTab = 'overview';
				notice = '';
				error = '';
				signedResult = null;
				usageLoadedForId = null;
				versionsLoadedForId = null;
				usageError = '';
				versionsError = '';
				usages = [];
				versions = [];
				replaceFile = null;
				replaceChangeNote = '';
				replaceAllowMimeChange = false;
				if (replaceFileInput) replaceFileInput.value = '';
				hydrateFromAsset(asset);
				// Fetch freshest detail payload in background
				void refreshAssetDetail(asset.id);
			});
		}
	});

	// Lazy-load Where Used / Versions when switching tabs
	$effect(() => {
		if (!open || !currentAsset) return;
		const idStr = String(currentAsset.id);
		const tab = activeTab;
		if (tab === 'usage' && usageLoadedForId !== idStr && !loadingUsage) {
			untrack(() => void loadUsage(currentAsset!.id));
		} else if (tab === 'versions' && versionsLoadedForId !== idStr && !loadingVersions) {
			untrack(() => void loadVersions(currentAsset!.id));
		}
	});

	async function refreshAssetDetail(id: string | number) {
		const seq = ++detailSeq;
		try {
			const fresh = await api.getDigitalAsset(id);
			if (seq !== detailSeq || !open || String(asset?.id ?? '') !== String(id)) {
				return;
			}
			if (fresh && String(fresh.id) === String(id)) {
				if (formDirty && currentAsset) {
					currentAsset = {
						...currentAsset,
						variants: fresh.variants ?? currentAsset.variants,
						mediaMetadata: fresh.mediaMetadata ?? currentAsset.mediaMetadata,
						workflowInstances: fresh.workflowInstances ?? currentAsset.workflowInstances,
						usageCount: fresh.usageCount ?? currentAsset.usageCount,
						currentVersion: fresh.currentVersion ?? currentAsset.currentVersion,
						processingStatus: fresh.processingStatus ?? currentAsset.processingStatus,
						dominantColor: fresh.dominantColor ?? currentAsset.dominantColor,
						blurhash: fresh.blurhash ?? currentAsset.blurhash,
					};
				} else {
					hydrateFromAsset(fresh);
				}
			}
		} catch {
			// Keep already-loaded list record if detail fetch fails
		}
	}

	function handleFocalClick(event: MouseEvent) {
		const target = event.currentTarget as HTMLElement;
		const rect = target.getBoundingClientRect();
		if (rect.width <= 0 || rect.height <= 0) return;
		const x = clampUnit((event.clientX - rect.left) / rect.width);
		const y = clampUnit((event.clientY - rect.top) / rect.height);
		focalX = Number(x.toFixed(3));
		focalY = Number(y.toFixed(3));
		focalCleared = false;
		focalDirty = true;
		formDirty = true;
	}

	function resetFocalPoint() {
		focalX = 0.5;
		focalY = 0.5;
		focalCleared = true;
		focalDirty = true;
		formDirty = true;
	}

	async function saveOverview(event: SubmitEvent) {
		event.preventDefault();
		if (!currentAsset || saving) return;
		saving = true;
		error = '';
		notice = '';
		try {
			const payload: Record<string, any> = {
				title: title.trim(),
				visibility,
				siteIds: selectedSiteIds,
				folderId: folderId || null,
				tagIds: selectedTagIds,
			};
			if (currentAsset.type === 'external') {
				payload.uri = externalUri.trim();
			}
			if (isImage && focalDirty) {
				payload.focalPoint = focalCleared ? null : { x: focalX, y: focalY };
			}
			const updated = await api.updateDigitalAsset(currentAsset.id, payload);
			detailSeq += 1;
			hydrateFromAsset(updated);
			onAssetUpdated(updated);
			notice = 'Overview saved.';
		} catch (e: any) {
			error = e?.message ?? 'Failed to save overview.';
		} finally {
			saving = false;
		}
	}

	async function saveLocalization(event: SubmitEvent) {
		event.preventDefault();
		if (!currentAsset || saving) return;
		saving = true;
		error = '';
		notice = '';
		try {
			const updated = await api.updateDigitalAsset(currentAsset.id, {
				defaultAlt: altMap,
				defaultCaption: captionMap,
				description: descMap,
			});
			detailSeq += 1;
			hydrateFromAsset(updated);
			onAssetUpdated(updated);
			notice = 'Localized metadata saved.';
		} catch (e: any) {
			error = e?.message ?? 'Failed to save localized metadata.';
		} finally {
			saving = false;
		}
	}

	async function triggerReprocess() {
		if (!currentAsset || reprocessing) return;
		reprocessing = true;
		error = '';
		notice = '';
		try {
			await api.reprocessDigitalAsset(currentAsset.id);
			notice = 'Reprocessing queued. Variants will refresh shortly.';
			await refreshAssetDetail(currentAsset.id);
			if (currentAsset) onAssetUpdated(currentAsset);
		} catch (e: any) {
			error = e?.message ?? 'Failed to queue reprocessing.';
		} finally {
			reprocessing = false;
		}
	}

	async function generateSignedUrl(event: SubmitEvent) {
		event.preventDefault();
		if (!currentAsset || generatingSignedUrl) return;
		generatingSignedUrl = true;
		error = '';
		try {
			const payload: Record<string, any> = {
				variant: signedVariant || 'original',
				ttlSeconds: Math.min(86400, Math.max(60, Number(signedTtl) || 3600)),
				disposition: signedDisposition,
			};
			if (signedSiteId) payload.siteId = signedSiteId;
			signedResult = await api.createDigitalAssetSignedUrl(currentAsset.id, payload);
		} catch (e: any) {
			error = e?.message ?? 'Failed to generate signed URL.';
		} finally {
			generatingSignedUrl = false;
		}
	}

	async function copyText(key: string, text: string) {
		try {
			await navigator.clipboard.writeText(text);
			copiedKey = key;
			setTimeout(() => {
				if (copiedKey === key) copiedKey = '';
			}, 1800);
		} catch {
			error = 'Clipboard write failed. Copy the text manually.';
		}
	}

	async function loadUsage(id: string | number) {
		const seq = ++usageSeq;
		usageLoadedForId = String(id);
		loadingUsage = true;
		usageError = '';
		try {
			const data = await api.getDigitalAssetUsage(id);
			if (seq !== usageSeq || !open || String(currentAsset?.id ?? '') !== String(id)) return;
			usageCount = Number(data?.usageCount ?? (Array.isArray(data?.usages) ? data.usages.length : 0));
			usages = Array.isArray(data?.usages) ? data.usages : [];
		} catch (e: any) {
			if (seq !== usageSeq || !open || String(currentAsset?.id ?? '') !== String(id)) return;
			usageError = e?.message ?? 'Unable to load asset usage references.';
		} finally {
			if (seq === usageSeq) {
				loadingUsage = false;
			}
		}
	}

	async function loadVersions(id: string | number) {
		const seq = ++versionsSeq;
		versionsLoadedForId = String(id);
		loadingVersions = true;
		versionsError = '';
		try {
			const data = await api.getDigitalAssetVersions(id);
			if (seq !== versionsSeq || !open || String(currentAsset?.id ?? '') !== String(id)) return;
			currentVersionNumber = data?.currentVersion ?? null;
			versions = Array.isArray(data?.versions) ? data.versions : [];
		} catch (e: any) {
			if (seq !== versionsSeq || !open || String(currentAsset?.id ?? '') !== String(id)) return;
			versionsError = e?.message ?? 'Unable to load asset versions.';
		} finally {
			if (seq === versionsSeq) {
				loadingVersions = false;
			}
		}
	}

	async function downloadVersion(ver: DigitalAssetVersion) {
		const targetUrl = ver.downloadUrl || ver.url;
		if (!targetUrl || downloadingVersion !== null) return;
		downloadingVersion = ver.versionNumber;
		versionsError = '';
		try {
			const headers = api.getHeaders();
			delete headers['Content-Type'];
			const resolvedUrl = resolveAssetEndpoint(api.baseUrl, targetUrl);
			const response = await fetch(resolvedUrl, {
				method: 'GET',
				credentials: 'same-origin',
				headers,
			});
			if (!response.ok) {
				const body = await response.json().catch(() => ({}));
				throw new Error(body.error ?? body.title ?? `Download failed (${response.status})`);
			}
			const blob = await response.blob();
			const objectUrl = URL.createObjectURL(blob);
			try {
				const link = document.createElement('a');
				link.href = objectUrl;
				link.download = ver.originalName || `${currentAsset?.title || 'asset'}-v${ver.versionNumber}`;
				document.body.appendChild(link);
				link.click();
				link.remove();
			} finally {
				URL.revokeObjectURL(objectUrl);
			}
		} catch (e: any) {
			versionsError = e?.message ?? 'Unable to download asset version.';
		} finally {
			downloadingVersion = null;
		}
	}

	async function submitReplace(event: SubmitEvent) {
		event.preventDefault();
		if (!currentAsset || !replaceFile || replacing) return;
		replacing = true;
		error = '';
		notice = '';
		try {
			const formData = new FormData();
			formData.set('file', replaceFile);
			if (replaceChangeNote.trim()) {
				formData.set('changeNote', replaceChangeNote.trim());
			}
			if (replaceAllowMimeChange) {
				formData.set('allowMimeChange', '1');
			}
			const updated = await api.replaceDigitalAsset(currentAsset.id, formData);
			const updatedAsset = (updated?.asset ?? updated) as DigitalAsset | undefined;
			replaceFile = null;
			replaceChangeNote = '';
			replaceAllowMimeChange = false;
			if (replaceFileInput) replaceFileInput.value = '';
			if (updatedAsset?.id) {
				detailSeq += 1;
				hydrateFromAsset(updatedAsset);
				onAssetUpdated(updatedAsset);
			}
			await loadVersions(currentAsset.id);
			notice = 'File replaced in place. A new version has been recorded.';
		} catch (e: any) {
			error = e?.message ?? 'Failed to replace asset file.';
		} finally {
			replacing = false;
		}
	}

	async function rollbackVersion(versionNumber: number) {
		if (!currentAsset || rollingBackVersion !== null) return;
		if (!window.confirm(`Rollback this asset to version v${versionNumber}?`)) return;
		rollingBackVersion = versionNumber;
		error = '';
		notice = '';
		try {
			const updated = await api.rollbackDigitalAssetVersion(currentAsset.id, versionNumber, {
				changeNote: `Rolled back to v${versionNumber}`,
			});
			const updatedAsset = (updated?.asset ?? updated) as DigitalAsset | undefined;
			if (updatedAsset?.id) {
				detailSeq += 1;
				hydrateFromAsset(updatedAsset);
				onAssetUpdated(updatedAsset);
			}
			await loadVersions(currentAsset.id);
			notice = `Rolled back to version v${versionNumber}.`;
		} catch (e: any) {
			error = e?.message ?? 'Failed to rollback version.';
		} finally {
			rollingBackVersion = null;
		}
	}

	async function saveRights(event: SubmitEvent) {
		event.preventDefault();
		if (!currentAsset || saving) return;
		saving = true;
		error = '';
		notice = '';
		try {
			const license = {
				type: licenseType.trim() || null,
				holder: licenseHolder.trim() || null,
				url: licenseUrl.trim() || null,
				notes: licenseNotes.trim() || null,
				expiresAt: licenseExpiresAt ? `${licenseExpiresAt}T00:00:00Z` : null,
				expiryAction: licenseExpiryAction,
			};
			const updated = await api.updateDigitalAsset(currentAsset.id, { license });
			detailSeq += 1;
			hydrateFromAsset(updated);
			onAssetUpdated(updated);
			notice = 'Rights & licensing saved.';
		} catch (e: any) {
			error = e?.message ?? 'Failed to save rights & licensing.';
		} finally {
			saving = false;
		}
	}
</script>

{#if open && currentAsset}
	<ModalDialog title={currentAsset.title || 'Asset Details'} open={true} {onClose} width={896}>
		<div class="space-y-4 p-2">
			{#if error}
				<div class="rounded-lg border border-danger/40 bg-danger-subtle px-3 py-2 text-sm text-danger" role="alert">
					{error}
				</div>
			{/if}
			{#if notice}
				<div class="rounded-lg border border-success/40 bg-success-subtle px-3 py-2 text-sm text-success" role="status">
					{notice}
				</div>
			{/if}

			<Tabs
				tabs={TABS}
				activeValue={activeTab}
				onSelect={(v) => {
					activeTab = v as DetailTab;
					notice = '';
					error = '';
				}}
			/>

			<!-- ==================== TAB 1: OVERVIEW ==================== -->
			{#if activeTab === 'overview'}
				<div class="grid gap-5 lg:grid-cols-12">
					<!-- Media Preview & Focal Point -->
					<div class="space-y-3 lg:col-span-6">
						{#if isImage && previewUrl}
							<div class="space-y-2">
								<div class="flex items-center justify-between text-xs text-text-muted">
									<span class="inline-flex items-center gap-1 font-medium text-text">
										<Icon name="crosshair" size={13} />
										Click image to set focal point
									</span>
									<button type="button" class="text-accent underline hover:text-accent-hover" onclick={resetFocalPoint}>
										Reset center
									</button>
								</div>

								<div
									class="flex justify-center overflow-hidden rounded-xl border border-border bg-bg"
									style={currentAsset.dominantColor ? `background-color: ${currentAsset.dominantColor}` : undefined}
								>
									<div
										class="relative mx-auto inline-block cursor-crosshair select-none"
										role="button"
										tabindex="0"
										aria-label="Set image focal point"
										onclick={handleFocalClick}
										onkeydown={(e) => {
											if (e.key === 'ArrowLeft') {
												e.preventDefault();
												focalX = clampUnit(Number((focalX - 0.05).toFixed(3)));
												focalDirty = true;
												focalCleared = false;
												formDirty = true;
											} else if (e.key === 'ArrowRight') {
												e.preventDefault();
												focalX = clampUnit(Number((focalX + 0.05).toFixed(3)));
												focalDirty = true;
												focalCleared = false;
												formDirty = true;
											} else if (e.key === 'ArrowUp') {
												e.preventDefault();
												focalY = clampUnit(Number((focalY - 0.05).toFixed(3)));
												focalDirty = true;
												focalCleared = false;
												formDirty = true;
											} else if (e.key === 'ArrowDown') {
												e.preventDefault();
												focalY = clampUnit(Number((focalY + 0.05).toFixed(3)));
												focalDirty = true;
												focalCleared = false;
												formDirty = true;
											}
										}}
									>
										<img
											src={previewUrl}
											alt={currentAsset.defaultAlt || currentAsset.title}
											class="block max-h-80 max-w-full"
										/>
										<!-- Crosshair lines & marker -->
										<div
											class="pointer-events-none absolute top-0 bottom-0 w-px bg-white/70 shadow"
											style={`left: ${(focalX * 100).toFixed(1)}%`}
										></div>
										<div
											class="pointer-events-none absolute right-0 left-0 h-px bg-white/70 shadow"
											style={`top: ${(focalY * 100).toFixed(1)}%`}
										></div>
										<div
											class="pointer-events-none absolute h-6 w-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-accent/80 shadow-md ring-2 ring-black/40"
											style={`left: ${(focalX * 100).toFixed(1)}%; top: ${(focalY * 100).toFixed(1)}%`}
										></div>
									</div>
								</div>

								<div class="flex items-center justify-between text-xs text-text-muted">
									<span>Focal point: <strong>{Math.round(focalX * 100)}%</strong> × <strong>{Math.round(focalY * 100)}%</strong></span>
									{#if currentAsset.width && currentAsset.height}
										<span>{currentAsset.width} × {currentAsset.height} px</span>
									{/if}
								</div>
							</div>
						{:else if isVideo}
							<div class="space-y-2">
								<!-- svelte-ignore a11y_media_has_caption -->
								<video
									controls
									preload="metadata"
									poster={currentAsset.thumbnailUrl || undefined}
									src={currentAsset.uri || currentAsset.fileUrl}
									class="max-h-80 w-full rounded-xl border border-border bg-black"
								></video>
								{#if currentAsset.durationMs}
									<p class="text-xs text-text-muted">Duration: {formatDuration(currentAsset.durationMs)}</p>
								{/if}
							</div>
						{:else if isAudio}
							<div class="space-y-3 rounded-xl border border-border bg-bg p-4">
								<div class="flex items-center gap-2 text-sm font-medium text-text">
									<Icon name="music" size={18} class="text-accent" />
									<span>Audio Preview</span>
									{#if currentAsset.durationMs}
										<span class="ml-auto text-xs text-text-muted">{formatDuration(currentAsset.durationMs)}</span>
									{/if}
								</div>

								{#if waveformBars.length > 0}
									<svg
										viewBox={`0 0 ${waveformBars.length * 4} 64`}
										class="h-20 w-full rounded-lg bg-surface p-2"
										role="img"
										aria-label="Audio waveform visualizer"
										preserveAspectRatio="none"
									>
										{#each waveformBars as amp, i (i)}
											{@const h = Math.max(4, amp * 56)}
											<rect
												x={i * 4 + 1}
												y={(64 - h) / 2}
												width="2.5"
												height={h}
												rx="1"
												fill="var(--accent)"
												opacity="0.85"
											/>
										{/each}
									</svg>
								{/if}

								<audio controls src={currentAsset.uri || currentAsset.fileUrl} class="w-full"></audio>
							</div>
						{:else}
							<div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-border bg-bg p-8 text-center">
								<Icon name="file-text" size={44} class="text-text-muted" />
								<div class="text-sm font-medium text-text">{currentAsset.originalName || currentAsset.title}</div>
								<div class="text-xs text-text-muted">{currentAsset.mimeType} · {formatBytes(currentAsset.size)}</div>
							</div>
						{/if}

						<!-- Quick File Info Card -->
						<div class="grid grid-cols-2 gap-2 rounded-lg border border-border bg-bg p-3 text-xs">
							<div><span class="text-text-muted">MIME:</span> <span class="font-medium text-text">{currentAsset.mimeType}</span></div>
							<div><span class="text-text-muted">Size:</span> <span class="font-medium text-text">{formatBytes(currentAsset.size)}</span></div>
							<div><span class="text-text-muted">Type:</span> <span class="font-medium text-text">{currentAsset.type}</span></div>
							<div><span class="text-text-muted">Status:</span> <span class="font-medium text-text">{currentAsset.processingStatus}</span></div>
							<div class="col-span-2 flex items-center justify-between gap-2 pt-1 border-t border-border">
								<a
									href={currentAsset.uri || currentAsset.fileUrl}
									target="_blank"
									rel="noopener noreferrer"
									class="inline-flex items-center gap-1 text-accent underline hover:text-accent-hover"
								>
									<Icon name="external-link" size={12} />
									<span>Open original file</span>
								</a>
								{#if onRequestDelete}
									<button
										type="button"
										class="inline-flex items-center gap-1 text-danger hover:underline"
										onclick={() => onRequestDelete?.(currentAsset!)}
									>
										<Icon name="trash-2" size={12} />
										<span>Delete asset</span>
									</button>
								{/if}
							</div>
						</div>
					</div>

					<!-- Metadata Edit Form -->
					<form class="space-y-3 lg:col-span-6" onsubmit={saveOverview}>
						<label class="block space-y-1 text-sm">
							<span class="text-text-muted">Title</span>
							<input
								class="admin-input admin-input-md"
								bind:value={title}
								oninput={() => (formDirty = true)}
								maxlength="255"
								required
							/>
						</label>

						{#if currentAsset.type === 'external'}
							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">External HTTPS URI</span>
								<input
									class="admin-input admin-input-md"
									type="url"
									bind:value={externalUri}
									oninput={() => (formDirty = true)}
									required
								/>
							</label>
						{/if}

						<div class="grid gap-3 sm:grid-cols-2">
							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">Visibility</span>
								<select
									class="admin-input admin-input-md"
									bind:value={visibility}
									onchange={() => (formDirty = true)}
								>
									<option value="private">Private</option>
									<option value="public">Public</option>
								</select>
							</label>

							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">Folder</span>
								<select
									class="admin-input admin-input-md"
									bind:value={folderId}
									onchange={() => (formDirty = true)}
								>
									<option value="">Unfiled</option>
									{#each flatFolders as folder (String(folder.id))}
										<option value={String(folder.id)}>
											{folder.depth ? `${'— '.repeat(folder.depth)}${folder.name}` : folder.name}
										</option>
									{/each}
								</select>
							</label>
						</div>

						<div class="space-y-1.5 text-sm">
							<span class="block text-text-muted">Site Grants</span>
							<div class="flex flex-wrap gap-1.5">
								{#each sites as site (String(site.id))}
									{@const id = String(site.id)}
									{@const checked = selectedSiteIds.includes(id)}
									<label
										class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs {checked
											? 'border-accent bg-accent-subtle text-accent font-medium'
											: 'border-border bg-bg text-text'}"
									>
										<input
											type="checkbox"
											{checked}
											onchange={() => {
												formDirty = true;
												selectedSiteIds = checked
													? selectedSiteIds.filter((s) => s !== id)
													: [...selectedSiteIds, id];
											}}
										/>
										<span>{site.name || site.domain}</span>
									</label>
								{/each}
							</div>
						</div>

						{#if tags.length > 0}
							<div class="space-y-1.5 text-sm">
								<span class="block text-text-muted">Tags</span>
								<div class="flex flex-wrap gap-1.5">
									{#each tags as tag (String(tag.id))}
										{@const id = String(tag.id)}
										{@const active = selectedTagIds.includes(id)}
										<button
											type="button"
											class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs {active
												? 'border-accent bg-accent-subtle text-accent font-medium'
												: 'border-border bg-bg text-text-muted hover:text-text'}"
											onclick={() => {
												formDirty = true;
												selectedTagIds = active
													? selectedTagIds.filter((t) => t !== id)
													: [...selectedTagIds, id];
											}}
										>
											<Icon name="tag" size={10} />
											<span>{tag.name}</span>
										</button>
									{/each}
								</div>
							</div>
						{/if}

						<div class="flex justify-end pt-2">
							<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" disabled={saving}>
								<Icon name="save" size={14} />
								<span>{saving ? 'Saving…' : 'Save Overview'}</span>
							</button>
						</div>
					</form>
				</div>

			<!-- ==================== TAB 2: LOCALIZATION & METADATA ==================== -->
			{:else if activeTab === 'localization'}
				<div class="space-y-5">
					<form class="space-y-4 rounded-xl border border-border bg-bg p-4" onsubmit={saveLocalization}>
						<div class="flex flex-wrap items-center justify-between gap-2">
							<h4 class="text-sm font-semibold text-text">Localized Alt Text, Caption & Description</h4>
							<div class="flex flex-wrap gap-1" role="tablist" aria-label="Content locales">
								{#each availableLocales as locale (locale)}
									<button
										type="button"
										role="tab"
										aria-selected={activeLocale === locale}
										class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors {activeLocale === locale
											? 'bg-accent text-white'
											: 'border border-border bg-surface text-text-muted hover:text-text'}"
										onclick={() => (activeLocale = locale)}
									>
										{locale}
									</button>
								{/each}
							</div>
						</div>

						<div class="grid gap-3 sm:grid-cols-2">
							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">Default Alt Text ({activeLocale})</span>
								<input
									class="admin-input admin-input-md"
									value={altMap[activeLocale] ?? ''}
									oninput={(e) => {
										formDirty = true;
										altMap = { ...altMap, [activeLocale]: e.currentTarget.value };
									}}
									placeholder="Concise accessible description"
								/>
							</label>

							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">Default Caption ({activeLocale})</span>
								<input
									class="admin-input admin-input-md"
									value={captionMap[activeLocale] ?? ''}
									oninput={(e) => {
										formDirty = true;
										captionMap = { ...captionMap, [activeLocale]: e.currentTarget.value };
									}}
									placeholder="Optional photo credit or caption"
								/>
							</label>
						</div>

						<label class="block space-y-1 text-sm">
							<span class="text-text-muted">Description ({activeLocale})</span>
							<textarea
								rows="3"
								class="admin-input admin-input-md"
								value={descMap[activeLocale] ?? ''}
								oninput={(e) => {
									formDirty = true;
									descMap = { ...descMap, [activeLocale]: e.currentTarget.value };
								}}
								placeholder="Internal notes or extended asset description…"
							></textarea>
						</label>

						<div class="flex justify-end">
							<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" disabled={saving}>
								<Icon name="save" size={14} />
								<span>{saving ? 'Saving…' : 'Save Translations'}</span>
							</button>
						</div>
					</form>

					<!-- Visual Fingerprint -->
					<div class="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-2">
						<div class="space-y-1">
							<span class="text-xs font-semibold uppercase text-text-muted">Dominant Color</span>
							{#if currentAsset.dominantColor}
								<div class="flex items-center gap-2">
									<span
										class="inline-block h-6 w-6 rounded-md border border-border shadow-xs"
										style={`background-color: ${currentAsset.dominantColor}`}
									></span>
									<code class="text-xs text-text">{currentAsset.dominantColor}</code>
									<button
										type="button"
										class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-0.5 text-xs"
										onclick={() => void copyText('color', currentAsset!.dominantColor!)}
									>
										{copiedKey === 'color' ? 'Copied' : 'Copy'}
									</button>
								</div>
							{:else}
								<p class="text-xs text-text-muted">Not extracted</p>
							{/if}
						</div>

						<div class="space-y-1">
							<span class="text-xs font-semibold uppercase text-text-muted">BlurHash</span>
							{#if currentAsset.blurhash}
								<div class="flex items-center gap-2">
									<code class="truncate rounded bg-bg px-2 py-1 text-xs text-text">{currentAsset.blurhash}</code>
									<button
										type="button"
										class="admin-btn admin-btn-ghost admin-btn-sm px-1.5 py-0.5 text-xs"
										onclick={() => void copyText('blurhash', currentAsset!.blurhash!)}
									>
										{copiedKey === 'blurhash' ? 'Copied' : 'Copy'}
									</button>
								</div>
							{:else}
								<p class="text-xs text-text-muted">Not generated</p>
							{/if}
						</div>
					</div>

					<!-- EXIF / IPTC / XMP Read-Only Inspector -->
					<div class="space-y-2">
						<h4 class="text-sm font-semibold text-text">Embedded Metadata (EXIF / IPTC / XMP)</h4>
						{#if metadataEntries.length > 0}
							<div class="max-h-64 overflow-auto rounded-lg border border-border">
								<table class="admin-table">
									<thead>
										<tr class="border-b border-border">
											<th>Group</th>
											<th>Field</th>
											<th>Value</th>
										</tr>
									</thead>
									<tbody>
										{#each metadataEntries as row, idx (`${row.section}:${row.key}:${idx}`)}
											<tr>
												<td class="text-xs text-text-muted">{row.section}</td>
												<td class="font-mono text-xs text-text">{row.key}</td>
												<td class="break-all text-xs text-text">{row.value}</td>
											</tr>
										{/each}
									</tbody>
								</table>
							</div>
						{:else}
							<p class="rounded-lg border border-border bg-bg p-3 text-xs text-text-muted">
								No EXIF, IPTC, or XMP metadata extracted for this asset.
							</p>
						{/if}
					</div>
				</div>

			<!-- ==================== TAB 3: VARIANTS & SHARING ==================== -->
			{:else if activeTab === 'variants'}
				<div class="space-y-5">
					<div class="flex flex-wrap items-center justify-between gap-2">
						<div>
							<h4 class="text-sm font-semibold text-text">Generated Derivatives ({currentAsset.variants?.length ?? 0})</h4>
							<p class="text-xs text-text-muted">Optimized WebP/AVIF thumbnails, posters, and responsive renditions.</p>
						</div>
						{#if currentAsset.type === 'upload'}
							<button
								type="button"
								class="admin-btn admin-btn-secondary admin-btn-sm gap-1.5"
								disabled={reprocessing}
								onclick={() => void triggerReprocess()}
							>
								<Icon name="refresh-cw" size={14} />
								<span>{reprocessing ? 'Queuing…' : 'Reprocess Asset'}</span>
							</button>
						{/if}
					</div>

					{#if currentAsset.variants && currentAsset.variants.length > 0}
						<div class="overflow-x-auto rounded-lg border border-border">
							<table class="admin-table">
								<thead>
									<tr class="border-b border-border">
										<th>Key</th>
										<th>Format</th>
										<th>Dimensions</th>
										<th>Size</th>
										<th>Focal Crop</th>
										<th class="text-right">Link</th>
									</tr>
								</thead>
								<tbody>
									{#each currentAsset.variants as v (v.key)}
										<tr>
											<td class="font-mono text-xs font-medium text-text">{v.key}</td>
											<td class="text-xs text-text-muted">{v.format?.toUpperCase() || v.mimeType}</td>
											<td class="text-xs text-text">{v.width && v.height ? `${v.width}×${v.height}` : '—'}</td>
											<td class="text-xs text-text-muted">{v.size ? formatBytes(v.size) : '—'}</td>
											<td>
												{#if v.focalCrop}
													<Badge status="info" outline>Focal</Badge>
												{:else}
													<span class="text-xs text-text-muted">Standard</span>
												{/if}
											</td>
											<td class="text-right">
												<a
													href={v.url}
													target="_blank"
													rel="noopener noreferrer"
													class="inline-flex items-center gap-1 text-xs text-accent underline"
												>
													<span>Open</span>
													<Icon name="external-link" size={11} />
												</a>
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{:else}
						<p class="rounded-lg border border-border bg-bg p-3 text-xs text-text-muted">
							No derivative variants generated yet.
						</p>
					{/if}

					{#if currentAsset.workflowInstances && currentAsset.workflowInstances.length > 0}
						<div class="space-y-1.5 rounded-lg border border-border bg-bg p-3">
							<h5 class="text-xs font-semibold uppercase text-text-muted">Processing Workflows</h5>
							{#each currentAsset.workflowInstances as run (`${run.workflow}:${run.version}`)}
								<div class="flex flex-wrap items-center justify-between gap-2 text-xs">
									<span class="font-mono text-text">{run.workflow} v{run.version}</span>
									<span class="text-text-muted">
										{run.status} ({run.executionState}) · {run.attempts} attempt{run.attempts === 1 ? '' : 's'}
									</span>
									{#if run.error}
										<span class="w-full text-danger">{run.error}</span>
									{/if}
								</div>
							{/each}
						</div>
					{/if}

					<!-- Signed URL Generator -->
					<form class="space-y-3 rounded-xl border border-border bg-bg p-4" onsubmit={generateSignedUrl}>
						<div>
							<h4 class="text-sm font-semibold text-text">Short-Lived Signed URL Generator</h4>
							<p class="text-xs text-text-muted">Create a time-limited HMAC-signed link to share private or public renditions securely.</p>
						</div>

						<div class="grid gap-3 sm:grid-cols-4">
							<label class="block space-y-1 text-xs">
								<span class="text-text-muted">Variant</span>
								<select class="admin-input admin-input-sm text-xs" bind:value={signedVariant}>
									<option value="original">original</option>
									{#each currentAsset.variants ?? [] as v (v.key)}
										<option value={v.key}>{v.key}</option>
									{/each}
								</select>
							</label>

							<label class="block space-y-1 text-xs">
								<span class="text-text-muted">TTL (seconds)</span>
								<select class="admin-input admin-input-sm text-xs" bind:value={signedTtl}>
									<option value={300}>5 minutes (300s)</option>
									<option value={900}>15 minutes (900s)</option>
									<option value={3600}>1 hour (3600s)</option>
									<option value={21600}>6 hours (21600s)</option>
									<option value={86400}>24 hours (86400s)</option>
								</select>
							</label>

							<label class="block space-y-1 text-xs">
								<span class="text-text-muted">Disposition</span>
								<select class="admin-input admin-input-sm text-xs" bind:value={signedDisposition}>
									<option value="inline">Inline preview</option>
									<option value="attachment">Force download</option>
								</select>
							</label>

							<label class="block space-y-1 text-xs">
								<span class="text-text-muted">Site context</span>
								<select class="admin-input admin-input-sm text-xs" bind:value={signedSiteId}>
									{#each grantedSites as site (String(site.id))}
										<option value={String(site.id)}>{site.name || site.domain}</option>
									{/each}
								</select>
							</label>
						</div>

						<div class="flex justify-end">
							<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" disabled={generatingSignedUrl}>
								<Icon name="link" size={13} />
								<span>{generatingSignedUrl ? 'Generating…' : 'Generate Signed URL'}</span>
							</button>
						</div>

						{#if signedResult}
							<div class="space-y-2 rounded-lg border border-border bg-surface p-3 text-xs">
								<div class="flex items-center justify-between gap-2">
									<span class="font-medium text-text">Generated Signed URL</span>
									<span class="text-text-muted">Expires: {signedResult.expiresAt}</span>
								</div>
								<div class="flex items-center gap-2">
									<input
										type="text"
										readonly
										class="admin-input admin-input-sm flex-1 font-mono text-xs"
										value={signedResult.url}
									/>
									<button
										type="button"
										class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
										onclick={() => void copyText('signed-url', signedResult!.url)}
									>
										<Icon name={copiedKey === 'signed-url' ? 'check' : 'copy'} size={13} />
										<span>{copiedKey === 'signed-url' ? 'Copied' : 'Copy'}</span>
									</button>
								</div>
								{#if signedResult.signature}
									<div class="truncate text-[11px] text-text-muted">
										Signature: <code>{signedResult.signature}</code>
									</div>
								{/if}
							</div>
						{/if}
					</form>
				</div>

			<!-- ==================== TAB 4: WHERE USED ==================== -->
			{:else if activeTab === 'usage'}
				<div class="space-y-3">
					<div class="flex items-center justify-between">
						<div>
							<h4 class="text-sm font-semibold text-text">Where Used ({usageCount})</h4>
							<p class="text-xs text-text-muted">Pages, templates, site settings, and drafts referencing this asset.</p>
						</div>
						<button
							type="button"
							class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
							disabled={loadingUsage}
							onclick={() => void loadUsage(currentAsset!.id)}
						>
							<Icon name="refresh-cw" size={13} />
							<span>Refresh</span>
						</button>
					</div>

					{#if loadingUsage}
						<LoadingState label="Checking references…" />
					{:else if usageError}
						<p class="rounded-lg border border-danger/40 bg-danger-subtle p-3 text-sm text-danger">{usageError}</p>
					{:else if usages.length === 0}
						<div class="rounded-xl border border-border bg-bg p-6 text-center text-sm text-text-muted">
							This asset is not currently referenced by any pages, templates, or settings.
						</div>
					{:else}
						<div class="overflow-x-auto rounded-lg border border-border">
							<table class="admin-table">
								<thead>
									<tr class="border-b border-border">
										<th>Entity</th>
										<th>Title / Reference</th>
										<th>Field / Context</th>
										<th>State</th>
										<th class="text-right">Link</th>
									</tr>
								</thead>
								<tbody>
									{#each usages as usage, idx (`${usage.entityType}:${usage.entityId}:${idx}`)}
										<tr>
											<td><Badge status="primary" outline>{usage.entityType}</Badge></td>
											<td class="font-medium text-text">
												{usage.title || usage.label || `#${usage.entityId}`}
												{#if usage.siteName}
													<div class="text-xs text-text-muted">{usage.siteName}</div>
												{/if}
											</td>
											<td class="font-mono text-xs text-text-muted">{usage.field || usage.context || '—'}</td>
											<td>
												{#if usage.isDraft}
													<Badge status="warning">Draft</Badge>
												{:else}
													<Badge status="success" outline>Published</Badge>
												{/if}
											</td>
											<td class="text-right">
												{#if usage.adminUrl || usage.url}
													<a
														href={usage.adminUrl || usage.url || '#'}
														target="_blank"
														rel="noopener noreferrer"
														class="inline-flex items-center gap-1 text-xs text-accent underline"
													>
														<span>Open</span>
														<Icon name="external-link" size={11} />
													</a>
												{:else}
													<span class="text-xs text-text-muted">—</span>
												{/if}
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{/if}
				</div>

			<!-- ==================== TAB 5: VERSIONS ==================== -->
			{:else if activeTab === 'versions'}
				<div class="space-y-5">
					{#if currentAsset.type === 'upload'}
						<form class="space-y-3 rounded-xl border border-border bg-bg p-4" onsubmit={submitReplace}>
							<div>
								<h4 class="text-sm font-semibold text-text">Replace File in Place</h4>
								<p class="text-xs text-text-muted">
									Uploads a new binary while preserving this asset’s ID, public token, and all page references. Previous versions remain available for rollback.
								</p>
							</div>

							<div class="grid gap-3 sm:grid-cols-2">
								<label class="block space-y-1 text-xs">
									<span class="text-text-muted">Replacement File</span>
									<input
										bind:this={replaceFileInput}
										type="file"
										class="admin-input admin-input-sm text-xs"
										required
										onchange={(e) => {
											replaceFile = e.currentTarget.files?.[0] ?? null;
										}}
									/>
								</label>

								<label class="block space-y-1 text-xs">
									<span class="text-text-muted">Change Note (optional)</span>
									<input
										type="text"
										class="admin-input admin-input-sm text-xs"
										bind:value={replaceChangeNote}
										placeholder="Color-corrected hero crop"
									/>
								</label>
							</div>

							<div class="flex flex-wrap items-center justify-between gap-2">
								<label class="inline-flex cursor-pointer items-center gap-2 text-xs text-text-muted">
									<input type="checkbox" bind:checked={replaceAllowMimeChange} />
									<span>Allow MIME type change (e.g., PNG → WebP)</span>
								</label>

								<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" disabled={!replaceFile || replacing}>
									<Icon name="upload" size={13} />
									<span>{replacing ? 'Replacing…' : 'Upload New Version'}</span>
								</button>
							</div>
						</form>
					{/if}

					<div class="space-y-2">
						<div class="flex items-center justify-between">
							<h4 class="text-sm font-semibold text-text">
								Version History {#if currentVersionNumber}(Current: v{currentVersionNumber}){/if}
							</h4>
							<button
								type="button"
								class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
								disabled={loadingVersions}
								onclick={() => void loadVersions(currentAsset!.id)}
							>
								<Icon name="refresh-cw" size={13} />
								<span>Refresh</span>
							</button>
						</div>

						{#if versionsError}
							<p class="rounded-lg border border-danger/40 bg-danger-subtle p-3 text-sm text-danger" role="alert">{versionsError}</p>
						{/if}

						{#if loadingVersions}
							<LoadingState label="Loading version history…" />
						{:else if versions.length === 0}
							{#if !versionsError}
								<p class="rounded-lg border border-border bg-bg p-4 text-center text-xs text-text-muted">
									No archived versions recorded yet.
								</p>
							{/if}
						{:else}
							<div class="overflow-x-auto rounded-lg border border-border">
								<table class="admin-table">
									<thead>
										<tr class="border-b border-border">
											<th>Version</th>
											<th>File / Size</th>
											<th>Change Note</th>
											<th>Created</th>
											<th class="text-right">Actions</th>
										</tr>
									</thead>
									<tbody>
										{#each versions as ver (ver.versionNumber)}
											{@const isCurrent = Boolean(ver.isCurrent || ver.versionNumber === currentVersionNumber)}
											<tr>
												<td>
													<div class="flex items-center gap-1.5">
														<span class="font-mono font-semibold text-text">v{ver.versionNumber}</span>
														{#if isCurrent}
															<Badge status="success" outline>Current</Badge>
														{/if}
													</div>
												</td>
												<td class="text-xs">
													<div class="text-text">{ver.originalName || ver.mimeType || 'Binary'}</div>
													<div class="text-text-muted">{ver.size ? formatBytes(ver.size) : '—'}</div>
												</td>
												<td class="text-xs text-text-muted">{ver.changeNote || '—'}</td>
												<td class="text-xs text-text-muted">
													<div>{ver.createdAt || '—'}</div>
													{#if ver.createdBy}<div>{ver.createdBy}</div>{/if}
												</td>
												<td class="text-right">
													<div class="inline-flex items-center justify-end gap-1.5">
														{#if ver.downloadUrl || ver.url}
															<button
																type="button"
																class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
																disabled={downloadingVersion !== null}
																onclick={() => void downloadVersion(ver)}
															>
																<Icon name="download" size={12} />
																<span>{downloadingVersion === ver.versionNumber ? 'Downloading…' : 'Download'}</span>
															</button>
														{/if}
														{#if !isCurrent}
															<button
																type="button"
																class="admin-btn admin-btn-secondary admin-btn-sm gap-1"
																disabled={rollingBackVersion !== null}
																onclick={() => void rollbackVersion(ver.versionNumber)}
															>
																<Icon name="undo-2" size={12} />
																<span>{rollingBackVersion === ver.versionNumber ? 'Rolling back…' : 'Rollback'}</span>
															</button>
														{/if}
													</div>
												</td>
											</tr>
										{/each}
									</tbody>
								</table>
							</div>
						{/if}
					</div>
				</div>

			<!-- ==================== TAB 6: RIGHTS & LICENSING ==================== -->
			{:else if activeTab === 'rights'}
				<form class="space-y-4" onsubmit={saveRights}>
					<div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-bg px-3.5 py-2.5 text-sm">
						<span class="font-medium text-text">License Expiration Status</span>
						{#if licenseExpiryStatus === 'expired'}
							<Badge status="danger">Expired</Badge>
						{:else if licenseExpiryStatus === 'expiring'}
							<Badge status="warning">Expiring within {expiryWarningDays} days</Badge>
						{:else if licenseExpiryStatus === 'active'}
							<Badge status="success">Active</Badge>
						{:else}
							<Badge status="primary" outline>No expiration date</Badge>
						{/if}
					</div>

					<div class="grid gap-3 sm:grid-cols-2">
						<label class="block space-y-1 text-sm">
							<span class="text-text-muted">License Type</span>
							<input
								class="admin-input admin-input-md"
								list="dam-license-presets"
								bind:value={licenseType}
								oninput={() => (formDirty = true)}
								maxlength={64}
								placeholder="e.g. CC BY 4.0, Royalty-Free, All Rights Reserved"
							/>
							<datalist id="dam-license-presets">
								{#each LICENSE_PRESETS as preset (preset)}
									<option value={preset}></option>
								{/each}
							</datalist>
						</label>

						<label class="block space-y-1 text-sm">
							<span class="text-text-muted">Copyright / Rights Holder</span>
							<input
								class="admin-input admin-input-md"
								bind:value={licenseHolder}
								oninput={() => (formDirty = true)}
								maxlength={255}
								placeholder="Photographer, agency, or organization"
							/>
						</label>

						<label class="block space-y-1 text-sm">
							<span class="text-text-muted">License / Attribution URL</span>
							<input
								class="admin-input admin-input-md"
								type="url"
								bind:value={licenseUrl}
								oninput={() => (formDirty = true)}
								placeholder="https://creativecommons.org/licenses/by/4.0/"
							/>
						</label>

						<div class="grid grid-cols-2 gap-2">
							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">Expires On</span>
								<input
									class="admin-input admin-input-md"
									type="date"
									bind:value={licenseExpiresAt}
									oninput={() => (formDirty = true)}
								/>
							</label>

							<label class="block space-y-1 text-sm">
								<span class="text-text-muted">On Expiration</span>
								<select
									class="admin-input admin-input-md"
									bind:value={licenseExpiryAction}
									onchange={() => (formDirty = true)}
								>
									<option value="alert">Alert admins</option>
									<option value="make_private">Auto-make private</option>
								</select>
							</label>
						</div>
					</div>

					<label class="block space-y-1 text-sm">
						<span class="text-text-muted">Usage Restrictions & Notes</span>
						<textarea
							rows="3"
							class="admin-input admin-input-md"
							bind:value={licenseNotes}
							oninput={() => (formDirty = true)}
							placeholder="Territory restrictions, model release IDs, renewal contact…"
						></textarea>
					</label>

					<div class="flex justify-end">
						<button type="submit" class="admin-btn admin-btn-primary admin-btn-sm gap-1.5" disabled={saving}>
							<Icon name="save" size={14} />
							<span>{saving ? 'Saving…' : 'Save Rights & Licensing'}</span>
						</button>
					</div>
				</form>
			{/if}
		</div>
	</ModalDialog>
{/if}
