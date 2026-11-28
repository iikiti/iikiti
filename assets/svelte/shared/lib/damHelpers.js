/**
 * Pure JavaScript helpers for the Modern DAM Workspace UI.
 *
 * Intentionally dependency-free (zero Svelte or DOM imports) so Bun unit tests
 * can import and verify folder trees, query building, multi-selection,
 * multi-file upload queueing, hashing, duration formatting, and license expiry.
 */

const DAY_MS = 24 * 60 * 60 * 1000;
const EXPIRING_SOON_DAYS = 30;

/**
 * @typedef {object} FolderRecord
 * @property {string|number} id
 * @property {string} name
 * @property {string|number|null} [parentId]
 * @property {string|number|null} [parent_id]
 * @property {number} [assetCount]
 * @property {string} [slug]
 */

/**
 * @typedef {FolderRecord & { children: FolderTreeNode[], depth: number }} FolderTreeNode
 */

/**
 * @param {unknown} value
 * @returns {string|null}
 */
function normalizeId(value) {
	if (value === null || value === undefined || value === '') return null;
	return String(value);
}

/**
 * Builds a nested, alphabetically sorted folder tree from a flat list of folders.
 * Safely promotes orphan folders and breaks parent cycles to root level.
 *
 * @param {FolderRecord[] | null | undefined} flatFolders
 * @returns {FolderTreeNode[]}
 */
export function buildFolderTree(flatFolders) {
	if (!Array.isArray(flatFolders) || flatFolders.length === 0) {
		return [];
	}

	/** @type {Map<string, FolderTreeNode>} */
	const nodeMap = new Map();
	/** @type {Map<string, string|null>} */
	const parentMap = new Map();

	for (const folder of flatFolders) {
		if (!folder || typeof folder !== 'object') continue;
		const id = normalizeId(folder.id);
		if (id === null) continue;
		const rawParent = folder.parentId !== undefined ? folder.parentId : folder.parent_id;
		const parentId = normalizeId(rawParent);
		nodeMap.set(id, {
			...folder,
			parentId,
			children: [],
			depth: 0,
		});
		parentMap.set(id, parentId);
	}

	/**
	 * Checks whether attaching `childId` under `candidateParentId` creates a cycle.
	 * @param {string} childId
	 * @param {string} candidateParentId
	 * @returns {boolean}
	 */
	const createsCycle = (childId, candidateParentId) => {
		let cursor = /** @type {string|null} */ (candidateParentId);
		const visited = new Set([childId]);
		while (cursor !== null) {
			if (visited.has(cursor)) return true;
			visited.add(cursor);
			cursor = parentMap.get(cursor) ?? null;
		}
		return false;
	};

	/** @type {FolderTreeNode[]} */
	const roots = [];

	for (const [id, node] of nodeMap.entries()) {
		const parentId = parentMap.get(id) ?? null;
		if (parentId !== null && parentId !== id && nodeMap.has(parentId) && !createsCycle(id, parentId)) {
			nodeMap.get(parentId)?.children.push(node);
		} else {
			roots.push(node);
		}
	}

	/**
	 * @param {FolderTreeNode[]} list
	 * @param {number} depth
	 */
	const sortAndDepth = (list, depth) => {
		list.sort((a, b) => String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, { sensitivity: 'base' }));
		for (const item of list) {
			item.depth = depth;
			if (item.children.length > 0) {
				sortAndDepth(item.children, depth + 1);
			}
		}
	};

	sortAndDepth(roots, 0);
	return roots;
}

/**
 * Flattens a nested folder tree in display order with preserved depth.
 * Useful for `<select>` dropdowns with indentation.
 *
 * @param {FolderTreeNode[] | null | undefined} tree
 * @returns {FolderTreeNode[]}
 */
export function flattenFolderTree(tree) {
	if (!Array.isArray(tree) || tree.length === 0) return [];
	/** @type {FolderTreeNode[]} */
	const result = [];
	/** @param {FolderTreeNode[]} nodes */
	const walk = (nodes) => {
		for (const node of nodes) {
			result.push(node);
			if (Array.isArray(node.children) && node.children.length > 0) {
				walk(node.children);
			}
		}
	};
	walk(tree);
	return result;
}

/**
 * Returns true if `targetId` is a descendant of `sourceId` in the folder hierarchy.
 * Cycle-safe and accepts either a flat folder list or nested folder nodes.
 *
 * @param {FolderRecord[] | null | undefined} folders
 * @param {string|number|null|undefined} sourceId
 * @param {string|number|null|undefined} targetId
 * @returns {boolean}
 */
export function isDescendantFolder(folders, sourceId, targetId) {
	const src = normalizeId(sourceId);
	const tgt = normalizeId(targetId);
	if (src === null || tgt === null || src === tgt || !Array.isArray(folders)) {
		return false;
	}

	/** @type {Map<string, string|null>} */
	const parentMap = new Map();
	/** @param {any[]} list @param {string|null} inheritedParent */
	const indexFolders = (list, inheritedParent = null) => {
		for (const item of list) {
			if (!item || typeof item !== 'object') continue;
			const id = normalizeId(item.id);
			if (id === null) continue;
			const rawParent = item.parentId !== undefined ? item.parentId : item.parent_id;
			const resolvedParent = rawParent !== undefined ? normalizeId(rawParent) : inheritedParent;
			parentMap.set(id, resolvedParent);
			if (Array.isArray(item.children) && item.children.length > 0) {
				indexFolders(item.children, id);
			}
		}
	};
	indexFolders(folders, null);

	let cursor = parentMap.get(tgt) ?? null;
	const visited = new Set([tgt]);
	while (cursor !== null) {
		if (cursor === src) return true;
		if (visited.has(cursor)) return false;
		visited.add(cursor);
		cursor = parentMap.get(cursor) ?? null;
	}

	return false;
}

/**
 * Validates whether `sourceId` can be moved inside `targetId` (null = root).
 * Prevents dropping a folder onto itself or into any of its descendants.
 *
 * @param {FolderRecord[] | null | undefined} folders
 * @param {string|number|null|undefined} sourceId
 * @param {string|number|null|undefined} targetId
 * @returns {boolean}
 */
export function canMoveFolder(folders, sourceId, targetId) {
	const src = normalizeId(sourceId);
	if (src === null) return false;
	const tgt = normalizeId(targetId);
	if (tgt === null) return true;
	if (src === tgt) return false;
	return !isDescendantFolder(folders, src, tgt);
}

/**
 * Builds a clean `URLSearchParams` query string for `GET /api/admin/assets`,
 * omitting empty, null, undefined, and default filter values.
 *
 * @param {Record<string, any> | null | undefined} filters
 * @returns {string}
 */
export function buildAssetQueryParams(filters) {
	if (!filters || typeof filters !== 'object') return '';

	const params = new URLSearchParams();

	const pageNum = Number(filters.page);
	if (Number.isFinite(pageNum) && Math.floor(pageNum) > 1) {
		params.set('page', String(Math.floor(pageNum)));
	}

	const perPageNum = Number(filters.itemsPerPage);
	if (Number.isFinite(perPageNum) && Math.floor(perPageNum) > 0 && Math.floor(perPageNum) !== 25) {
		params.set('itemsPerPage', String(Math.floor(perPageNum)));
	}

	const q = typeof filters.q === 'string' ? filters.q.trim() : '';
	if (q) params.set('q', q);

	const mimeCategory = typeof filters.mimeCategory === 'string' ? filters.mimeCategory.trim() : '';
	if (mimeCategory && mimeCategory !== 'all') {
		params.set('mimeCategory', mimeCategory);
	}

	const mimeType = typeof filters.mimeType === 'string' ? filters.mimeType.trim() : '';
	if (mimeType) params.set('mimeType', mimeType);

	const siteId = normalizeId(filters.siteId);
	if (siteId !== null && siteId !== 'all') {
		params.set('siteId', siteId);
	}

	const visibility = typeof filters.visibility === 'string' ? filters.visibility.trim() : '';
	if (visibility && visibility !== 'all') {
		params.set('visibility', visibility);
	}

	const processingStatus = typeof filters.processingStatus === 'string' ? filters.processingStatus.trim() : '';
	if (processingStatus && processingStatus !== 'all') {
		params.set('processingStatus', processingStatus);
	}

	const rawFolderId = normalizeId(filters.folderId);
	if (rawFolderId !== null && rawFolderId !== 'all') {
		const folderId = rawFolderId === 'none' ? 'unfiled' : rawFolderId;
		params.set('folderId', folderId);
		if (filters.includeSubfolders && folderId !== 'unfiled') {
			params.set('includeSubfolders', '1');
		}
	}

	const collectionId = normalizeId(filters.collectionId);
	if (collectionId !== null && collectionId !== 'all') {
		params.set('collectionId', collectionId);
	}

	if (Array.isArray(filters.tagIds)) {
		const cleaned = filters.tagIds
			.map((id) => normalizeId(id))
			.filter((id) => id !== null);
		if (cleaned.length > 0) {
			params.set('tagIds', cleaned.join(','));
		}
	} else if (typeof filters.tagIds === 'string' && filters.tagIds.trim() !== '') {
		const cleaned = filters.tagIds
			.split(',')
			.map((id) => id.trim())
			.filter(Boolean);
		if (cleaned.length > 0) {
			params.set('tagIds', cleaned.join(','));
		}
	}

	const licenseStatus = typeof filters.licenseStatus === 'string' ? filters.licenseStatus.trim() : '';
	if (licenseStatus && licenseStatus !== 'all') {
		params.set('licenseStatus', licenseStatus);
	}

	const sort = typeof filters.sort === 'string' ? filters.sort.trim() : '';
	if (sort && sort !== 'newest' && sort !== 'created_desc') {
		params.set('sort', sort);
	}

	const locale = typeof filters.locale === 'string' ? filters.locale.trim() : '';
	if (locale) params.set('locale', locale);

	return params.toString();
}

/**
 * Returns a new Set with `id` toggled on or off (normalized as string).
 *
 * @param {Iterable<string|number> | null | undefined} selectedSet
 * @param {string|number} id
 * @returns {Set<string>}
 */
export function toggleSelection(selectedSet, id) {
	const next = new Set(Array.from(selectedSet ?? [], (item) => String(item)));
	const key = String(id);
	if (next.has(key)) {
		next.delete(key);
	} else {
		next.add(key);
	}
	return next;
}

/**
 * Toggles selection for all `visibleIds`. If every visible ID is already selected,
 * removes them from the selection; otherwise adds all of them.
 *
 * @param {Iterable<string|number> | null | undefined} selectedSet
 * @param {Array<string|number>} visibleIds
 * @returns {Set<string>}
 */
export function toggleSelectAll(selectedSet, visibleIds) {
	const next = new Set(Array.from(selectedSet ?? [], (item) => String(item)));
	const normalizedVisible = Array.isArray(visibleIds) ? visibleIds.map((id) => String(id)) : [];
	if (normalizedVisible.length === 0) {
		return next;
	}

	const allSelected = normalizedVisible.every((id) => next.has(id));
	if (allSelected) {
		for (const id of normalizedVisible) {
			next.delete(id);
		}
	} else {
		for (const id of normalizedVisible) {
			next.add(id);
		}
	}
	return next;
}

/**
 * Computes a contiguous inclusive selection range between `anchorId` and `targetId`
 * in `visibleIds` (supports Shift-click range selection both forwards and backwards).
 *
 * @param {Array<string|number>} visibleIds
 * @param {string|number|null|undefined} anchorId
 * @param {string|number} targetId
 * @returns {Set<string>}
 */
export function createSelectionRange(visibleIds, anchorId, targetId) {
	const list = Array.isArray(visibleIds) ? visibleIds.map((id) => String(id)) : [];
	const targetKey = String(targetId);
	const targetIndex = list.indexOf(targetKey);
	if (targetIndex === -1) {
		return new Set();
	}

	const anchorKey = anchorId !== null && anchorId !== undefined ? String(anchorId) : null;
	const anchorIndex = anchorKey !== null ? list.indexOf(anchorKey) : -1;
	if (anchorIndex === -1) {
		return new Set([targetKey]);
	}

	const start = Math.min(anchorIndex, targetIndex);
	const end = Math.max(anchorIndex, targetIndex);
	return new Set(list.slice(start, end + 1));
}

/**
 * Computes the SHA-256 hex digest of a File/Blob using Web Crypto.
 * Returns `null` gracefully if `crypto.subtle` or `arrayBuffer()` is unavailable.
 *
 * @param {Blob | { arrayBuffer?: () => Promise<ArrayBuffer> } | null | undefined} file
 * @returns {Promise<string|null>}
 */
export async function computeFileSha256(file) {
	try {
		const subtle = globalThis.crypto?.subtle;
		if (!subtle || typeof subtle.digest !== 'function') {
			return null;
		}
		if (!file || typeof file.arrayBuffer !== 'function') {
			return null;
		}
		const buffer = await file.arrayBuffer();
		const hashBuffer = await subtle.digest('SHA-256', buffer);
		const bytes = new Uint8Array(hashBuffer);
		let hex = '';
		for (let i = 0; i < bytes.length; i += 1) {
			hex += bytes[i].toString(16).padStart(2, '0');
		}
		return hex;
	} catch {
		return null;
	}
}

/**
 * @typedef {'queued'|'hashing'|'duplicate_prompt'|'uploading'|'done'|'error'|'cancelled'} UploadStatus
 */

/**
 * @typedef {object} UploadQueueItem
 * @property {string} id
 * @property {any} file
 * @property {string} name
 * @property {string} title
 * @property {number} size
 * @property {string} mimeType
 * @property {UploadStatus} status
 * @property {number} progress
 * @property {string|null} checksum
 * @property {any[]} duplicateMatches
 * @property {'allow'|'reuse'|'skip'|null} duplicatePolicy
 * @property {Record<string, any>} meta
 * @property {any} result
 * @property {string|null} error
 */

/**
 * Pure state machine for multi-file DAM uploads with concurrency limiting,
 * pre-flight SHA-256 hashing & duplicate prompting, progress tracking,
 * cancellation, and retries.
 *
 * @param {object} options
 * @param {number} [options.concurrency=3]
 * @param {(file: any, item: UploadQueueItem) => Promise<string|null>} [options.hashFile]
 * @param {(checksum: string, item: UploadQueueItem) => Promise<{ duplicate?: boolean, matches?: any[] } | null>} [options.checkDuplicate]
 * @param {(item: UploadQueueItem, ctx: { onProgress: (pct: number) => void, signal: AbortSignal }) => Promise<any>} options.uploadFile
 * @param {(snapshot: ReturnType<ReturnType<typeof createUploadQueueManager>['getSnapshot']>) => void} [options.onChange]
 */
export function createUploadQueueManager(options) {
	const concurrency = Math.max(1, Number(options?.concurrency ?? 3) || 3);
	const hashFile = options?.hashFile ?? ((file) => computeFileSha256(file));
	const checkDuplicate = options?.checkDuplicate ?? null;
	const uploadFile = options?.uploadFile;
	const onChange = options?.onChange ?? null;

	if (typeof uploadFile !== 'function') {
		throw new Error('createUploadQueueManager requires an uploadFile function.');
	}

	let nextSeq = 1;
	/** @type {UploadQueueItem[]} */
	let items = [];
	/** @type {Map<string, AbortController>} */
	const controllers = new Map();
	let activeCount = 0;

	function deriveTitle(name) {
		const raw = String(name ?? '').trim();
		if (!raw) return 'Untitled asset';
		const stripped = raw.replace(/\.[^.]+$/, '').trim();
		return stripped || raw;
	}

	function cloneItem(item) {
		return {
			...item,
			duplicateMatches: [...item.duplicateMatches],
			meta: { ...item.meta },
		};
	}

	function getSnapshot() {
		const counts = {
			total: items.length,
			queued: 0,
			hashing: 0,
			duplicate_prompt: 0,
			uploading: 0,
			done: 0,
			error: 0,
			cancelled: 0,
		};

		let totalBytes = 0;
		let weightedLoaded = 0;
		let activeItemsForAvg = 0;
		let sumProgress = 0;

		for (const item of items) {
			counts[item.status] = (counts[item.status] ?? 0) + 1;
			if (item.status === 'cancelled') continue;
			activeItemsForAvg += 1;
			const pct = item.status === 'done' ? 100 : Math.min(100, Math.max(0, item.progress));
			sumProgress += pct;
			const bytes = Number(item.size) > 0 ? Number(item.size) : 0;
			totalBytes += bytes;
			weightedLoaded += bytes * pct;
		}

		let aggregateProgress = 0;
		if (totalBytes > 0) {
			aggregateProgress = Math.round(weightedLoaded / totalBytes);
		} else if (activeItemsForAvg > 0) {
			aggregateProgress = Math.round(sumProgress / activeItemsForAvg);
		}

		const isActive = counts.queued > 0 || counts.hashing > 0 || counts.uploading > 0;

		return {
			items: items.map(cloneItem),
			counts,
			aggregateProgress,
			isActive,
		};
	}

	function emit() {
		if (typeof onChange === 'function') {
			onChange(getSnapshot());
		}
	}

	/** @param {string} id */
	function findItem(id) {
		return items.find((item) => item.id === id) ?? null;
	}

	function pump() {
		while (activeCount < concurrency) {
			const next = items.find((item) => item.status === 'queued');
			if (!next) break;
			activeCount += 1;
			void processItem(next);
		}
	}

	/** @param {UploadQueueItem} item */
	async function processItem(item) {
		const controller = new AbortController();
		controllers.set(item.id, controller);

		try {
			// Skip hashing/duplicate check if user already chose "Upload Anyway"
			if (item.duplicatePolicy !== 'allow') {
				item.status = 'hashing';
				item.error = null;
				emit();

				if (item.checksum === null) {
					item.checksum = await hashFile(item.file, item);
				}

				if (controller.signal.aborted || item.status === 'cancelled') {
					item.status = 'cancelled';
					emit();
					return;
				}

				if (item.checksum && typeof checkDuplicate === 'function') {
					let dupResult = null;
					try {
						dupResult = await checkDuplicate(item.checksum, cloneItem(item));
					} catch {
						// Pre-flight duplicate check is advisory; proceed to upload if unavailable.
						dupResult = null;
					}

					if (controller.signal.aborted || item.status === 'cancelled') {
						item.status = 'cancelled';
						emit();
						return;
					}

					const matches = Array.isArray(dupResult?.matches) ? dupResult.matches : [];
					if (dupResult?.duplicate && matches.length > 0) {
						item.status = 'duplicate_prompt';
						item.duplicateMatches = matches;
						emit();
						return;
					}
				}
			}

			if (controller.signal.aborted || item.status === 'cancelled') {
				item.status = 'cancelled';
				emit();
				return;
			}

			item.status = 'uploading';
			item.progress = Math.max(0, Math.min(99, item.progress));
			item.error = null;
			emit();

			const result = await uploadFile(cloneItem(item), {
				signal: controller.signal,
				onProgress: (pct) => {
					if (item.status !== 'uploading') return;
					const clamped = Math.min(100, Math.max(0, Math.round(Number(pct) || 0)));
					if (clamped !== item.progress) {
						item.progress = clamped;
						emit();
					}
				},
			});

			if (controller.signal.aborted || item.status === 'cancelled') {
				item.status = 'cancelled';
				emit();
				return;
			}

			item.status = 'done';
			item.progress = 100;
			item.result = result ?? null;
			item.error = null;
			emit();
		} catch (cause) {
			if (controller.signal.aborted || item.status === 'cancelled' || cause?.name === 'AbortError') {
				item.status = 'cancelled';
				item.error = null;
			} else {
				item.status = 'error';
				item.error = cause instanceof Error ? cause.message : String(cause ?? 'Upload failed');
			}
			emit();
		} finally {
			controllers.delete(item.id);
			activeCount = Math.max(0, activeCount - 1);
			pump();
		}
	}

	/**
	 * Enqueues one or more files and begins processing up to the concurrency limit.
	 *
	 * @param {Iterable<any>} files
	 * @param {Record<string, any>} [meta]
	 * @returns {UploadQueueItem[]}
	 */
	function enqueue(files, meta = {}) {
		const list = Array.from(files ?? []).filter(Boolean);
		if (list.length === 0) return [];

		/** @type {UploadQueueItem[]} */
		const added = [];
		for (const file of list) {
			const name = String(file.name ?? 'file');
			const item = /** @type {UploadQueueItem} */ ({
				id: `upload-${nextSeq++}`,
				file,
				name,
				title: deriveTitle(name),
				size: Number(file.size ?? 0) || 0,
				mimeType: String(file.type ?? ''),
				status: 'queued',
				progress: 0,
				checksum: null,
				duplicateMatches: [],
				duplicatePolicy: null,
				meta: { ...meta },
				result: null,
				error: null,
			});
			items.push(item);
			added.push(cloneItem(item));
		}

		emit();
		pump();
		return added;
	}

	/**
	 * Resolves a duplicate prompt item with `'reuse'`, `'upload_anyway'`, or `'skip'`.
	 *
	 * @param {string} id
	 * @param {'reuse'|'upload_anyway'|'skip'} decision
	 */
	function resolveDuplicate(id, decision) {
		const item = findItem(id);
		if (!item || item.status !== 'duplicate_prompt') return;

		if (decision === 'reuse') {
			item.duplicatePolicy = 'reuse';
			item.status = 'done';
			item.progress = 100;
			item.result = item.duplicateMatches[0] ?? null;
			item.error = null;
			emit();
			return;
		}

		if (decision === 'upload_anyway') {
			item.duplicatePolicy = 'allow';
			item.status = 'queued';
			item.progress = 0;
			item.error = null;
			emit();
			pump();
			return;
		}

		item.duplicatePolicy = 'skip';
		item.status = 'cancelled';
		item.error = null;
		emit();
	}

	/**
	 * Cancels a queued, hashing, prompted, or uploading item.
	 * @param {string} id
	 */
	function cancel(id) {
		const item = findItem(id);
		if (!item || item.status === 'done' || item.status === 'cancelled') return;

		item.status = 'cancelled';
		item.error = null;
		const controller = controllers.get(id);
		if (controller) {
			controller.abort();
		}
		emit();
		pump();
	}

	/**
	 * Retries an errored or cancelled upload item.
	 * @param {string} id
	 */
	function retry(id) {
		const item = findItem(id);
		if (!item || (item.status !== 'error' && item.status !== 'cancelled')) return;

		item.status = 'queued';
		item.progress = 0;
		item.error = null;
		if (item.duplicatePolicy === 'skip') {
			item.duplicatePolicy = null;
		}
		emit();
		pump();
	}

	/**
	 * Removes an item from the queue, cancelling it first if active.
	 * @param {string} id
	 */
	function remove(id) {
		cancel(id);
		const prevLen = items.length;
		items = items.filter((item) => item.id !== id);
		if (items.length !== prevLen) {
			emit();
		}
	}

	/**
	 * Clears finished (`done` and `cancelled`) items from the queue.
	 */
	function clearCompleted() {
		const prevLen = items.length;
		items = items.filter((item) => item.status !== 'done' && item.status !== 'cancelled');
		if (items.length !== prevLen) {
			emit();
		}
	}

	/**
	 * Updates editable title for an item before upload completes.
	 * @param {string} id
	 * @param {string} title
	 */
	function setItemTitle(id, title) {
		const item = findItem(id);
		if (!item) return;
		item.title = String(title ?? '');
		emit();
	}

	return {
		enqueue,
		resolveDuplicate,
		cancel,
		retry,
		remove,
		clearCompleted,
		setItemTitle,
		getSnapshot,
	};
}

/**
 * Formats a duration in milliseconds as `'M:SS'` or `'H:MM:SS'`.
 *
 * @param {number|string|null|undefined} ms
 * @returns {string}
 */
export function formatDuration(ms) {
	if (ms === null || ms === undefined || ms === '') return '';
	const numeric = Number(ms);
	if (!Number.isFinite(numeric) || numeric < 0) return '';

	const totalSeconds = Math.floor(numeric / 1000);
	const hours = Math.floor(totalSeconds / 3600);
	const minutes = Math.floor((totalSeconds % 3600) / 60);
	const seconds = totalSeconds % 60;

	const secStr = String(seconds).padStart(2, '0');
	if (hours > 0) {
		const minStr = String(minutes).padStart(2, '0');
		return `${hours}:${minStr}:${secStr}`;
	}
	return `${minutes}:${secStr}`;
}

/**
 * Evaluates license expiry status relative to `now`.
 *
 * - `'none'`: no license or no valid `expiresAt`
 * - `'expired'`: `expiresAt <= now`
 * - `'expiring'`: expires within 30 days
 * - `'active'`: expires more than 30 days in the future
 *
 * @param {{ expiresAt?: string | Date | null } | null | undefined} license
 * @param {Date | number | string} [now]
 * @returns {'none'|'active'|'expiring'|'expired'}
 */
export function getExpiryStatus(license, now = new Date()) {
	if (!license || typeof license !== 'object' || !license.expiresAt) {
		return 'none';
	}

	const expiresDate = license.expiresAt instanceof Date ? license.expiresAt : new Date(license.expiresAt);
	const expiresMs = expiresDate.getTime();
	if (!Number.isFinite(expiresMs)) {
		return 'none';
	}

	const nowDate = now instanceof Date ? now : new Date(now);
	const nowMs = nowDate.getTime();
	if (!Number.isFinite(nowMs)) {
		return 'none';
	}

	if (expiresMs <= nowMs) {
		return 'expired';
	}
	if (expiresMs - nowMs <= EXPIRING_SOON_DAYS * DAY_MS) {
		return 'expiring';
	}
	return 'active';
}

/**
 * Produces a concise uppercase format badge label from a MIME type or variant list
 * (e.g. `'AVIF'`, `'WEBP'`, `'JPEG'`, `'MP4'`, `'PDF'`).
 *
 * @param {string|null|undefined} mimeType
 * @param {Array<{ format?: string|null, mimeType?: string|null }> | null | undefined} [variants]
 * @returns {string}
 */
export function shortMimeBadge(mimeType, variants = null) {
	if (Array.isArray(variants) && variants.length > 0) {
		const formats = variants
			.map((v) => String(v?.format ?? '').toLowerCase())
			.filter(Boolean);
		if (formats.includes('avif')) return 'AVIF';
		if (formats.includes('webp')) return 'WebP';
	}

	if (typeof mimeType !== 'string' || !mimeType.includes('/')) {
		return '';
	}
	const sub = (mimeType.split('/')[1] ?? '').split(';')[0].split('+')[0].trim().toLowerCase();
	if (!sub) return '';
	if (sub === 'jpeg') return 'JPG';
	if (sub === 'webp') return 'WebP';
	if (sub === 'avif') return 'AVIF';
	if (sub === 'svg') return 'SVG';
	if (sub === 'mpeg') return 'MP3';
	if (sub === 'quicktime') return 'MOV';
	if (sub === 'octet-stream') return 'FILE';
	return sub.toUpperCase().slice(0, 6);
}
