import { describe, expect, test } from 'bun:test';
import {
	buildAssetQueryParams,
	buildFolderTree,
	canMoveFolder,
	computeFileSha256,
	createSelectionRange,
	createUploadQueueManager,
	flattenFolderTree,
	formatDuration,
	getExpiryStatus,
	isDescendantFolder,
	shortMimeBadge,
	toggleSelectAll,
	toggleSelection,
} from '../../assets/svelte/shared/lib/damHelpers.js';

describe('buildFolderTree & flattenFolderTree', () => {
	test('builds alphabetically sorted nested tree with depths', () => {
		const tree = buildFolderTree([
			{ id: 3, name: 'Press Releases', parentId: 1 },
			{ id: 2, name: 'Brand', parentId: null },
			{ id: 1, name: 'Marketing', parentId: null },
			{ id: 4, name: 'Campaigns', parentId: 1 },
			{ id: 5, name: '2026 Launch', parentId: 4 },
		]);

		expect(tree.map((n) => n.name)).toEqual(['Brand', 'Marketing']);
		expect(tree[0].depth).toBe(0);
		expect(tree[1].depth).toBe(0);
		expect(tree[1].children.map((n) => n.name)).toEqual(['Campaigns', 'Press Releases']);
		expect(tree[1].children[0].depth).toBe(1);
		expect(tree[1].children[0].children.map((n) => n.name)).toEqual(['2026 Launch']);
		expect(tree[1].children[0].children[0].depth).toBe(2);

		const flat = flattenFolderTree(tree);
		expect(flat.map((n) => `${n.depth}:${n.name}`)).toEqual([
			'0:Brand',
			'0:Marketing',
			'1:Campaigns',
			'2:2026 Launch',
			'1:Press Releases',
		]);
	});

	test('promotes orphan folders and safely breaks cycles', () => {
		const tree = buildFolderTree([
			{ id: 'orphan', name: 'Orphan', parentId: 'missing' },
			{ id: 'self', name: 'Self Loop', parentId: 'self' },
			{ id: 'a', name: 'Alpha', parentId: 'b' },
			{ id: 'b', name: 'Beta', parentId: 'a' },
		]);

		const names = tree.map((n) => n.name);
		expect(names).toContain('Orphan');
		expect(names).toContain('Self Loop');
		// Both cyclic nodes are safely promoted without infinite recursion
		expect(names).toContain('Alpha');
		expect(names).toContain('Beta');
	});

	test('handles empty and invalid inputs', () => {
		expect(buildFolderTree([])).toEqual([]);
		expect(buildFolderTree(null)).toEqual([]);
		expect(buildFolderTree(undefined)).toEqual([]);
	});
});

describe('isDescendantFolder & canMoveFolder', () => {
	const folders = [
		{ id: 1, name: 'Root', parentId: null },
		{ id: 2, name: 'Child', parentId: 1 },
		{ id: 3, name: 'Grandchild', parentId: 2 },
		{ id: 4, name: 'Sibling', parentId: null },
	];

	test('detects direct and nested descendants accurately', () => {
		expect(isDescendantFolder(folders, 1, 2)).toBe(true);
		expect(isDescendantFolder(folders, 1, 3)).toBe(true);
		expect(isDescendantFolder(folders, 2, 3)).toBe(true);
		expect(isDescendantFolder(folders, 3, 1)).toBe(false);
		expect(isDescendantFolder(folders, 1, 4)).toBe(false);
		expect(isDescendantFolder(folders, 1, 1)).toBe(false);
		expect(isDescendantFolder(folders, '1', '3')).toBe(true);
	});

	test('handles cyclic folder graphs without infinite loop', () => {
		const cyclic = [
			{ id: 'x', name: 'X', parentId: 'y' },
			{ id: 'y', name: 'Y', parentId: 'x' },
			{ id: 'z', name: 'Z', parentId: null },
		];
		expect(isDescendantFolder(cyclic, 'z', 'x')).toBe(false);
		expect(isDescendantFolder(cyclic, 'x', 'y')).toBe(true);
	});

	test('canMoveFolder blocks self and descendant drops but allows root and sibling drops', () => {
		expect(canMoveFolder(folders, 1, 1)).toBe(false);
		expect(canMoveFolder(folders, 1, 2)).toBe(false);
		expect(canMoveFolder(folders, 1, 3)).toBe(false);
		expect(canMoveFolder(folders, 1, 4)).toBe(true);
		expect(canMoveFolder(folders, 3, null)).toBe(true);
		expect(canMoveFolder(folders, 3, 4)).toBe(true);
	});
});

describe('buildAssetQueryParams', () => {
	test('omits empty, null, undefined, and default values', () => {
		const query = buildAssetQueryParams({
			page: 1,
			itemsPerPage: 25,
			q: '   ',
			mimeCategory: 'all',
			mimeType: '',
			siteId: '',
			visibility: 'all',
			processingStatus: 'all',
			folderId: null,
			includeSubfolders: true,
			collectionId: undefined,
			tagIds: [],
			licenseStatus: 'all',
			sort: 'newest',
			locale: '',
		});
		expect(query).toBe('');
	});

	test('builds clean query string for populated filters', () => {
		const query = buildAssetQueryParams({
			page: 3,
			itemsPerPage: 50,
			q: '  hero banner  ',
			mimeCategory: 'image',
			siteId: 4,
			visibility: 'public',
			processingStatus: 'failed',
			folderId: 12,
			includeSubfolders: true,
			collectionId: 9,
			tagIds: [1, '2', ''],
			licenseStatus: 'expiring',
			sort: 'size_desc',
			locale: 'en-US',
		});

		const parsed = new URLSearchParams(query);
		expect(parsed.get('page')).toBe('3');
		expect(parsed.get('itemsPerPage')).toBe('50');
		expect(parsed.get('q')).toBe('hero banner');
		expect(parsed.get('mimeCategory')).toBe('image');
		expect(parsed.get('siteId')).toBe('4');
		expect(parsed.get('visibility')).toBe('public');
		expect(parsed.get('processingStatus')).toBe('failed');
		expect(parsed.get('folderId')).toBe('12');
		expect(parsed.get('includeSubfolders')).toBe('1');
		expect(parsed.get('collectionId')).toBe('9');
		expect(parsed.get('tagIds')).toBe('1,2');
		expect(parsed.get('licenseStatus')).toBe('expiring');
		expect(parsed.get('sort')).toBe('size_desc');
		expect(parsed.get('locale')).toBe('en-US');
	});

	test('does not include includeSubfolders for unfiled folder view and maps none to unfiled', () => {
		const query = buildAssetQueryParams({
			folderId: 'unfiled',
			includeSubfolders: true,
		});
		expect(query).toBe('folderId=unfiled');

		const legacyNoneQuery = buildAssetQueryParams({
			folderId: 'none',
			includeSubfolders: true,
		});
		expect(legacyNoneQuery).toBe('folderId=unfiled');
	});

	test('preserves expiring_or_expired licenseStatus filter', () => {
		const query = buildAssetQueryParams({
			licenseStatus: 'expiring_or_expired',
		});
		expect(query).toBe('licenseStatus=expiring_or_expired');
	});
});

describe('selection helpers', () => {
	test('toggleSelection returns a new Set without mutating input', () => {
		const initial = new Set(['1', '2']);
		const added = toggleSelection(initial, 3);
		expect(Array.from(initial)).toEqual(['1', '2']);
		expect(Array.from(added)).toEqual(['1', '2', '3']);

		const removed = toggleSelection(added, '2');
		expect(Array.from(removed)).toEqual(['1', '3']);
	});

	test('toggleSelectAll selects all visible IDs or clears them when all are selected', () => {
		const visible = ['10', '20', '30'];
		const step1 = toggleSelectAll(new Set(['99', '10']), visible);
		expect(Array.from(step1).sort()).toEqual(['10', '20', '30', '99']);

		const step2 = toggleSelectAll(step1, visible);
		expect(Array.from(step2)).toEqual(['99']);
	});

	test('createSelectionRange supports forward, backward, and missing anchor ranges', () => {
		const visible = ['a', 'b', 'c', 'd', 'e'];
		expect(Array.from(createSelectionRange(visible, 'b', 'd'))).toEqual(['b', 'c', 'd']);
		expect(Array.from(createSelectionRange(visible, 'e', 'c'))).toEqual(['c', 'd', 'e']);
		expect(Array.from(createSelectionRange(visible, 'missing', 'c'))).toEqual(['c']);
		expect(Array.from(createSelectionRange(visible, 'a', 'missing'))).toEqual([]);
	});
});

describe('computeFileSha256', () => {
	test('computes accurate SHA-256 hex digest for a Blob', async () => {
		const blob = new Blob(['hello world'], { type: 'text/plain' });
		const digest = await computeFileSha256(blob);
		expect(digest).toBe('b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9');
	});

	test('falls back to null gracefully when input lacks arrayBuffer', async () => {
		expect(await computeFileSha256(null)).toBeNull();
		expect(await computeFileSha256({})).toBeNull();
	});
});

describe('createUploadQueueManager', () => {
	const flush = (ms = 10) => new Promise((r) => setTimeout(r, ms));

	test('enforces concurrency limit and tracks per-file and aggregate progress', async () => {
		let currentActive = 0;
		let maxActive = 0;
		const resolvers = [];

		const queue = createUploadQueueManager({
			concurrency: 2,
			hashFile: async (file) => `hash-${file.name}`,
			uploadFile: (item, { onProgress }) => {
				currentActive += 1;
				maxActive = Math.max(maxActive, currentActive);
				onProgress(50);
				return new Promise((resolve) => {
					resolvers.push(() => {
						currentActive -= 1;
						onProgress(100);
						resolve({ id: item.name });
					});
				});
			},
		});

		queue.enqueue([
			{ name: 'a.jpg', size: 100, type: 'image/jpeg' },
			{ name: 'b.jpg', size: 100, type: 'image/jpeg' },
			{ name: 'c.jpg', size: 200, type: 'image/jpeg' },
		]);

		await flush();
		expect(maxActive).toBe(2);
		let snap = queue.getSnapshot();
		expect(snap.counts.uploading).toBe(2);
		expect(snap.counts.queued).toBe(1);
		// (100*50 + 100*50 + 200*0) / 400 = 25%
		expect(snap.aggregateProgress).toBe(25);

		// Finish first two uploads
		resolvers.shift()();
		resolvers.shift()();
		await flush();

		snap = queue.getSnapshot();
		expect(snap.counts.done).toBe(2);
		expect(snap.counts.uploading).toBe(1);
		// (100*100 + 100*100 + 200*50) / 400 = 75%
		expect(snap.aggregateProgress).toBe(75);

		resolvers.shift()();
		await flush();

		snap = queue.getSnapshot();
		expect(snap.counts.done).toBe(3);
		expect(snap.aggregateProgress).toBe(100);
		expect(snap.isActive).toBe(false);
	});

	test('handles duplicate_prompt transitions: reuse, upload_anyway, and skip', async () => {
		const uploadedNames = [];
		const queue = createUploadQueueManager({
			concurrency: 3,
			hashFile: async (file) => `sha-${file.name}`,
			checkDuplicate: async (checksum) => ({
				duplicate: true,
				matches: [{ id: 77, title: `Existing ${checksum}` }],
			}),
			uploadFile: async (item) => {
				uploadedNames.push({ name: item.name, policy: item.duplicatePolicy });
				return { id: 999, title: item.title };
			},
		});

		const added = queue.enqueue([
			{ name: 'dup1.png', size: 100, type: 'image/png' },
			{ name: 'dup2.png', size: 100, type: 'image/png' },
			{ name: 'dup3.png', size: 100, type: 'image/png' },
		]);

		await flush();
		let snap = queue.getSnapshot();
		expect(snap.counts.duplicate_prompt).toBe(3);
		expect(uploadedNames).toHaveLength(0);

		queue.resolveDuplicate(added[0].id, 'reuse');
		queue.resolveDuplicate(added[1].id, 'upload_anyway');
		queue.resolveDuplicate(added[2].id, 'skip');

		await flush();
		snap = queue.getSnapshot();
		expect(snap.items[0].status).toBe('done');
		expect(snap.items[0].result).toEqual({ id: 77, title: 'Existing sha-dup1.png' });

		expect(snap.items[1].status).toBe('done');
		expect(uploadedNames).toEqual([{ name: 'dup2.png', policy: 'allow' }]);

		expect(snap.items[2].status).toBe('cancelled');
		expect(snap.items[2].duplicatePolicy).toBe('skip');
	});

	test('supports error retry, cancellation, and clearCompleted', async () => {
		let attempts = 0;
		let aborted = false;

		const queue = createUploadQueueManager({
			concurrency: 2,
			hashFile: async () => null,
			uploadFile: (item, { signal }) => {
				if (item.name === 'flaky.png') {
					attempts += 1;
					if (attempts === 1) {
						return Promise.reject(new Error('Server timeout'));
					}
					return Promise.resolve({ id: 101 });
				}
				return new Promise((_, reject) => {
					signal.addEventListener('abort', () => {
						aborted = true;
						const err = new Error('Aborted');
						err.name = 'AbortError';
						reject(err);
					});
				});
			},
		});

		const added = queue.enqueue([
			{ name: 'flaky.png', size: 50, type: 'image/png' },
			{ name: 'slow.mp4', size: 500, type: 'video/mp4' },
		]);

		await flush();
		let snap = queue.getSnapshot();
		expect(snap.items[0].status).toBe('error');
		expect(snap.items[0].error).toBe('Server timeout');
		expect(snap.items[1].status).toBe('uploading');

		queue.cancel(added[1].id);
		queue.retry(added[0].id);
		await flush();

		snap = queue.getSnapshot();
		expect(aborted).toBe(true);
		expect(snap.items[0].status).toBe('done');
		expect(snap.items[1].status).toBe('cancelled');

		queue.clearCompleted();
		expect(queue.getSnapshot().items).toHaveLength(0);
	});

	test('transitions to duplicate_prompt on HTTP 409 duplicate conflict fallback during upload (body and data shapes)', async () => {
		let attempt = 0;
		const queue = createUploadQueueManager({
			concurrency: 2,
			// Simulates non-HTTPS origin where crypto.subtle is unavailable
			hashFile: async () => null,
			uploadFile: async (item) => {
				attempt += 1;
				if (item.name === 'same-batch-body.png' && item.duplicatePolicy !== 'allow') {
					const err = new Error('409: Duplicate asset');
					err.status = 409;
					err.body = { duplicate: true, matches: [{ id: 501, title: 'Existing Body Match' }] };
					throw err;
				}
				if (item.name === 'same-batch-data.png' && item.duplicatePolicy !== 'allow') {
					const err = new Error('409: Duplicate asset');
					err.status = 409;
					err.data = { duplicate: true, matches: [{ id: 502, title: 'Existing Data Match' }] };
					throw err;
				}
				return { id: 600 + attempt, title: item.title };
			},
		});

		const added = queue.enqueue([
			{ name: 'same-batch-body.png', size: 120, type: 'image/png' },
			{ name: 'same-batch-data.png', size: 120, type: 'image/png' },
		]);

		await flush();
		let snap = queue.getSnapshot();
		expect(snap.counts.duplicate_prompt).toBe(2);
		expect(snap.items[0].status).toBe('duplicate_prompt');
		expect(snap.items[0].duplicateMatches).toEqual([{ id: 501, title: 'Existing Body Match' }]);
		expect(snap.items[0].error).toBeNull();
		expect(snap.items[1].status).toBe('duplicate_prompt');
		expect(snap.items[1].duplicateMatches).toEqual([{ id: 502, title: 'Existing Data Match' }]);

		queue.resolveDuplicate(added[0].id, 'upload_anyway');
		queue.resolveDuplicate(added[1].id, 'reuse');

		await flush();
		snap = queue.getSnapshot();
		expect(snap.items[0].status).toBe('done');
		expect(snap.items[0].duplicatePolicy).toBe('allow');
		expect(snap.items[1].status).toBe('done');
		expect(snap.items[1].result).toEqual({ id: 502, title: 'Existing Data Match' });
	});
});

describe('formatDuration', () => {
	test('formats milliseconds into M:SS and H:MM:SS', () => {
		expect(formatDuration(0)).toBe('0:00');
		expect(formatDuration(5000)).toBe('0:05');
		expect(formatDuration(84000)).toBe('1:24');
		expect(formatDuration(3725000)).toBe('1:02:05');
		expect(formatDuration(-100)).toBe('');
		expect(formatDuration(null)).toBe('');
		expect(formatDuration(undefined)).toBe('');
		expect(formatDuration('invalid')).toBe('');
	});
});

describe('getExpiryStatus', () => {
	const now = new Date('2026-06-01T00:00:00Z');

	test('uses 14-day default warning window for none, active, expiring, and expired', () => {
		expect(getExpiryStatus(null, now)).toBe('none');
		expect(getExpiryStatus({}, now)).toBe('none');
		expect(getExpiryStatus({ expiresAt: 'not-a-date' }, now)).toBe('none');
		expect(getExpiryStatus({ expiresAt: '2026-05-31T23:59:59Z' }, now)).toBe('expired');
		expect(getExpiryStatus({ expiresAt: '2026-06-01T00:00:00Z' }, now)).toBe('expired');
		// Exactly 14 days away -> expiring
		expect(getExpiryStatus({ expiresAt: '2026-06-15T00:00:00Z' }, now)).toBe('expiring');
		// 15 days away -> active under 14-day default
		expect(getExpiryStatus({ expiresAt: '2026-06-16T00:00:00Z' }, now)).toBe('active');
		expect(getExpiryStatus({ expiresAt: '2026-07-01T00:00:00Z' }, now)).toBe('active');
	});

	test('supports custom warningDays parameter', () => {
		expect(getExpiryStatus({ expiresAt: '2026-06-20T00:00:00Z' }, now, 30)).toBe('expiring');
		expect(getExpiryStatus({ expiresAt: '2026-07-01T00:00:00Z' }, now, 30)).toBe('expiring');
		expect(getExpiryStatus({ expiresAt: '2026-07-02T00:00:00Z' }, now, 30)).toBe('active');
		expect(getExpiryStatus({ expiresAt: '2026-06-10T00:00:00Z' }, now, 7)).toBe('active');
		expect(getExpiryStatus({ expiresAt: '2026-06-08T00:00:00Z' }, now, 7)).toBe('expiring');
	});
});

describe('shortMimeBadge', () => {
	test('prefers modern AVIF/WebP variant labels and formats common MIME types', () => {
		expect(shortMimeBadge('image/jpeg', [{ format: 'webp' }, { format: 'avif' }])).toBe('AVIF');
		expect(shortMimeBadge('image/jpeg', [{ format: 'webp' }])).toBe('WebP');
		expect(shortMimeBadge('image/jpeg')).toBe('JPG');
		expect(shortMimeBadge('application/pdf')).toBe('PDF');
		expect(shortMimeBadge('video/mp4')).toBe('MP4');
		expect(shortMimeBadge('audio/mpeg')).toBe('MP3');
		expect(shortMimeBadge('')).toBe('');
	});
});
