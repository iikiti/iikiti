import { test, expect, beforeEach, afterEach } from 'bun:test';
let loadIf;
let resetLoaderCache;

/**
 * Minimal DOM stand-in: records appended <script> elements and fires onload
 * on the next microtask so loadIf can resolve without a browser.
 */
let appended;
let originalDocument;

beforeEach(async () => {
	originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
	appended = [];
	globalThis.document = {
		readyState: 'complete',
		createElement: () => ({ dataset: {} }),
		querySelector: () => null,
		head: {
			appendChild(el) {
				appended.push(el);
				queueMicrotask(() => {
					el.onload?.();
				});
			},
		},
	};
	({ loadIf, __resetLoaderCacheForTests: resetLoaderCache } = await import('../../assets/js/iikiti/loader.js'));
	resetLoaderCache();
});

afterEach(() => {
	resetLoaderCache();
	if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument);
	else delete globalThis.document;
});

test('returns the native value directly and never loads the polyfill', async () => {
	const nativeValue = { native: true };
	const value = await loadIf(() => nativeValue, {
		url: '/vendor/polyfill.js',
		read: () => undefined,
	});

	expect(value).toBe(nativeValue);
	expect(appended.length).toBe(0);
});

test('treats falsy native values as present (only undefined means missing)', async () => {
	expect(await loadIf(() => 0, { url: '/p.js', read: () => 'polyfill' })).toBe(0);
	expect(await loadIf(() => null, { url: '/p.js', read: () => 'polyfill' })).toBe(null);
	expect(await loadIf(() => false, { url: '/p.js', read: () => 'polyfill' })).toBe(false);
	expect(appended.length).toBe(0);
});

test('loads the polyfill and resolves to the reference read after load', async () => {
	const polyfillRef = { polyfilled: true };
	const value = await loadIf(() => undefined, {
		url: '/vendor/polyfill.js',
		read: () => polyfillRef,
	});

	expect(value).toBe(polyfillRef);
	expect(appended.length).toBe(1);
	expect(appended[0].src).toBe('/vendor/polyfill.js');
});

test('concurrent callers share a single script load', async () => {
	const shared = { shared: true };
	const read = () => shared;
	const spec = { url: '/vendor/shared.js', read };

	const [first, second] = await Promise.all([
		loadIf(() => undefined, spec),
		loadIf(() => undefined, spec),
	]);

	expect(first).toBe(second);
	expect(appended.length).toBe(1);
});

test('rejects when the polyfill loads but read() yields undefined', async () => {
	await expect(
		loadIf(() => undefined, { url: '/vendor/broken.js', read: () => undefined }),
	).rejects.toThrow(/Polyfill loaded but its reference is unavailable/);
});

test('rejects when the probe is not a function', async () => {
	await expect(
		loadIf('Temporal', { url: '/p.js', read: () => 1 }),
	).rejects.toThrow(TypeError);
});
