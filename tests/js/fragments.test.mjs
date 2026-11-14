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
