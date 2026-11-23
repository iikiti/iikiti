const GA4_KEY = 'iikiti-content-optimization.ga4';
const GA4_SCRIPT_BASE = 'https://www.googletagmanager.com/gtag/js';
const CLARITY_KEY = 'iikiti-content-optimization.clarity';
const CLARITY_SCRIPT_BASE = 'https://www.clarity.ms/tag/';
const CONNECTOR_KEY_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/;
const ADAPTER_ID_PATTERN = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const PARAMETER_NAME_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]*$/;

function isObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function parseHttpsUrl(value, { allowWildcard = false } = {}) {
	if (typeof value !== 'string' || value === '' || /[\s\u0000-\u001f\u007f\\;,]/.test(value)) return null;
	if (value.includes('?') || value.includes('#')) return null;
	if (!allowWildcard && value.includes('*')) return null;
	if (allowWildcard && value.includes('*')
		&& (!/^https:\/\/\*\.[^/]+(?:\/.*)?$/.test(value) || (value.match(/\*/g) || []).length !== 1)
	) return null;

	try {
		const normalizedValue = allowWildcard ? value.replace('://*.', '://wildcard.') : value;
		const url = new URL(normalizedValue);
		if (url.protocol !== 'https:' || !url.hostname || url.username || url.password || url.search || url.hash) return null;
		if (/^(?:\d{1,3}\.){3}\d{1,3}$/.test(url.hostname) || url.hostname.startsWith('[') || url.hostname.includes(':')) return null;
		if (url.hostname.startsWith('wildcard.') && url.hostname.slice('wildcard.'.length).split('.').length < 2) return null;
		if (url.port && (Number(url.port) < 1 || Number(url.port) > 65535)) return null;
		const decodedPath = decodeURIComponent(url.pathname);
		if (/[\\;,\u0000-\u001f\u007f]/.test(decodedPath) || decodedPath.includes('//') || /(?:^|\/)\.\.?($|\/)/.test(decodedPath)) return null;
		return url;
	} catch {
		return null;
	}
}

function validateSourceList(sources, { allowWildcard = false, requirePinnedPath = false } = {}) {
	return Array.isArray(sources) && sources.every((source) => {
		const url = parseHttpsUrl(source, { allowWildcard });
		return url !== null && (!requirePinnedPath || (url.pathname !== '' && url.pathname !== '/'));
	});
}

function matchesScriptSource(scriptUrl, sources) {
	const script = parseHttpsUrl(scriptUrl);
	if (!script || !Array.isArray(sources)) return false;

	return sources.some((source) => {
		const rule = parseHttpsUrl(source);
		if (!rule || rule.origin !== script.origin) return false;
		return rule.pathname.endsWith('/')
			? script.pathname.startsWith(rule.pathname)
			: script.pathname === rule.pathname;
	});
}

function validateParameters(parameters) {
	if (!isObject(parameters)) return false;

	return Object.entries(parameters).every(([name, value]) => {
		if (!PARAMETER_NAME_PATTERN.test(name)) return false;
		if (typeof value === 'string') return !/[\u0000-\u001f\u007f<>]/.test(value);
		if (typeof value === 'number') return Number.isFinite(value);
		return typeof value === 'boolean';
	});
}

function readConfig(documentRef) {
	const element = documentRef.getElementById('iikiti-analytics-config');
	if (!element || element.tagName !== 'SCRIPT' || element.parentNode !== documentRef.head || element.type !== 'application/json') return null;

	try {
		const config = JSON.parse(element.textContent || '');
		if (!isObject(config) || config.requireConsent !== true || config.category !== 'analytics' || !Array.isArray(config.connectors)) {
			return null;
		}

		const keys = new Set();
		for (const connector of config.connectors) {
			if (!isObject(connector)
				|| typeof connector.key !== 'string'
				|| !CONNECTOR_KEY_PATTERN.test(connector.key)
				|| typeof connector.adapter !== 'string'
				|| !ADAPTER_ID_PATTERN.test(connector.adapter)
				|| !isObject(connector.parameters)
				|| !validateParameters(connector.parameters)
				|| !isObject(connector.cspRequirements)
				|| !validateSourceList(connector.cspRequirements.scriptSrc, { requirePinnedPath: connector.adapter !== 'clarity' })
				|| !validateSourceList(connector.cspRequirements.connectSrc, { allowWildcard: true })
				|| !validateSourceList(connector.cspRequirements.imgSrc, { allowWildcard: true })
				|| !matchesScriptSource(connector.scriptUrl, connector.cspRequirements.scriptSrc)
				|| (connector.key === GA4_KEY && (connector.adapter !== 'ga4'
					|| connector.scriptUrl !== GA4_SCRIPT_BASE
					|| Object.keys(connector.parameters).length !== 1
					|| typeof connector.parameters.measurementId !== 'string'
					|| !/^G-[A-Z0-9]{4,20}$/.test(connector.parameters.measurementId)))
				|| (connector.adapter === 'ga4' && connector.key !== GA4_KEY)
				|| (connector.key === CLARITY_KEY && (connector.adapter !== 'clarity'
					|| connector.scriptUrl !== CLARITY_SCRIPT_BASE
					|| Object.keys(connector.parameters).length !== 1
					|| typeof connector.parameters.projectId !== 'string'
					|| !/^[a-z0-9]{6,20}$/.test(connector.parameters.projectId)))
				|| (connector.adapter === 'clarity' && connector.key !== CLARITY_KEY)
				|| keys.has(connector.key)
			) {
				return null;
			}
			keys.add(connector.key);
		}

		return config;
	} catch {
		return null;
	}
}

function createScript(documentRef, url) {
	return new Promise((resolve, reject) => {
		const script = documentRef.createElement('script');
		script.async = true;
		script.referrerPolicy = 'strict-origin-when-cross-origin';
		script.onload = () => resolve(script);
		script.onerror = () => {
			script.remove?.();
			reject(new Error('Analytics provider script failed to load.'));
		};
		script.src = url;
		documentRef.head.appendChild(script);
	});
}

function createGa4Adapter(windowRef, documentRef) {
	let initialized = false;
	let lastConsent = null;

	function updateConsent(allowed) {
		if (!initialized || lastConsent === allowed) return;
		lastConsent = allowed;
		windowRef.gtag('consent', 'update', {
			analytics_storage: allowed ? 'granted' : 'denied',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
		});
	}

	return {
		load(descriptor) {
			if (descriptor.key !== GA4_KEY || descriptor.scriptUrl !== GA4_SCRIPT_BASE) return;
			const id = descriptor.parameters.measurementId;
			if (typeof id !== 'string' || !/^G-[A-Z0-9]{4,20}$/.test(id)
				|| !matchesScriptSource(GA4_SCRIPT_BASE, descriptor.cspRequirements.scriptSrc)
			) return;

			if (!initialized) {
				if (!Array.isArray(windowRef.dataLayer)) windowRef.dataLayer = [];
				windowRef.gtag = function gtag() {
					windowRef.dataLayer.push(arguments);
				};
				windowRef.gtag('consent', 'default', {
					analytics_storage: 'denied',
					ad_storage: 'denied',
					ad_user_data: 'denied',
					ad_personalization: 'denied',
				});
				windowRef.gtag('js', new Date());
				initialized = true;
			}

			updateConsent(true);
			windowRef.gtag('config', id, {
				allow_google_signals: false,
				allow_ad_personalization_signals: false,
				anonymize_ip: true,
			});
			const scriptUrl = new URL(GA4_SCRIPT_BASE);
			scriptUrl.searchParams.set('id', id);
			return createScript(documentRef, scriptUrl.href);
		},
		setConsent(allowed) {
			updateConsent(allowed);
		},
	};
}

function createClarityAdapter(windowRef, documentRef) {
	let initialized = false;
	let lastConsent = null;

	function setClarityConsent(allowed) {
		if (typeof windowRef.clarity !== 'function' || lastConsent === allowed) return;
		lastConsent = allowed;
		windowRef.clarity('consentv2', {
			ad_Storage: 'denied',
			analytics_Storage: allowed ? 'granted' : 'denied',
		});
	}

	function initializeQueue() {
		if (typeof windowRef.clarity !== 'function') {
			windowRef.clarity = function clarity() {
				(windowRef.clarity.q = windowRef.clarity.q || []).push(arguments);
			};
		}
		initialized = true;
	}

	return {
		load(descriptor) {
			if (descriptor.key !== CLARITY_KEY || descriptor.scriptUrl !== CLARITY_SCRIPT_BASE) return;
			const projectId = descriptor.parameters.projectId;
			if (typeof projectId !== 'string' || !/^[a-z0-9]{6,20}$/.test(projectId)) return;

			const scriptUrl = `${CLARITY_SCRIPT_BASE}${projectId}`;
			if (!matchesScriptSource(scriptUrl, descriptor.cspRequirements.scriptSrc)) return;
			initializeQueue();
			setClarityConsent(true);
			return createScript(documentRef, scriptUrl);
		},
		setConsent(allowed) {
			if (initialized) setClarityConsent(allowed);
		},
	};
}

/** Install the consent-gated analytics API before the consent fragment is mounted. */
export function installAnalyticsLoader(windowRef = window, documentRef = document) {
	const config = readConfig(documentRef);
	const descriptors = config?.connectors ?? [];
	const activeAdapterIds = new Set(descriptors.map((descriptor) => descriptor.adapter));
	const adapters = new Map([
		['ga4', createGa4Adapter(windowRef, documentRef)],
		['clarity', createClarityAdapter(windowRef, documentRef)],
	]);
	const loadedKeys = new Set();
	const loadingKeys = new Set();
	const analyticsApi = isObject(windowRef.iikiti.analytics) ? windowRef.iikiti.analytics : {};
	const queuedAdapters = Array.isArray(analyticsApi._queue) ? analyticsApi._queue.splice(0) : [];
	let consentManager = null;
	let unsubscribe = null;
	let previousAllowed = false;

	function getConsent() {
		const manager = windowRef.iikiti?.consent;
		if (!manager || typeof manager.getState !== 'function' || typeof manager.isAllowed !== 'function') {
			return { manager: null, state: null, allowed: false };
		}

		try {
			const state = manager.getState();
			const allowed = isObject(state)
				&& state.ready === true
				&& state.hasConsented === true
				&& state.stale !== true
				&& state.categories?.analytics === true
				&& manager.isAllowed('analytics') === true;
			return { manager, state, allowed };
		} catch {
			return { manager: null, state: null, allowed: false };
		}
	}

	function notifyConsent(allowed, state) {
		for (const [adapterId, handler] of adapters.entries()) {
			if (!activeAdapterIds.has(adapterId) || typeof handler.setConsent !== 'function') continue;
			try {
				void Promise.resolve(handler.setConsent(allowed, state)).catch(() => {});
			} catch {
				// A plugin consent handler must not prevent other adapters from updating.
			}
		}
	}

	function loadAllowedConnectors() {
		for (const descriptor of descriptors) {
			const handler = adapters.get(descriptor.adapter);
			if (!handler || loadedKeys.has(descriptor.key) || loadingKeys.has(descriptor.key)) continue;
			if (descriptor.adapter === 'ga4' && (descriptor.key !== GA4_KEY || descriptor.scriptUrl !== GA4_SCRIPT_BASE)) continue;
			if (descriptor.adapter === 'clarity' && (descriptor.key !== CLARITY_KEY || descriptor.scriptUrl !== CLARITY_SCRIPT_BASE)) continue;
			loadingKeys.add(descriptor.key);
			try {
				void Promise.resolve(handler.load(descriptor)).then(() => {
					loadingKeys.delete(descriptor.key);
					loadedKeys.add(descriptor.key);
					const current = getConsent();
					if (!current.allowed) {
						try {
							void Promise.resolve(handler.setConsent(false, current.state)).catch(() => {});
						} catch {
						// Keep a provider failure isolated from the consent API.
					}
					}
				}).catch(() => {
					loadingKeys.delete(descriptor.key);
				});
			} catch {
				loadingKeys.delete(descriptor.key);
			}
		}
	}

	function refreshConsent() {
		const { manager, state, allowed } = getConsent();
		if (manager !== consentManager) {
			if (typeof unsubscribe === 'function') unsubscribe();
			consentManager = manager;
			unsubscribe = null;
			if (manager && typeof manager.onChange === 'function') {
				try {
					unsubscribe = manager.onChange(() => refreshConsent());
				} catch {
					unsubscribe = null;
				}
			}
		}

		if (allowed) loadAllowedConnectors();
		if (allowed !== previousAllowed) notifyConsent(allowed, state);
		previousAllowed = allowed;
	}

	analyticsApi.registerAdapter = (adapterId, handler) => {
		if (typeof adapterId !== 'string' || !ADAPTER_ID_PATTERN.test(adapterId)
			|| adapters.has(adapterId)
			|| !isObject(handler)
			|| typeof handler.load !== 'function'
			|| typeof handler.setConsent !== 'function'
		) return false;

		adapters.set(adapterId, handler);
		const { allowed, state } = getConsent();
		if (allowed && activeAdapterIds.has(adapterId)) {
			loadAllowedConnectors();
			try {
				void Promise.resolve(handler.setConsent(true, state)).catch(() => {});
			} catch {
				// Adapter load remains independent of consent notification failures.
			}
		}
		return true;
	};
	windowRef.iikiti.analytics = analyticsApi;

	for (const entry of queuedAdapters) {
		if (Array.isArray(entry) && entry.length >= 2) {
			analyticsApi.registerAdapter(entry[0], entry[1]);
		}
	}

	windowRef.addEventListener('iikiti:consent-ready', refreshConsent);
	windowRef.addEventListener('iikiti:consent-mode-update', refreshConsent);
	refreshConsent();
	return analyticsApi;
}

if (
	typeof window !== 'undefined' &&
	typeof document !== 'undefined' &&
	typeof document.getElementById === 'function' &&
	document.getElementById('iikiti-analytics-config') &&
	typeof window.iikiti?.analytics?.getConnectors !== 'function'
) {
	installAnalyticsLoader(window, document);
}
