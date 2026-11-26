import { test, expect, beforeEach, afterEach } from 'bun:test';
import { load, mount } from '../../assets/js/iikiti/fragments.js';

const originalFetch = globalThis.fetch;
const originalDocument = globalThis.document;
const originalWindow = globalThis.window;

beforeEach(() => {
	globalThis.window = { location: { href: 'http://localhost/' } };
	globalThis.document = {
		querySelector: () => null,
		querySelectorAll: () => [],
		head: { appendChild: () => {} },
		createElement: () => ({
			dataset: {},
			innerHTML: '',
			content: { replaceChildren: () => {} },
			replaceChildren: () => {},
			matches: () => true,
			getAttribute: () => 'not-json',
			setAttribute: () => {},
		}),
	};
});

afterEach(() => {
	globalThis.fetch = originalFetch;
	globalThis.document = originalDocument;
	globalThis.window = originalWindow;
});

test('load validates provider is a non-empty string', async () => {
	await expect(load('')).rejects.toThrow('Fragment provider must be a non-empty string.');
	await expect(load('   ')).rejects.toThrow('Fragment provider must be a non-empty string.');
	await expect(load(123)).rejects.toThrow('Fragment provider must be a non-empty string.');
});

test('load fetches and returns a validated fragment result', async () => {
	let fetchedUrl = null;
	globalThis.fetch = async (url) => {
		fetchedUrl = url;
		return new Response(
			JSON.stringify({
				html: '<div>content</div>',
				styles: [{ id: 'test-css', css: '.x{color:red}' }],
			}),
			{ status: 200, headers: { 'content-type': 'application/json' } },
		);
	};

	const result = await load('test-provider', { params: { foo: 'bar' } });
	expect(String(fetchedUrl)).toContain('/_iikiti/fragments/test-provider');
	expect(String(fetchedUrl)).toContain('params=');
	expect(result.html).toBe('<div>content</div>');
	expect(result.styles).toHaveLength(1);
});

test('load throws on non-ok response', async () => {
	globalThis.fetch = async () =>
		new Response('error', { status: 500, statusText: 'Internal Server Error' });
	await expect(load('bad-provider')).rejects.toThrow(/Fragment request failed with status 500/);
});

test('load throws on invalid result shape', async () => {
	globalThis.fetch = async () =>
		new Response(JSON.stringify({ html: '<div>' }), { status: 200 });
	await expect(load('bad-shape')).rejects.toThrow(/Fragment response must include an html string and styles array/);
});

test('mount tolerates invalid descriptors without throwing', async () => {
	const el = document.createElement('div');
	el.setAttribute('data-iikiti-fragment-descriptor', 'not-json');
	await expect(mount(el)).resolves.toEqual([]);
});

test('mount resolves when no placeholders are present', async () => {
	const el = { matches: () => false, querySelectorAll: () => [] };
	await expect(mount(el)).resolves.toEqual([]);
});

test('mount fetches and renders a valid descriptor', async () => {
	let requestedUrl;
	globalThis.fetch = async (url) => {
		requestedUrl = String(url);
		return new Response(JSON.stringify({ html: '<p>Loaded</p>', styles: [] }));
	};
	let rendered;
	const el = {
		matches: () => true,
		querySelectorAll: () => [],
		getAttribute: () => JSON.stringify({ provider: 'example', params: { id: 42 } }),
		replaceChildren: (content) => { rendered = content; },
	};
	const results = await mount(el);
	expect(requestedUrl).toContain('/_iikiti/fragments/example');
	expect(new URL(requestedUrl).searchParams.get('params')).toBe('{"id":42}');
	expect(results[0].status).toBe('fulfilled');
	expect(rendered).toBeDefined();
});

function createDeferred() {
	let resolve;
	let reject;
	const promise = new Promise((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

function createDomHarness(descriptors) {
	const installedStyles = [];
	const elements = descriptors.map((descriptor) => ({
		descriptor,
		renderedHtml: null,
		matches: (sel) => sel === '[data-iikiti-fragment-descriptor]',
		querySelectorAll: () => [],
		getAttribute: (name) => (name === 'data-iikiti-fragment-descriptor' ? JSON.stringify(descriptor) : null),
		replaceChildren(content) {
			this.renderedHtml = content.html;
		},
	}));

	globalThis.document = {
		querySelector: () => null,
		querySelectorAll: (selector) => {
			if (selector === '[data-iikiti-fragment-descriptor]') return elements;
			if (selector === 'style[data-iikiti-fragment-provider]') return installedStyles;
			return [];
		},
		head: {
			appendChild: (el) => {
				installedStyles.push(el);
			},
		},
		createElement: () => ({
			dataset: {},
			innerHTML: '',
			textContent: '',
			get content() {
				return { html: this.innerHTML };
			},
		}),
	};

	return {
		elements,
		installedStyles,
		root: {
			matches: () => false,
			querySelectorAll: () => elements,
		},
	};
}

test('mount renders independent sibling and installs its styles while another fetch is stalled', async () => {
	const stalledFetch = createDeferred();
	const { elements, installedStyles, root } = createDomHarness([
		{ id: 'slow', provider: 'sample.slow', params: {} },
		{ id: 'fast', provider: 'sample.fast', params: {} },
	]);

	globalThis.fetch = (url) => {
		const target = String(url);
		if (target.includes('sample.slow')) {
			return stalledFetch.promise;
		}
		return Promise.resolve(
			new Response(
				JSON.stringify({
					html: '<p>Fast Sibling Ready</p>',
					styles: [{ id: 'fast-style', css: '.fast{color:green}' }],
				}),
				{ status: 200 },
			),
		);
	};

	const mountPromise = mount(root);
	await new Promise((r) => setTimeout(r, 20));

	expect(elements[1].renderedHtml).toBe('<p>Fast Sibling Ready</p>');
	expect(elements[0].renderedHtml).toBeNull();
	expect(installedStyles).toHaveLength(1);
	expect(installedStyles[0].dataset.iikitiFragmentProvider).toBe('sample.fast');

	stalledFetch.resolve(
		new Response(
			JSON.stringify({
				html: '<p>Slow Finally Arrived</p>',
				styles: [{ id: 'slow-style', css: '.slow{color:blue}' }],
			}),
			{ status: 200 },
		),
	);
	const settled = await mountPromise;
	expect(settled.every((item) => item.status === 'fulfilled')).toBe(true);
	expect(elements[0].renderedHtml).toBe('<p>Slow Finally Arrived</p>');
	expect(installedStyles).toHaveLength(2);
});

test('mount completes dependent fragment chain while unrelated slow fragment stays pending', async () => {
	const slowFetch = createDeferred();
	const order = [];
	const { elements, root } = createDomHarness([
		{ id: 'unrelated-slow', provider: 'sample.slow', params: {} },
		{ id: 'chain-a', provider: 'sample.a', params: {} },
		{ id: 'chain-b', provider: 'sample.b', params: {}, dependsOn: ['chain-a'] },
	]);

	globalThis.fetch = (url) => {
		const target = String(url);
		if (target.includes('sample.slow')) {
			order.push('start:slow');
			return slowFetch.promise;
		}
		if (target.includes('sample.a')) {
			order.push('fetch:a');
			return Promise.resolve(new Response(JSON.stringify({ html: '<p>A</p>', styles: [] })));
		}
		order.push('fetch:b');
		return Promise.resolve(new Response(JSON.stringify({ html: '<p>B</p>', styles: [] })));
	};

	const mountPromise = mount(root);
	await new Promise((r) => setTimeout(r, 20));

	expect(elements[1].renderedHtml).toBe('<p>A</p>');
	expect(elements[2].renderedHtml).toBe('<p>B</p>');
	expect(elements[0].renderedHtml).toBeNull();
	expect(order.indexOf('fetch:a')).toBeLessThan(order.indexOf('fetch:b'));

	slowFetch.resolve(new Response(JSON.stringify({ html: '<p>Slow</p>', styles: [] })));
	await mountPromise;
	expect(elements[0].renderedHtml).toBe('<p>Slow</p>');
});

test('mount isolates failed fragment and its dependents without blocking healthy siblings', async () => {
	const { elements, root } = createDomHarness([
		{ id: 'broken', provider: 'sample.broken', params: {} },
		{ id: 'dependent', provider: 'sample.dependent', params: {}, dependsOn: ['broken'] },
		{ id: 'healthy', provider: 'sample.healthy', params: {} },
	]);

	let dependentCalled = false;
	globalThis.fetch = async (url) => {
		const target = String(url);
		if (target.includes('sample.broken')) {
			return new Response('boom', { status: 500 });
		}
		if (target.includes('sample.dependent')) {
			dependentCalled = true;
			return new Response(JSON.stringify({ html: '<p>Should not run</p>', styles: [] }));
		}
		return new Response(JSON.stringify({ html: '<p>Healthy</p>', styles: [] }));
	};

	const settled = await mount(root);
	expect(settled[0].status).toBe('rejected');
	expect(settled[1].status).toBe('rejected');
	expect(settled[2].status).toBe('fulfilled');
	expect(dependentCalled).toBe(false);
	expect(elements[2].renderedHtml).toBe('<p>Healthy</p>');
});

test('mount enforces MAX_ACTIVE_REQUESTS sliding window while fragment #1 stays pending', async () => {
	const firstFetch = createDeferred();
	const descriptors = [];
	for (let i = 1; i <= 34; i++) {
		descriptors.push({ id: `f-${i}`, provider: `sample.p${i}`, params: {} });
	}
	const { elements, root } = createDomHarness(descriptors);

	let inFlight = 0;
	let maxInFlight = 0;
	globalThis.fetch = (url) => {
		const target = String(url);
		inFlight++;
		if (inFlight > maxInFlight) maxInFlight = inFlight;

		if (target.includes('sample.p1?') || target.endsWith('sample.p1')) {
			return firstFetch.promise.finally(() => {
				inFlight--;
			});
		}
		const match = target.match(/sample\.p(\d+)/);
		const idx = match ? match[1] : 'x';
		return Promise.resolve(new Response(JSON.stringify({ html: `<p>P${idx}</p>`, styles: [] }))).finally(() => {
			inFlight--;
		});
	};

	const mountPromise = mount(root);
	await new Promise((r) => setTimeout(r, 30));

	expect(maxInFlight).toBeLessThanOrEqual(32);
	expect(elements[0].renderedHtml).toBeNull();
	expect(elements[33].renderedHtml).toBe('<p>P34</p>');
	for (let i = 1; i < 34; i++) {
		expect(elements[i].renderedHtml).toBe(`<p>P${i + 1}</p>`);
	}

	firstFetch.resolve(new Response(JSON.stringify({ html: '<p>P1</p>', styles: [] })));
	const settled = await mountPromise;
	expect(settled.every((item) => item.status === 'fulfilled')).toBe(true);
	expect(elements[0].renderedHtml).toBe('<p>P1</p>');
});
