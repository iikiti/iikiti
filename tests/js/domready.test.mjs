import { test, expect, beforeEach, afterEach } from 'bun:test';

let originalDocument;
let originalWindow;
let documentTarget;
let windowTarget;
let importId = 0;

beforeEach(() => {
	originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
	originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
	documentTarget = new EventTarget();
	windowTarget = new EventTarget();
	documentTarget.readyState = 'complete';
	globalThis.document = documentTarget;
	globalThis.window = windowTarget;
});

afterEach(() => {
	for (const [name, descriptor] of [['document', originalDocument], ['window', originalWindow]]) {
		if (descriptor) Object.defineProperty(globalThis, name, descriptor);
		else delete globalThis[name];
	}
});

function importFresh() {
	return import(`../../assets/js/iikiti/domready.js?test=${importId++}`);
}

test('promises resolve immediately when the document is complete', async () => {
	const { domReady, onLoad } = await importFresh();
	await expect(domReady).resolves.toBeUndefined();
	await expect(onLoad).resolves.toBeUndefined();
});

test('loading document waits for DOMContentLoaded and window load separately', async () => {
	documentTarget.readyState = 'loading';
	const { domReady, onLoad } = await importFresh();
	let ready = false;
	let loaded = false;
	domReady.then(() => { ready = true; });
	onLoad.then(() => { loaded = true; });
	await Promise.resolve();
	expect(ready).toBe(false);
	expect(loaded).toBe(false);

	documentTarget.dispatchEvent(new Event('DOMContentLoaded'));
	await expect(domReady).resolves.toBeUndefined();
	expect(loaded).toBe(false);
	windowTarget.dispatchEvent(new Event('load'));
	await expect(onLoad).resolves.toBeUndefined();
});

test('interactive document is ready but waits for window load', async () => {
	documentTarget.readyState = 'interactive';
	const { domReady, onLoad } = await importFresh();
	let loaded = false;
	onLoad.then(() => { loaded = true; });
	await expect(domReady).resolves.toBeUndefined();
	expect(loaded).toBe(false);
	windowTarget.dispatchEvent(new Event('load'));
	await expect(onLoad).resolves.toBeUndefined();
});
