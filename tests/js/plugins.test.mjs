import { test, expect, beforeEach, afterEach } from 'bun:test';

let originals;
let appended;
let windowTarget;
let pluginRegistry;
let startPlugins;
let resetLoaderCache;
let importId = 0;

beforeEach(async () => {
	originals = Object.fromEntries(['document', 'window', 'CSS'].map((name) => [
		name, Object.getOwnPropertyDescriptor(globalThis, name),
	]));
	appended = [];
	windowTarget = new EventTarget();
	globalThis.window = windowTarget;
	globalThis.CSS = { escape: (value) => value };
	globalThis.document = {
		readyState: 'complete',
		createElement: (tag) => ({ tag, dataset: {} }),
		querySelector: () => null,
		head: {
			appendChild(element) {
				appended.push({ element, media: element.media });
				queueMicrotask(() => element.onload());
			},
		},
	};
	({ __resetLoaderCacheForTests: resetLoaderCache } = await import('../../assets/js/iikiti/loader.js'));
	resetLoaderCache();
	({ pluginRegistry, startPlugins } = await import(`../../assets/js/iikiti/plugins.js?test=${importId++}`));
});

afterEach(() => {
	resetLoaderCache();
	for (const [name, descriptor] of Object.entries(originals)) {
		if (descriptor) Object.defineProperty(globalThis, name, descriptor);
		else delete globalThis[name];
	}
});

test('startPlugins loads styles and scripts in order only once', async () => {
	pluginRegistry.register({ name: 'p1', css: '/p1.css', entry: '/p1.js', strategy: 'defer', cssStrategy: 'async' });
	pluginRegistry.register({ name: 'p2', entry: '/p2.js' });

	await startPlugins();
	expect(appended.map(({ element }) => element.href ?? element.src)).toEqual(['/p1.css', '/p1.js', '/p2.js']);
	expect(appended[0].media).toBe('print');
	expect(appended[0].element.media).toBe('all');
	expect(appended[1].element.defer).toBe(true);
	await startPlugins();
	expect(appended).toHaveLength(3);
});

test('interaction plugin waits for both interaction gates before loading', async () => {
	pluginRegistry.register({ name: 'p1', entry: '/p1.js', strategy: 'interaction' });
	const pending = startPlugins();
	expect(appended).toHaveLength(0);
	windowTarget.dispatchEvent(new Event('pointerdown'));
	await Promise.resolve();
	expect(appended).toHaveLength(0);
	windowTarget.dispatchEvent(new Event('keydown'));
	await pending;
	expect(appended.map(({ element }) => element.src)).toEqual(['/p1.js']);
});
