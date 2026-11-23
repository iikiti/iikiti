import { test, expect } from 'bun:test';
import { installAnalyticsLoader } from '../../plugins/iikiti-content-optimization/assets/js/analytics-loader.js';

const ga4Descriptor = {
	key: 'iikiti-content-optimization.ga4',
	adapter: 'ga4',
	scriptUrl: 'https://www.googletagmanager.com/gtag/js',
	parameters: { measurementId: 'G-ABCD1234' },
	cspRequirements: {
		scriptSrc: ['https://www.googletagmanager.com/gtag/js'],
		connectSrc: ['https://www.google-analytics.com'],
		imgSrc: ['https://www.google-analytics.com'],
	},
};

function createEnvironment(connectors = [ga4Descriptor]) {
	const appendedScripts = [];
	const listeners = new Map();
	const managerListeners = [];
	let state = {
		ready: true,
		hasConsented: false,
		stale: false,
		categories: { analytics: false },
	};
	const manager = {
		getState: () => state,
		isAllowed: (category) => category === 'analytics' && state.ready && state.categories.analytics,
		onChange: (listener) => {
			managerListeners.push(listener);
			return () => managerListeners.splice(managerListeners.indexOf(listener), 1);
		},
	};
	const windowRef = {
		iikiti: { consent: manager },
		addEventListener: (name, listener) => listeners.set(name, listener),
	};
	const head = { appendChild: (script) => appendedScripts.push(script) };
	const documentRef = {
		getElementById: (id) => id === 'iikiti-analytics-config' ? {
			tagName: 'SCRIPT',
			parentNode: head,
			type: 'application/json',
			textContent: JSON.stringify({ requireConsent: true, category: 'analytics', connectors }),
		} : null,
		createElement: () => ({}),
		head,
	};
	const notifyChange = () => managerListeners.forEach((listener) => listener(state));
	return {
		windowRef,
		documentRef,
		appendedScripts,
		listeners,
		setState(next) {
			state = next;
			notifyChange();
		},
	};
}

function consentedState(overrides = {}) {
	return {
		ready: true,
		hasConsented: true,
		stale: false,
		categories: { analytics: true },
		...overrides,
	};
}

test('loads GA4 only after every analytics consent gate passes', () => {
	const environment = createEnvironment();
	installAnalyticsLoader(environment.windowRef, environment.documentRef);
	expect(environment.appendedScripts).toHaveLength(0);

	environment.setState(consentedState());
	expect(environment.appendedScripts).toHaveLength(1);
	expect(environment.appendedScripts[0].src).toBe('https://www.googletagmanager.com/gtag/js?id=G-ABCD1234');
	expect(environment.appendedScripts[0].async).toBe(true);
	expect(environment.appendedScripts[0].referrerPolicy).toBe('strict-origin-when-cross-origin');
	expect(environment.windowRef.dataLayer.length).toBeGreaterThan(0);

	environment.listeners.get('iikiti:consent-mode-update')();
	expect(environment.appendedScripts).toHaveLength(1);

	environment.setState(consentedState({ hasConsented: false }));
	expect(environment.windowRef.dataLayer.at(-1)[2]).toMatchObject({
		analytics_storage: 'denied',
		ad_storage: 'denied',
		ad_user_data: 'denied',
		ad_personalization: 'denied',
	});
});

test('sets Clarity consent before append and revokes it when consent changes', () => {
	const clarityDescriptor = {
		key: 'iikiti-content-optimization.clarity',
		adapter: 'clarity',
		scriptUrl: 'https://www.clarity.ms/tag/',
		parameters: { projectId: 'abc123xy' },
		cspRequirements: {
			scriptSrc: ['https://www.clarity.ms/tag/', 'https://scripts.clarity.ms/'],
			connectSrc: ['https://*.clarity.ms'],
			imgSrc: ['https://c.clarity.ms'],
		},
	};
	const environment = createEnvironment([clarityDescriptor]);
	installAnalyticsLoader(environment.windowRef, environment.documentRef);
	environment.setState(consentedState());

	expect(environment.appendedScripts).toHaveLength(1);
	expect(environment.appendedScripts[0].src).toBe('https://www.clarity.ms/tag/abc123xy');
	expect(environment.windowRef.clarity.q[0][0]).toBe('consentv2');
	expect(environment.windowRef.clarity.q[0][1]).toEqual({ ad_Storage: 'denied', analytics_Storage: 'granted' });

	environment.setState(consentedState({ categories: { analytics: false } }));
	expect(environment.windowRef.clarity.q.at(-1)[0]).toBe('consentv2');
	expect(environment.windowRef.clarity.q.at(-1)[1]).toEqual({ ad_Storage: 'denied', analytics_Storage: 'denied' });
	environment.setState(consentedState());
	expect(environment.windowRef.clarity.q.at(-1)[1]).toEqual({ ad_Storage: 'denied', analytics_Storage: 'granted' });
});

test('rejects malformed configuration and unauthorized script paths', () => {
	const invalidDescriptor = {
		...ga4Descriptor,
		cspRequirements: { ...ga4Descriptor.cspRequirements, scriptSrc: ['https://www.googletagmanager.com/other.js'] },
	};
	const invalid = createEnvironment([invalidDescriptor]);
	installAnalyticsLoader(invalid.windowRef, invalid.documentRef);
	invalid.setState(consentedState());
	expect(invalid.appendedScripts).toHaveLength(0);

	const malformedElement = {
		getElementById: () => ({ type: 'application/json', textContent: '{invalid' }),
		createElement: () => ({}),
		head: { appendChild() { throw new Error('must not append'); } },
	};
	const noManager = { iikiti: {}, addEventListener() {} };
	installAnalyticsLoader(noManager, malformedElement);
	expect(noManager.iikiti.analytics).toBeDefined();
});

test('unregistered third-party adapters fail closed and may load only through a registered adapter', () => {
	const externalDescriptor = {
		key: 'vendor.analytics',
		adapter: 'external-script',
		scriptUrl: 'https://metrics.example.test/loader.js',
		parameters: { site: 'alpha team', enabled: true },
		cspRequirements: {
			scriptSrc: ['https://metrics.example.test/loader.js'],
			connectSrc: [],
			imgSrc: [],
		},
	};
	const environment = createEnvironment([externalDescriptor]);
	const api = installAnalyticsLoader(environment.windowRef, environment.documentRef);
	expect(environment.appendedScripts).toHaveLength(0);

	environment.setState(consentedState());
	expect(environment.appendedScripts).toHaveLength(0);

	api.registerAdapter('external-script', {
		load(descriptor) {
			const url = new URL(descriptor.scriptUrl);
			for (const [name, value] of Object.entries(descriptor.parameters)) url.searchParams.set(name, String(value));
			const script = environment.documentRef.createElement('script');
			script.async = true;
			script.referrerPolicy = 'strict-origin-when-cross-origin';
			script.src = url.href;
			environment.documentRef.head.appendChild(script);
		},
		setConsent() {},
	});

	expect(environment.appendedScripts[0].src).toBe('https://metrics.example.test/loader.js?site=alpha+team&enabled=true');
});

test('registered plugin adapters receive revocation and are idempotent by connector key', () => {
	const pluginDescriptor = {
		...ga4Descriptor,
		key: 'vendor.custom',
		adapter: 'vendor-adapter',
		scriptUrl: 'https://vendor.example.test/analytics.js',
		parameters: { site: 'primary' },
		cspRequirements: {
			scriptSrc: ['https://vendor.example.test/analytics.js'],
			connectSrc: [],
			imgSrc: [],
		},
	};
	const environment = createEnvironment([pluginDescriptor]);
	const consents = [];
	let loads = 0;
	const api = installAnalyticsLoader(environment.windowRef, environment.documentRef);
	expect(api.registerAdapter('vendor-no-revoke', { load() {} })).toBe(false);
	expect(api.registerAdapter('vendor-adapter', {
		load: () => { loads++; },
		setConsent: (allowed) => consents.push(allowed),
	})).toBe(true);

	environment.setState(consentedState());
	environment.setState(consentedState());
	expect(loads).toBe(1);

	environment.setState(consentedState({ categories: { analytics: false } }));
	expect(consents).toEqual([true, false]);
	expect(api.registerAdapter('bad adapter', { load() {} })).toBe(false);
});

test('rejects DOM-clobbered config elements outside head and IP-literal script URLs', () => {
	const environment = createEnvironment();
	const bodyEl = { tagName: 'DIV', parentNode: {}, type: 'application/json', textContent: JSON.stringify({ requireConsent: true, category: 'analytics', connectors: [ga4Descriptor] }) };
	environment.documentRef.getElementById = () => bodyEl;
	installAnalyticsLoader(environment.windowRef, environment.documentRef);
	environment.setState(consentedState());
	expect(environment.appendedScripts).toHaveLength(0);

	const ipEnv = createEnvironment([{
		key: 'vendor.ip',
		adapter: 'ip-adapter',
		scriptUrl: 'https://127.0.0.1/track.js',
		parameters: {},
		cspRequirements: { scriptSrc: ['https://127.0.0.1/track.js'], connectSrc: [], imgSrc: [] },
	}]);
	let loaded = false;
	const api = installAnalyticsLoader(ipEnv.windowRef, ipEnv.documentRef);
	api.registerAdapter('ip-adapter', { load: () => { loaded = true; }, setConsent() {} });
	ipEnv.setState(consentedState());
	expect(loaded).toBe(false);
});

test('drains queued adapters, skips setConsent for inactive adapters, retries failed script load, and revokes on async race', async () => {
	const pluginDescriptor = {
		key: 'vendor.custom',
		adapter: 'queued-adapter',
		scriptUrl: 'https://vendor.example.test/analytics.js',
		parameters: { site: 'primary' },
		cspRequirements: { scriptSrc: ['https://vendor.example.test/analytics.js'], connectSrc: [], imgSrc: [] },
	};
	const environment = createEnvironment([pluginDescriptor]);
	const order = [];
	const inactiveCalls = [];
	let resolveLoad;
	let loadCalls = 0;
	environment.windowRef.iikiti.analytics = {
		_queue: [
			['queued-adapter', {
				load: () => {
					loadCalls++;
					order.push('load');
					if (loadCalls === 1) return Promise.reject(new Error('load failed'));
					return new Promise((resolve) => { resolveLoad = resolve; });
				},
				setConsent: (allowed) => order.push(`consent:${allowed}`),
			}],
			['unused-adapter', {
				load: () => inactiveCalls.push('load'),
				setConsent: (allowed) => inactiveCalls.push(`consent:${allowed}`),
			}],
		],
	};

	installAnalyticsLoader(environment.windowRef, environment.documentRef);
	environment.setState(consentedState());
	await Promise.resolve();
	await Promise.resolve();
	expect(order[0]).toBe('load');
	expect(inactiveCalls).toEqual([]);

	// Retry on late consent-ready event after first load failed.
	environment.listeners.get('iikiti:consent-ready')();
	expect(loadCalls).toBe(2);

	// Revoke consent while async load is still in flight; revocation must run after load settles.
	environment.setState(consentedState({ categories: { analytics: false } }));
	resolveLoad();
	await Promise.resolve();
	await Promise.resolve();
	expect(order.at(-1)).toBe('consent:false');
});
