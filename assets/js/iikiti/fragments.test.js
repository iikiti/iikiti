import { afterEach, expect, test } from 'bun:test';
import { createFragmentsFacade } from './fragmentFacade.js';

const originalDocument = globalThis.document;
const originalFetch = globalThis.fetch;
const originalWindow = globalThis.window;

afterEach(() => {
	if (originalDocument === undefined) delete globalThis.document;
	else globalThis.document = originalDocument;
	globalThis.fetch = originalFetch;
	if (originalWindow === undefined) delete globalThis.window;
	else globalThis.window = originalWindow;
});

test('facade imports and caches the implementation on first use', async () => {
	let imports = 0;
	const api = { load: async () => 'loaded', mount: async () => 'mounted' };
	const fragments = createFragmentsFacade(async () => {
		imports++;
		return api;
	});

	expect(imports).toBe(0);
	expect(await fragments.load('example')).toBe('loaded');
	expect(await fragments.mount()).toBe('mounted');
	expect(imports).toBe(1);
});

test('load validates the response, deduplicates styles, and replaces only after success', async () => {
	const styles = [];
	const document = {
		location: { href: 'https://example.test/page' },
		head: { appendChild: (element) => styles.push(element) },
		createElement(tag) {
			if (tag === 'style') return { dataset: {}, textContent: '' };
			return { set innerHTML(value) { this.content = { html: value }; } };
		},
		querySelectorAll() { return styles; },
	};
	globalThis.document = document;
	globalThis.window = { location: document.location };
	const target = { content: 'old', replaceChildren(content) { this.content = content; } };
	const result = { html: '<b>fragment</b>', styles: [{ id: 'site', css: '.fragment{color:red}' }] };
	let calls = 0;
	globalThis.fetch = async (url, options) => {
		calls++;
		expect(new URL(url).pathname).toBe('/_iikiti/fragments/example');
		expect(JSON.parse(new URL(url).searchParams.get('params'))).toEqual({ page: 3 });
		expect(options.credentials).toBe('same-origin');
		return { ok: true, json: async () => result };
	};

	const { load } = await import('./fragments.js');
	expect(await load('example', { params: { page: 3 }, target })).toBe(result);
	expect(target.content.html).toBe(result.html);
	expect(styles).toHaveLength(1);
	expect(styles[0].textContent).toBe('.fragment{color:red}');
	await load('example', { params: { page: 3 }, target });
	expect(calls).toBe(2);
	expect(styles).toHaveLength(1);

	globalThis.fetch = async () => ({ ok: true, json: async () => ({ html: 4, styles: [] }) });
	await expect(load('example', { target })).rejects.toThrow('html string and styles array');
	expect(target.content.html).toBe(result.html);
});

test('mount waits for declared dependencies and leaves unrelated placeholders independent', async () => {
	const styles = [];
	const document = {
		location: { href: 'https://example.test/page' },
		head: { appendChild: (element) => styles.push(element) },
		createElement(tag) {
			if (tag === 'style') return { dataset: {}, textContent: '' };
			return { set innerHTML(value) { this.content = { html: value }; } };
		},
		querySelectorAll() { return styles; },
	};
	globalThis.document = document;
	globalThis.window = { location: document.location };
	const requests = [];
	globalThis.fetch = async (url) => {
		requests.push(new URL(url).pathname);
		return { ok: true, json: async () => ({ html: '<span></span>', styles: [] }) };
	};
	const placeholder = (descriptor) => ({
		getAttribute: () => JSON.stringify(descriptor),
		replaceChildren() {},
	});
	const dependent = placeholder({ provider: 'dependent', id: 'dependent', dependsOn: ['base'] });
	const base = placeholder({ provider: 'base', id: 'base' });
	const root = { querySelectorAll: () => [dependent, base] };

	const { mount } = await import('./fragments.js');
	const results = await mount(root);
	expect(results.map(({ status }) => status)).toEqual(['fulfilled', 'fulfilled']);
	expect(requests).toEqual(['/_iikiti/fragments/base', '/_iikiti/fragments/dependent']);
});

test('concurrent loads are bounded', async () => {
	const document = {
		location: { href: 'https://example.test/page' },
		head: { appendChild() {} },
		createElement() { return { dataset: {}, textContent: '' }; },
		querySelectorAll() { return []; },
	};
	globalThis.document = document;
	globalThis.window = { location: document.location };
	let active = 0;
	let maxActive = 0;
	globalThis.fetch = async () => {
		active++;
		maxActive = Math.max(maxActive, active);
		await new Promise((resolve) => setTimeout(resolve, 1));
		active--;
		return { ok: true, json: async () => ({ html: '<i></i>', styles: [] }) };
	};

	const { load } = await import('./fragments.js');
	await Promise.all(Array.from({ length: 40 }, (_, index) => load(`sample-${index}`)));
	expect(maxActive).toBe(32);
});
