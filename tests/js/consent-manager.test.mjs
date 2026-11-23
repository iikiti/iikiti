import { test, expect, beforeEach, afterEach } from 'bun:test';
import { createConsentManager } from '../../plugins/consent-management/assets/consent-manager.js';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

/**
 * Minimal in-memory localStorage stand-in.
 */
function createLocalStorageMock() {
	const store = {};
	return {
		getItem(key) {
			return key in store ? String(store[key]) : null;
		},
		setItem(key, value) {
			store[key] = String(value);
		},
		removeItem(key) {
			delete store[key];
		},
		clear() {
			Object.keys(store).forEach((k) => delete store[k]);
		},
	};
}

function createConsentScript(category, src) {
	const attributes = [];
	const element = {
		tagName: 'SCRIPT',
		type: 'text/javascript',
		src,
		textContent: '',
		dataset: category ? { consentCategory: category } : {},
		attributes,
		parentNode: null,
		setAttribute(name, value) {
			const existing = attributes.find((attribute) => attribute.name === name);
			if (existing) existing.value = String(value);
			else attributes.push({ name, value: String(value) });
			if (name === 'src') this.src = String(value);
			if (name === 'type') this.type = String(value);
		},
		removeAttribute(name) {
			const index = attributes.findIndex((attribute) => attribute.name === name);
			if (index !== -1) attributes.splice(index, 1);
			if (name === 'src') this.src = '';
			if (name === 'type') this.type = '';
		},
	};
	if (category) attributes.push({ name: 'data-consent-category', value: category });
	if (src) attributes.push({ name: 'src', value: src });
	attributes.push({ name: 'type', value: 'text/javascript' });
	attributes.push({ name: 'nonce', value: 'page-nonce' });

	return element;
}

/**
 * Minimal document stand-in sufficient for initialize() to run without DOM
 * errors. Script blocking/release is covered by Playwright integration tests,
 * not unit tests.
 */
function createDocumentMock() {
	return {
		getElementById() {
			return null;
		},
		querySelectorAll() {
			return [];
		},
		createElement() {
			return {
				dataset: {},
				attributes: [],
				parentNode: null,
				setAttribute() {},
				removeAttribute() {},
				type: '',
				src: '',
			};
		},
		documentElement: {},
		addEventListener() {},
	};
}

function createFetchMock(response) {
	return async () => ({
		ok: response.ok,
		status: response.status ?? (response.ok ? 200 : 500),
		json: async () => response.body,
	});
}

function createDispatchMock() {
	let last = null;
	const fn = (evt) => {
		last = evt;
	};
	fn.last = () => last;
	fn.reset = () => {
		last = null;
	};
	return fn;
}

/** Flush microtasks so async applyConsent completes. */
async function flush() {
    await new Promise((resolve) => queueMicrotask(resolve));
    await Promise.resolve();
}

let originalNavigator;
let originalLocalStorage;

beforeEach(() => {
	originalNavigator = globalThis.navigator;
	originalLocalStorage = globalThis.localStorage;
});

afterEach(() => {
	globalThis.navigator = originalNavigator;
	globalThis.localStorage = originalLocalStorage;
});

function buildManager({
    config = { version: 1, default_categories: { necessary: true, analytics: false, marketing: false } },
    localStorage: lsMock = createLocalStorageMock(),
    fetchResponse = null,
    dispatchEvent: dispatchMock = createDispatchMock(),
    document: docMock = createDocumentMock(),
    navigatorOverride = {},
} = {}) {
	const deps = {
		fetch: createFetchMock(fetchResponse),
		localStorage: lsMock,
		dispatchEvent: dispatchMock,
		document: docMock,
	};

	// Override navigator for signal detection.
	globalThis.navigator = {
		...originalNavigator,
		...navigatorOverride,
	};

	const manager = createConsentManager(config, deps);
	return { manager, deps, dispatchMock, fetchFn: deps.fetch, ls: lsMock };
}

test('initial state: no consent, defaults, stale', () => {
	const { manager } = buildManager();
	const state = manager.getState();
	expect(state.hasConsented).toBe(false);
	expect(state.categories).toEqual({ necessary: true, analytics: false, marketing: false });
	expect(state.stale).toBe(true);
	expect(state.version).toBe(0);
	expect(state.configVersion).toBe(1);
	expect(state.proofToken).toBeNull();
});

test('stored consent is restored and not stale when version matches', () => {
	const ls = createLocalStorageMock();
	ls.setItem('iikiti:consent', JSON.stringify({
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: false },
		version: 1,
		configVersion: 1,
		stale: false,
		legalFramework: 'gdpr',
		geoCountry: 'DE',
		signals: { gpc: false },
		proofToken: 'abc123',
		consentedAt: '2026-01-01T00:00:00+00:00',
		userId: 42,
		sessionId: 'sess-99',
	}));
	const { manager } = buildManager({ localStorage: ls });
	const state = manager.getState();
	expect(state.hasConsented).toBe(true);
	expect(state.categories).toEqual({ necessary: true, analytics: true, marketing: false });
	expect(state.stale).toBe(false);
	expect(state.proofToken).toBe('abc123');
	expect(state.legalFramework).toBe('gdpr');
	expect(state.geoCountry).toBe('DE');
	expect(state.userId).toBe(42);
	expect(state.sessionId).toBe('sess-99');
});

test('stored consent is stale when config version changed', () => {
	const ls = createLocalStorageMock();
	ls.setItem('iikiti:consent', JSON.stringify({
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: false },
		version: 1,
		configVersion: 1,
		stale: false,
	}));
	const { manager } = buildManager({
		config: { version: 2, default_categories: { necessary: true, analytics: false, marketing: false } },
		localStorage: ls,
	});
	const state = manager.getState();
	expect(state.stale).toBe(true);
	expect(state.hasConsented).toBe(false);
});

test('auto-select deny analytics+marketing when GPC present and no prior consent', () => {
	const { manager } = buildManager({
		navigatorOverride: { globalPrivacyControl: true },
	});
	const state = manager.getState();
	expect(state.signals.gpc).toBe(true);
	expect(state.categories.analytics).toBe(false);
	expect(state.categories.marketing).toBe(false);
});

test('auto-select deny analytics+marketing when DNT present and no prior consent', () => {
	const { manager } = buildManager({
		navigatorOverride: { doNotTrack: '1' },
	});
	const state = manager.getState();
	expect(state.signals.dnt).toBe(true);
	expect(state.categories.analytics).toBe(false);
	expect(state.categories.marketing).toBe(false);
});

test('signal does not override previously stored consent', () => {
	const ls = createLocalStorageMock();
	ls.setItem('iikiti:consent', JSON.stringify({
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: true },
		version: 1,
		configVersion: 1,
		stale: false,
	}));
	const { manager } = buildManager({
		localStorage: ls,
		navigatorOverride: { globalPrivacyControl: true },
	});
	const state = manager.getState();
	expect(state.categories.analytics).toBe(true);
	expect(state.categories.marketing).toBe(true);
});

test('isAllowed reflects categories', () => {
	const { manager } = buildManager();
	expect(manager.isAllowed('necessary')).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(false);
	expect(manager.isAllowed('marketing')).toBe(false);
});

test('isAllowed fails closed when default categories allow analytics before explicit consent', () => {
	const { manager } = buildManager({
		config: { version: 1, default_categories: { necessary: true, analytics: true, marketing: true } },
	});
	const state = manager.getState();

	expect(state.hasConsented).toBe(false);
	expect(state.categories.analytics).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(false);
});

test('isAllowed waits until consent initialization completes before allowing stored consent', async () => {
	const ls = createLocalStorageMock();
	ls.setItem('iikiti:consent', JSON.stringify({
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: false },
		version: 1,
		configVersion: 1,
		stale: false,
	}));
	const { manager } = buildManager({ localStorage: ls, fetchResponse: { ok: false, body: {} } });

	expect(manager.getState().ready).toBe(false);
	expect(manager.isAllowed('analytics')).toBe(false);
	await manager.initialize();
	expect(manager.getState().ready).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(true);
});

test('server denial or stale status invalidates previously stored local consent', async () => {
	const ls = createLocalStorageMock();
	ls.setItem('iikiti:consent', JSON.stringify({
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: false },
		version: 1,
		configVersion: 1,
		stale: false,
	}));
	const { manager } = buildManager({ localStorage: ls, fetchResponse: { ok: false, status: 400, body: {} } });
	await manager.initialize();

	expect(manager.getState().hasConsented).toBe(false);
	expect(manager.getState().stale).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(false);
});

test('local explicit consent remains usable when Consumer API synchronization is unavailable', async () => {
	const { manager } = buildManager({ fetchResponse: { ok: false, status: 503, body: {} } });
	await manager.initialize();
	manager.allow('analytics');
	await flush();

	expect(manager.getState().ready).toBe(true);
	expect(manager.getState().hasConsented).toBe(true);
	expect(manager.getState().categories.analytics).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(true);
});

test('releases only individually consented blocked scripts', async () => {
	const analytics = createConsentScript('analytics', 'https://analytics.example/collect.js');
	const marketing = createConsentScript('marketing', 'https://ads.example/track.js');
	const released = [];
	const doc = createDocumentMock();
	doc.querySelectorAll = (selector) => selector === 'script[data-consent-category]' ? [analytics, marketing] : [];
	doc.createElement = () => {
		const script = createConsentScript('', '');
		script.attributes.splice(script.attributes.findIndex((attribute) => attribute.name === 'nonce'), 1);
		return script;
	};
	const parent = {
		replaceChild(replacement, original) {
			released.push({ replacement, original });
		},
	};
	analytics.parentNode = parent;
	marketing.parentNode = parent;
	const { manager } = buildManager({ document: doc });
	await manager.initialize();

	expect(analytics.src).toBe('');
	expect(marketing.src).toBe('');
	manager.allow('marketing');
	await flush();

	expect(released).toHaveLength(1);
	expect(released[0].original).toBe(marketing);
	expect(released[0].replacement.src).toBe('https://ads.example/track.js');
	expect(released[0].replacement.attributes.some((attribute) => attribute.name === 'nonce')).toBe(false);
	expect(analytics.src).toBe('');

	manager.allow('analytics');
	await flush();

	expect(released).toHaveLength(2);
	expect(released[1].original).toBe(analytics);
});

test('allow analytics updates state and emits change', async () => {
	const dispatchMock = createDispatchMock();
	const listenerCalls = [];
	const { manager } = buildManager({
		dispatchEvent: dispatchMock,
	});
	manager.onChange((s) => listenerCalls.push(s.categories));
	await manager.initialize();

	manager.allow('analytics');

	// allow() is async (applyConsent); flush to let it complete.
	await flush();

	const state = manager.getState();
	expect(state.categories.analytics).toBe(true);
	expect(listenerCalls.length).toBeGreaterThan(0);
	const evt = dispatchMock.last();
	expect(evt).not.toBeNull();
	expect(evt.type).toBe('iikiti:consent-mode-update');
	expect(evt.detail.analytics_storage).toBe('granted');
	expect(evt.detail.ad_storage).toBe('denied');
	expect(evt.detail.ad_user_data).toBe('denied');
});

test('deny marketing sets ad_user_data and ad_personalization to denied', async () => {
	const dispatchMock = createDispatchMock();
	const { manager } = buildManager({
		config: { version: 1, default_categories: { necessary: true, analytics: true, marketing: true } },
		dispatchEvent: dispatchMock,
	});
	await manager.initialize();
	// First consent to enable consent-mode dispatch.
	manager.allow('marketing');
	await flush();

	dispatchMock.reset();
	manager.deny('marketing');
	await flush();

	const state = manager.getState();
	expect(state.categories.marketing).toBe(false);
	const evt = dispatchMock.last();
	expect(evt.detail.ad_user_data).toBe('denied');
	expect(evt.detail.ad_personalization).toBe('denied');
});

test('consent mode event detail when all granted', async () => {
	const dispatchMock = createDispatchMock();
	const { manager } = buildManager({ dispatchEvent: dispatchMock });
	await manager.initialize();
	manager.allow('analytics');
	await flush();
	manager.allow('marketing');
	await flush();
	const evt = dispatchMock.last();
	expect(evt.detail.ad_storage).toBe('granted');
	expect(evt.detail.analytics_storage).toBe('granted');
	expect(evt.detail.ad_user_data).toBe('granted');
	expect(evt.detail.ad_personalization).toBe('granted');
});

test('consent mode event detail when all denied', async () => {
	const dispatchMock = createDispatchMock();
	const { manager } = buildManager({ dispatchEvent: dispatchMock });
	await manager.initialize();
	manager.deny('analytics');
	await flush();
	manager.deny('marketing');
	await flush();
	const evt = dispatchMock.last();
	expect(evt.detail.ad_storage).toBe('denied');
	expect(evt.detail.analytics_storage).toBe('denied');
	expect(evt.detail.ad_user_data).toBe('denied');
	expect(evt.detail.ad_personalization).toBe('denied');
});

test('session id is generated and reused', () => {
	const ls = createLocalStorageMock();
	const { manager, ls: storage } = buildManager({ localStorage: ls });
	const first = manager.sessionId();
	expect(first).toBeTruthy();
	const second = manager.sessionId();
	expect(second).toBe(first);
	expect(storage.getItem('iikiti:consent:session')).toBe(first);
});

test('server state overrides local state on initialize', async () => {
	const ls = createLocalStorageMock();
	const dispatchMock = createDispatchMock();
	const { manager } = buildManager({
		localStorage: ls,
		fetchResponse: {
			ok: true,
			body: {
				hasConsented: true,
				categories: { necessary: true, analytics: true, marketing: true },
				version: 1,
				configVersion: 1,
				stale: false,
				legalFramework: 'ccpa',
				geoCountry: 'US',
				signals: { gpc: false },
				proofToken: 'srv-token',
				consentedAt: '2026-02-02T00:00:00+00:00',
				userId: null,
				sessionId: 'srv-sess',
			},
		},
		dispatchEvent: dispatchMock,
	});
	await manager.initialize();
	await flush();
	const state = manager.getState();
	expect(state.hasConsented).toBe(true);
	expect(state.legalFramework).toBe('ccpa');
	expect(state.geoCountry).toBe('US');
	expect(state.proofToken).toBe('srv-token');
	expect(state.sessionId).toBe('srv-sess');
	expect(dispatchMock.last()).not.toBeNull();
});

test('getConfig and getConfigVersion return config', () => {
	const { manager } = buildManager({ config: { version: 3, default_categories: { necessary: true, analytics: false, marketing: false } } });
	expect(manager.getConfigVersion()).toBe(3);
	expect(manager.getConfig().version).toBe(3);
});

function bootstrapManager(configText) {
	const document = createDocumentMock();
	let ready;
	let config = null;
	document.readyState = 'loading';
	document.addEventListener = (name, listener) => { ready = listener; };
	document.getElementById = (id) => id === 'iikiti-consent-config' ? config : null;
	const window = {
		fetch: async () => ({ ok: false }),
		localStorage: createLocalStorageMock(),
		dispatchEvent() {},
	};
	const source = readFileSync(new URL('../../plugins/consent-management/assets/consent-manager.js', import.meta.url), 'utf8')
		.replace('export function createConsentManager', 'function createConsentManager');
	runInNewContext(source, { window, document, navigator: {}, CustomEvent, crypto, console });
	config = { textContent: configText };
	return { window, ready };
}

test('browser bootstrap reads config at DOM ready and tolerates malformed JSON', async () => {
	const delayed = bootstrapManager('{"version":7}');
	delayed.window.localStorage.setItem('iikiti:consent', JSON.stringify({
		version: 7,
		hasConsented: true,
		categories: { necessary: true, analytics: true, marketing: false },
	}));
	await delayed.ready();
	expect(delayed.window.iikiti.consent.getConfigVersion()).toBe(7);
	expect(delayed.window.iikiti.consent.getState().hasConsented).toBe(true);
	expect(delayed.window.iikiti.consent.isAllowed('analytics')).toBe(true);
	const malformed = bootstrapManager('{invalid');
	await malformed.ready();
	expect(malformed.window.iikiti.consent.getConfigVersion()).toBe(1);
});

test('stored local consent without hasConsented fails closed', async () => {
	const delayed = bootstrapManager('{"version":7}');
	delayed.window.localStorage.setItem('iikiti:consent', JSON.stringify({
		version: 7,
		categories: { necessary: true, analytics: true, marketing: false },
	}));
	await delayed.ready();
	expect(delayed.window.iikiti.consent.getState().hasConsented).toBe(false);
	expect(delayed.window.iikiti.consent.isAllowed('analytics')).toBe(false);
});

test('consent mode updates reach the component event bus', async () => {
	const originalWindow = globalThis.window;
	const events = [];
	globalThis.window = { iikiti: { components: { emit: (...args) => events.push(args) } } };
	try {
		const { manager } = buildManager();
		manager.allow('analytics');
		await flush();
		expect(events).toHaveLength(1);
		expect(events[0][0]).toBe('consent-mode-update');
		expect(events[0][1].analytics_storage).toBe('granted');
	} finally {
		globalThis.window = originalWindow;
	}
});

test('allow() during an in-flight initialize() is not overwritten by stale server state', async () => {
	let resolveFetch;
	const fetchMock = (url, opts) => {
		if (opts?.method === 'POST') {
			return Promise.resolve({
				ok: true,
				json: async () => ({ proofToken: 'tok-new', version: 1, configVersion: 1 }),
			});
		}
		return new Promise((resolve) => {
			resolveFetch = () => resolve({
				ok: true,
				json: async () => ({
					hasConsented: false,
					categories: { necessary: true, analytics: false, marketing: false },
					version: 0,
					stale: true,
				}),
			});
		});
	};
	const manager = createConsentManager(
		{ version: 1, default_categories: { necessary: true, analytics: false, marketing: false } },
		{
			fetch: fetchMock,
			localStorage: createLocalStorageMock(),
			dispatchEvent: createDispatchMock(),
			document: createDocumentMock(),
		},
	);
	const initPromise = manager.initialize();
	const initPromise2 = manager.initialize();
	expect(initPromise2).toBe(initPromise);

	await manager.allow('analytics');
	expect(manager.getState().ready).toBe(true);
	expect(manager.isAllowed('analytics')).toBe(true);

	resolveFetch();
	await initPromise;
	expect(manager.isAllowed('analytics')).toBe(true);
	expect(manager.getState().hasConsented).toBe(true);
});

test('hideBanner() during pending loadBanner() keeps banner hidden and failed loadBanner() retries', async () => {
	const originalWindow = globalThis.window;
	let attempts = 0;
	let finish;
	globalThis.window = {
		iikiti: {
			loader: {
				loadScript: () => {
					attempts++;
					if (attempts === 1) return Promise.reject(new Error('network error'));
					return new Promise((resolve) => { finish = resolve; });
				},
			},
		},
	};
	try {
		const container = { hidden: true };
		const doc = createDocumentMock();
		doc.getElementById = (id) => (id === 'iikiti-consent-banner' ? container : null);
		const { manager } = buildManager({ document: doc });
		window.iikiti.consent = manager;
		await expect(manager.loadBanner()).rejects.toThrow('network error');

		manager.showBanner();
		expect(container.hidden).toBe(false);
		manager.hideBanner();
		expect(container.hidden).toBe(true);

		let mounted = false;
		window.iikiti.consentBanner = { mount: () => { mounted = true; } };
		finish();
		await flush();
		expect(attempts).toBe(2);
		expect(mounted).toBe(false);
	} finally {
		globalThis.window = originalWindow;
	}
});

test('banner loading is shared while pending and after it completes', async () => {
	const originalWindow = globalThis.window;
	let calls = 0;
	let finish;
	globalThis.window = { iikiti: { loader: { loadScript: () => {
		calls++;
		return new Promise((resolve) => { finish = resolve; });
	} } } };
	try {
		const { manager } = buildManager();
		window.iikiti.consent = manager;
		const first = manager.loadBanner();
		const second = manager.loadBanner();
		expect(calls).toBe(1);
		finish();
		await Promise.all([first, second]);
		await manager.loadBanner();
		expect(calls).toBe(1);
	} finally {
		globalThis.window = originalWindow;
	}
});
