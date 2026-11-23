/**
 * Consent manager — loads early (sync, in <head>) and governs consent-based
 * script blocking until the user sets preferences.
 *
 * Exposes `window.iikiti.consent`:
 *   getState(), isAllowed(category), allow(category), deny(category),
 *   onChange(fn), showBanner(), hideBanner(), loadBanner(), initialize(),
 *   getConfig(), getConfigVersion(), sessionId()
 *
 * Scripts marked `data-consent-category="analytics|marketing"` are blocked
 * (src neutralised) until consent is granted, then released.
 *
 * The core factory `createConsentManager(config, deps)` is exported for unit
 * testing. `deps` accepts `{ fetch, localStorage, dispatchEvent, document }`.
 */

(function () {
	if (
		typeof window === 'undefined' ||
		typeof document === 'undefined' ||
		typeof document.getElementById !== 'function'
	) {
		return;
	}
	if (window.iikiti?.consent) {
		return;
	}

	const consent = createConsentManager({}, {
		fetch: (...args) => window.fetch(...args),
		localStorage: {
			getItem: (key) => window.localStorage.getItem(key),
			setItem: (key, value) => window.localStorage.setItem(key, value),
		},
		dispatchEvent: (evt) => window.dispatchEvent(evt),
		document,
		readConfig: () => {
			try {
				const el = document.getElementById('iikiti-consent-config');
				return el ? JSON.parse(el.textContent || '{}') : {};
			} catch {
				return {};
			}
		},
	});

	window.iikiti = window.iikiti || {};
	window.iikiti.consent = consent;

	// Defer DOM initialization until the DOM is ready so config and scripts
	// are present.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => consent.initialize());
	} else {
		consent.initialize();
	}
})();

/**
 * Create a consent manager instance.
 *
 * @param {object} config - Banner config (version, default_categories, bannerUrl)
 * @param {object} deps - Injected dependencies for testability
 * @param {function} deps.fetch - Fetch function (defaults to global fetch)
 * @param {object} deps.localStorage - Storage (defaults to global localStorage)
 * @param {function} deps.dispatchEvent - Dispatch function (defaults to global)
 * @param {object} deps.document - Document (defaults to global document)
 * @returns {{ getState: function, isAllowed: function, allow: function,
 *   deny: function, onChange: function, showBanner: function,
 *   hideBanner: function, loadBanner: function, initialize: function,
 *   getConfig: function, getConfigVersion: function, sessionId: function }}
 */
export function createConsentManager(config, deps = {}) {
	const {
		fetch: fetchFn = globalThis.fetch.bind(globalThis),
		localStorage: ls = globalThis.localStorage,
		dispatchEvent: dispatch = globalThis.dispatchEvent.bind(globalThis),
		document: doc = globalThis.document,
		readConfig,
	} = deps;

	const STORAGE_KEY = 'iikiti:consent';
	const SESSION_KEY = 'iikiti:consent:session';
	const CATEGORIES = ['necessary', 'analytics', 'marketing'];

	let state = null;
	let sessionId = null;
	let listeners = [];
	let blocked = [];
	let initialized = false;
	let initPromise = null;
	let consentRevision = 0;

	function getConfig() {
		return config;
	}

	function getConfigVersion() {
		return Number(config.version ?? 1);
	}

	function isAllowed(category) {
		if (category === 'necessary') {
			return true;
		}
		if (!CATEGORIES.includes(category)) {
			return false;
		}

		return Boolean(
			state?.ready === true &&
			state?.hasConsented === true &&
			state?.stale !== true &&
			state?.categories?.[category] === true
		);
	}

	function categoriesForExplicitChange() {
		if (state?.ready === true && state.hasConsented === true && state.stale !== true) {
			return { ...state.categories, necessary: true };
		}

		return { necessary: true, analytics: false, marketing: false };
	}

	function getDefaultCategories() {
		const raw = config.default_categories;
		if (raw && typeof raw === 'object') {
			return {
				necessary: true,
				analytics: Boolean(raw.analytics),
				marketing: Boolean(raw.marketing),
			};
		}
		return { necessary: true, analytics: false, marketing: false };
	}

	function ensureSessionId() {
		if (sessionId) {
			return sessionId;
		}
		let existing = null;
		try {
			existing = ls.getItem(SESSION_KEY);
		} catch {
			// ignore
		}
		if (!existing) {
			existing = crypto.randomUUID?.() ?? (Math.random().toString(36) + Date.now().toString(36));
			try {
				ls.setItem(SESSION_KEY, existing);
			} catch {
				// ignore
			}
		}
		sessionId = existing;
		return sessionId;
	}

	function readStored() {
		try {
			const raw = ls.getItem(STORAGE_KEY);
			if (raw) {
				return JSON.parse(raw);
			}
		} catch {
			// ignore
		}
		return null;
	}

	function writeStored(value) {
		try {
			ls.setItem(STORAGE_KEY, JSON.stringify(value));
		} catch {
			// ignore
		}
	}

	function detectSignals() {
		const signals = {};
		try {
			if (
				typeof globalThis.navigator?.globalPrivacyControl === 'boolean' &&
				globalThis.navigator.globalPrivacyControl
			) {
				signals.gpc = true;
			}
		} catch {
			// ignore
		}
		try {
			const dnt = globalThis.navigator?.doNotTrack;
			if (dnt === '1' || dnt === 'yes') {
				signals.dnt = true;
			}
		} catch {
			// ignore
		}
		return signals;
	}

	function autoSelect(stateArg) {
		const signals = detectSignals();
		const hasSignal = Boolean(signals.gpc || signals.dnt);
		if (hasSignal && stateArg.categories.analytics) {
			stateArg.categories.analytics = false;
		}
		if (hasSignal && stateArg.categories.marketing) {
			stateArg.categories.marketing = false;
		}
		return signals;
	}

	function emitChange() {
		const snapshot = { ...state };
		listeners.forEach((fn) => {
			try {
				fn(snapshot);
			} catch {
				// ignore listener errors
			}
		});
	}

	function dispatchConsentMode() {
		if (!state) return;
		const ready = state.ready === true && state.hasConsented === true && state.stale !== true;
		const analytics = ready && state.categories.analytics === true;
		const marketing = ready && state.categories.marketing === true;
		const eventDetail = {
			ad_storage: marketing ? 'granted' : 'denied',
			analytics_storage: analytics ? 'granted' : 'denied',
			ad_user_data: marketing ? 'granted' : 'denied',
			ad_personalization: marketing ? 'granted' : 'denied',
			consent_state: state,
		};
		try {
			dispatch(
				new CustomEvent('iikiti:consent-mode-update', {
					detail: eventDetail,
					cancelable: true,
				})
			);
		} catch {
			// ignore
		}
		if (typeof window !== 'undefined' && window.iikiti?.components) {
			window.iikiti.components.emit('consent-mode-update', eventDetail);
		}
	}

	/**
	 * Persist consent to localStorage and the server, then release blocked
	 * scripts and notify listeners.
	 */
	async function applyConsent(categories) {
		ensureSessionId();
		const revision = ++consentRevision;
		const next = {
			hasConsented: true,
			ready: true,
			categories: {
				necessary: true,
				analytics: Boolean(categories.analytics),
				marketing: Boolean(categories.marketing),
			},
			version: getConfigVersion(),
			configVersion: getConfigVersion(),
			stale: false,
			legalFramework: state?.legalFramework ?? 'none',
			geoCountry: state?.geoCountry ?? null,
			signals: state?.signals ?? null,
			proofToken: state?.proofToken ?? null,
			consentedAt: state?.consentedAt ?? null,
			userId: state?.userId ?? null,
			sessionId,
		};

		try {
			const response = await fetchFn('/api/privacy/consent', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'include',
				body: JSON.stringify({
					categories: next.categories,
					session_id: sessionId,
					version: next.version,
				}),
			});
			if (response.ok) {
				const result = await response.json();
				const responseVersion = Number(result.configVersion ?? result.version ?? 0);
				if (result.hasConsented === false || result.stale === true || responseVersion !== getConfigVersion()) {
					next.hasConsented = false;
					next.categories = getDefaultCategories();
					next.version = Number(result.version ?? 0);
					next.stale = true;
				} else {
					next.proofToken = result.proofToken ?? next.proofToken;
					next.legalFramework = result.legalFramework ?? next.legalFramework;
					next.version = responseVersion;
					next.configVersion = getConfigVersion();
					next.stale = false;
					if (typeof result.sessionId === 'string' && result.sessionId) {
						sessionId = result.sessionId;
						next.sessionId = result.sessionId;
					}
				}
			} else if (response.status >= 400 && response.status < 500) {
				next.hasConsented = false;
				next.categories = getDefaultCategories();
				next.stale = true;
			}
		} catch {
			// offline: keep localStorage copy, still apply locally
		}
		if (revision !== consentRevision) {
			return state;
		}

		// Explicit user consent is authoritative and must never regress ready to false.
		initialized = true;
		next.ready = true;
		state = next;
		writeStored(next);
		emitChange();
		dispatchConsentMode();
		releaseBlocked();
		if (!state.hasConsented || state.stale) {
			showBanner();
		} else {
			hideBanner();
		}
		return state;
	}

	/** Block a script element until consent is given. */
	function blockScript(el) {
		if (el.dataset.iikitiConsentBlocked) {
			return;
		}
		const category = el.dataset.consentCategory;
		if (!CATEGORIES.includes(category) || category === 'necessary' || isAllowed(category)) {
			return;
		}
		el.dataset.iikitiConsentBlocked = '1';
		if (!Object.prototype.hasOwnProperty.call(el.dataset, 'consentType')) {
			el.dataset.consentType = el.type;
		}
		if (el.src) {
			el.dataset.consentSrc = el.src;
			el.removeAttribute('src');
		}
		if (el.type !== 'text/plain') {
			el.type = 'text/plain';
		}
		blocked.push(el);
	}

	/** Release only scripts whose individual category has explicit fresh consent. */
	function releaseBlocked() {
		const remaining = [];
		blocked.forEach((el) => {
			const category = el.dataset.consentCategory;
			if (!isAllowed(category)) {
				remaining.push(el);
				return;
			}

			const src = el.dataset.consentSrc;
			const replacement = doc.createElement('script');
			const originalType = el.dataset.consentType;
			if (originalType) {
				replacement.type = originalType;
			}
			if (src) {
				replacement.src = src;
			} else {
				replacement.textContent = el.textContent ?? '';
			}
			for (const attr of el.attributes) {
				if (
					attr.name !== 'src' &&
					attr.name !== 'type' &&
					attr.name !== 'data-consent-category' &&
					attr.name !== 'data-iikiti-consent-blocked' &&
					attr.name !== 'data-consent-src' &&
					attr.name !== 'data-consent-type' &&
					!(src && attr.name === 'nonce')
				) {
					replacement.setAttribute(attr.name, attr.value);
				}
			}
			if (el.parentNode) {
				el.parentNode.replaceChild(replacement, el);
			}
		});
		blocked = remaining;
	}

	/** Intercept dynamically created scripts. */
	function installCreateElementInterceptor() {
		const nativeCreateElement = doc.createElement.bind(doc);
		doc.createElement = function (tag, options) {
			const el = nativeCreateElement(tag, options);
			if (tag.toLowerCase() === 'script') {
				const originalSetAttribute = el.setAttribute.bind(el);
				el.setAttribute = function (name, value) {
					originalSetAttribute(name, value);
					if (
						name === 'data-consent-category' &&
						CATEGORIES.includes(value) &&
						value !== 'necessary' &&
						!isAllowed(value)
					) {
						blockScript(el);
					}
					if (
						name === 'src' &&
						el.dataset.consentCategory &&
						CATEGORIES.includes(el.dataset.consentCategory) &&
						el.dataset.consentCategory !== 'necessary' &&
						!isAllowed(el.dataset.consentCategory)
					) {
						blockScript(el);
					}
					return undefined;
				};
			}
			return el;
		};
	}

	function installMutationObserver() {
		if (!globalThis.MutationObserver) {
			return;
		}
		const observer = new globalThis.MutationObserver((mutations) => {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (node.nodeType !== 1) {
						continue;
					}
					const el = node;
					if (
						el.tagName === 'SCRIPT' &&
						el.dataset.consentCategory &&
						CATEGORIES.includes(el.dataset.consentCategory) &&
						el.dataset.consentCategory !== 'necessary' &&
						!isAllowed(el.dataset.consentCategory)
					) {
						blockScript(el);
					}
					el.querySelectorAll?.('script[data-consent-category]').forEach((s) => {
						if (
							CATEGORIES.includes(s.dataset.consentCategory) &&
							s.dataset.consentCategory !== 'necessary' &&
							!isAllowed(s.dataset.consentCategory) &&
							!s.dataset.iikitiConsentBlocked
						) {
							blockScript(s);
						}
					});
				}
			}
		});
		observer.observe(doc.documentElement || doc, { childList: true, subtree: true });
	}

	function blockExistingScripts() {
		doc.querySelectorAll('script[data-consent-category]').forEach((el) => {
			blockScript(el);
		});
	}

	async function loadBanner() {
		if (api.bannerLoaded) {
			return api.bannerLoaded;
		}
		const bannerUrl = config.bannerUrl;
		if (typeof window !== 'undefined' && window.iikiti?.consentBanner?.mount) {
			api.bannerLoaded = Promise.resolve();
			return api.bannerLoaded;
		}
		if (typeof window !== 'undefined' && window.iikiti?.loader) {
			api.bannerLoaded = window.iikiti.loader
				.loadScript(bannerUrl ? bannerUrl : 'consent-banner', { strategy: 'complete' })
				.then(() => {
					api.bannerLoaded = true;
					return api;
				})
				.catch((error) => {
					api.bannerLoaded = null;
					throw error;
				});
			return api.bannerLoaded;
		}
		return Promise.resolve();
	}

	function showBanner() {
		const container = doc.getElementById('iikiti-consent-banner');
		if (!container) {
			return;
		}
		container.hidden = false;
		void (async () => {
			try {
				await loadBanner();
			} catch {
				return;
			}
			if (container.hidden) {
				return;
			}
			if (typeof window !== 'undefined' && window.iikiti?.consentBanner?.mount) {
				window.iikiti.consentBanner.mount(container, async ({ analytics, marketing }) => {
					await applyConsent({ necessary: true, analytics, marketing });
				});
			}
		})();
	}

	function hideBanner() {
		const container = doc.getElementById('iikiti-consent-banner');
		if (container) {
			container.hidden = true;
		}
	}

	function initialize() {
		if (initialized) {
			return Promise.resolve();
		}
		if (initPromise) {
			return initPromise;
		}
		initPromise = (async () => {
			const initialConsentRevision = consentRevision;
			if (readConfig) {
				config = readConfig();
			}
			initializeState();
			blockExistingScripts();
			installCreateElementInterceptor();
			installMutationObserver();

			// Fetch server-side consent state (may override local for logged-in users or signed cookie sessions).
			try {
				const response = await fetchFn('/api/privacy/consent', {
					headers: { Accept: 'application/json' },
					credentials: 'include',
				});
				if (response.ok) {
					const server = await response.json();
					if (initialConsentRevision === consentRevision) {
						const currentVersion = getConfigVersion();
						const serverVersion = Number(server.configVersion ?? server.version ?? 0);
						const hasCurrentConsent = server.hasConsented === true
							&& server.stale !== true
							&& Number(server.version ?? 0) === currentVersion
							&& serverVersion === currentVersion;
						state = hasCurrentConsent
							? {
								...state,
								hasConsented: true,
								categories: {
									necessary: true,
									analytics: Boolean(server.categories?.analytics),
									marketing: Boolean(server.categories?.marketing),
								},
								version: serverVersion,
								configVersion: currentVersion,
								stale: false,
								legalFramework: server.legalFramework ?? state.legalFramework,
								geoCountry: server.geoCountry ?? state.geoCountry,
								signals: server.signals ?? state.signals,
								proofToken: server.proofToken ?? state.proofToken,
								consentedAt: server.consentedAt ?? state.consentedAt,
								userId: server.userId ?? state.userId,
								sessionId: server.sessionId ?? state.sessionId,
							}
							: {
								...state,
								hasConsented: false,
								categories: getDefaultCategories(),
								version: Number(server.version ?? 0),
								configVersion: currentVersion,
								stale: Boolean(server.stale) || serverVersion !== currentVersion,
							};
						writeStored(state);
					}
				} else if (response.status >= 400 && response.status < 500 && initialConsentRevision === consentRevision) {
					state = {
						...state,
						hasConsented: false,
						categories: getDefaultCategories(),
						configVersion: getConfigVersion(),
						stale: true,
					};
				}
			} catch {
				// ignore network errors; local state stands
			}

			initialized = true;
			state = { ...state, ready: true };
			writeStored(state);
			emitChange();
			dispatchConsentMode();
			releaseBlocked();
			if (!state.hasConsented || state.stale) {
				showBanner();
			} else {
				hideBanner();
			}
			try {
				dispatch(new CustomEvent('iikiti:consent-ready', { detail: { state: { ...state } } }));
			} catch {
				// ignore event dispatch errors
			}
		})().finally(() => {
			initPromise = null;
		});

		return initPromise;
	}

	function initializeState() {
		const stored = readStored();
		const signals = detectSignals();
		const currentVersion = getConfigVersion();
		const hasCurrentConsent = Boolean(
			stored?.hasConsented === true &&
			stored?.stale !== true &&
			stored?.version === currentVersion
		);
		const initial = hasCurrentConsent && stored?.categories && typeof stored.categories === 'object'
			? {
				necessary: true,
				analytics: Boolean(stored.categories.analytics),
				marketing: Boolean(stored.categories.marketing),
			}
			: getDefaultCategories();

		state = {
			ready: false,
			hasConsented: hasCurrentConsent,
			categories: initial,
			version: stored?.version ?? 0,
			configVersion: currentVersion,
			stale: Boolean(!stored || stored.stale === true || stored.version !== currentVersion),
			legalFramework: stored?.legalFramework ?? 'none',
			geoCountry: stored?.geoCountry ?? null,
			signals,
			proofToken: stored?.proofToken ?? null,
			consentedAt: stored?.consentedAt ?? null,
			userId: stored?.userId ?? null,
			sessionId: stored?.sessionId ?? null,
		};

		// Privacy signals override defaults when consent has not been recorded.
		if (!state.hasConsented) {
			const signalForced = autoSelect(state);
			state.signals = { ...state.signals, ...signalForced };
		}
	}

	initializeState();

	const api = {
		getState() {
			return state ? { ...state } : null;
		},
		isAllowed(category) {
			return isAllowed(category);
		},
		allow(category) {
			if (!state) {
				return Promise.resolve(null);
			}
			return applyConsent({ ...categoriesForExplicitChange(), [category]: true });
		},
		deny(category) {
			if (!state) {
				return Promise.resolve(null);
			}
			return applyConsent({ ...categoriesForExplicitChange(), [category]: false });
		},
		onChange(fn) {
			listeners.push(fn);
			return function unsubscribe() {
				listeners = listeners.filter((l) => l !== fn);
			};
		},
		showBanner,
		hideBanner,
		loadBanner,
		initialize,
		getConfig,
		getConfigVersion,
		sessionId: function () {
			return ensureSessionId();
		},
	};

	return api;
}
