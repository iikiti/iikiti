/**
 * Consent manager — loads early (sync, in <head>) and governs consent-based
 * script blocking until the user sets preferences.
 *
 * Exposes `window.iikiti.consent`:
 *   getState(), isAllowed(category), allow(category), onChange(fn),
 *   showBanner(), hideBanner(), loadBanner()
 *
 * Scripts marked `data-consent-category="analytics|marketing"` are blocked
 * (src neutralised) until consent is granted, then released.
 */
(function () {
	if (window.iikiti?.consent) {
		return;
	}

	const CONFIG_KEY = 'iikiti-consent-config';
	const STORAGE_KEY = 'iikiti:consent';
	const CATEGORIES = ['necessary', 'analytics', 'marketing'];

	/**
	 * @typedef {object} ConsentState
	 * @property {boolean} hasConsented
	 * @property {{ necessary: boolean, analytics: boolean, marketing: boolean }} categories
	 * @property {number} version
	 * @property {number} configVersion
	 * @property {boolean} stale
	 * @property {string} legalFramework
	 * @property {string|null} geoCountry
	 * @property {object|null} signals
	 * @property {string|null} proofToken
	 * @property {string|null} consentedAt
	 */

	let config = {};
	let state = null;
	let sessionId = null;
	let listeners = [];
	let blocked = [];
	let released = false;

	function readConfig() {
		const el = document.getElementById(CONFIG_KEY);
		if (el) {
			try {
				config = JSON.parse(el.textContent || '{}');
			} catch {
				config = {};
			}
		}
		return config;
	}

	function getConfig() {
		return config;
	}

	function getConfigVersion() {
		return Number(config.version ?? 1);
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
		let existing = localStorage.getItem('iikiti:consent:session');
		if (!existing) {
			existing = crypto.randomUUID?.() ?? (Math.random().toString(36) + Date.now().toString(36));
			localStorage.setItem('iikiti:consent:session', existing);
		}
		sessionId = existing;
		return sessionId;
	}

	function readStored() {
		try {
			const raw = localStorage.getItem(STORAGE_KEY);
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
			localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
		} catch {
			// ignore
		}
	}

	function detectSignals() {
		const signals = {};
		try {
			if (navigator.userAgentData?.brands) {
				// gpc via header only is read server-side; client fallback
			}
			if (typeof navigator.globalPrivacyControl === 'boolean' && navigator.globalPrivacyControl) {
				signals.gpc = true;
			}
		} catch {
			// ignore
		}
		const dnt = navigator.doNotTrack;
		if (dnt === '1' || dnt === 'yes') {
			signals.dnt = true;
		}
		return signals;
	}

	function autoSelect(state) {
		const signals = detectSignals();
		const hasSignal = Boolean(signals.gpc || signals.dnt);
		if (hasSignal && state.categories.analytics) {
			state.categories.analytics = false;
		}
		if (hasSignal && state.categories.marketing) {
			state.categories.marketing = false;
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
		if (!state || !state.hasConsented) {
			return;
		}
		const { analytics, marketing } = state.categories;
		const eventDetail = {
			ad_storage: analytics || marketing ? 'granted' : 'denied',
			analytics_storage: analytics ? 'granted' : 'denied',
			ad_user_data: marketing ? 'granted' : 'denied',
			ad_personalization: marketing ? 'granted' : 'denied',
			consent_state: state,
		};
		try {
			window.dispatchEvent(
				new CustomEvent('iikiti:consent-mode-update', { detail: eventDetail, cancelable: true })
			);
		} catch {
			// ignore
		}
		if (window.iikiti?.components) {
			window.iikiti.components.emit('consent-mode-update', eventDetail);
		}
	}

	/**
	 * Persist consent to localStorage and the server, then release blocked
	 * scripts and notify listeners.
	 */
	async function applyConsent(categories, version) {
		ensureSessionId();
		const next = {
			hasConsented: true,
			categories: { necessary: true, analytics: Boolean(categories.analytics), marketing: Boolean(categories.marketing) },
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
			const response = await fetch('/api/privacy/consent', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ categories: next.categories, session_id: sessionId, version: next.version }),
			});
			if (response.ok) {
				const result = await response.json();
				next.proofToken = result.proofToken ?? next.proofToken;
				next.legalFramework = result.legalFramework ?? next.legalFramework;
				next.version = result.version ?? next.version;
				next.stale = Boolean(result.stale);
			}
		} catch {
			// offline: keep localStorage copy, still apply locally
		}

		state = next;
		writeStored(next);
		emitChange();
		dispatchConsentMode();
		releaseBlocked();
		return state;
	}

	/** Block a script element until consent is given. */
	function blockScript(el) {
		if (released || el.dataset.iikitiConsentBlocked) {
			return;
		}
		const category = el.dataset.consentCategory;
		if (!CATEGORIES.includes(category) || category === 'necessary') {
			return;
		}
		el.dataset.iikitiConsentBlocked = '1';
		if (el.src) {
			el.dataset.consentSrc = el.src;
			el.removeAttribute('src');
		}
		if (el.type !== 'text/plain') {
			el.dataset.consentType = el.type;
			el.type = 'text/plain';
		}
		blocked.push(el);
	}

	/** Release all blocked scripts once consent is granted. */
	function releaseBlocked() {
		if (released) {
			return;
		}
		released = true;
		blocked.forEach((el) => {
			if (el.dataset.consentSrc) {
				const src = el.dataset.consentSrc;
				const type = el.dataset.consentType;
				// Re-insert a fresh script so the browser executes it.
				const replacement = document.createElement('script');
				replacement.src = src;
				if (type) {
					replacement.type = type;
				}
				// Copy non-src attributes.
				for (const attr of el.attributes) {
					if (attr.name !== 'src' && attr.name !== 'type' && attr.name !== 'data-consent-category' && attr.name !== 'data-iikiti-consent-blocked' && attr.name !== 'data-consent-src' && attr.name !== 'data-consent-type') {
						replacement.setAttribute(attr.name, attr.value);
					}
				}
				el.parentNode.replaceChild(replacement, el);
			} else if (el.type === 'text/plain') {
				el.type = el.dataset.consentType ?? 'text/javascript';
			}
		});
		blocked = [];
	}

	/** Intercept dynamically created scripts. */
	function installCreateElementInterceptor() {
		const nativeCreateElement = document.createElement.bind(document);
		document.createElement = function (tag, options) {
			const el = nativeCreateElement(tag, options);
			if (tag.toLowerCase() === 'script') {
				const originalSetAttribute = el.setAttribute.bind(el);
				el.setAttribute = function (name, value) {
					originalSetAttribute(name, value);
					if (name === 'data-consent-category' && CATEGORIES.includes(value) && value !== 'necessary' && !released) {
						blockScript(el);
					}
					if (name === 'src' && el.dataset.consentCategory && CATEGORIES.includes(el.dataset.consentCategory) && el.dataset.consentCategory !== 'necessary' && !released) {
						blockScript(el);
					}
					return undefined;
				};
			}
			return el;
		};
	}

	function installMutationObserver() {
		if (!window.MutationObserver) {
			return;
		}
		const observer = new MutationObserver((mutations) => {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (node.nodeType !== 1) {
						continue;
					}
					const el = node;
					if (el.tagName === 'SCRIPT' && el.dataset.consentCategory && CATEGORIES.includes(el.dataset.consentCategory) && el.dataset.consentCategory !== 'necessary' && !released) {
						blockScript(el);
					}
					el.querySelectorAll?.('script[data-consent-category]').forEach((s) => {
						if (CATEGORIES.includes(s.dataset.consentCategory) && s.dataset.consentCategory !== 'necessary' && !released && !s.dataset.iikitiConsentBlocked) {
							blockScript(s);
						}
					});
				}
			}
		});
		observer.observe(document.documentElement || document, { childList: true, subtree: true });
	}

	function blockExistingScripts() {
		document.querySelectorAll('script[data-consent-category]').forEach((el) => {
			blockScript(el);
		});
	}

	async function loadBanner() {
		if (window.iikiti?.consent?.bannerLoaded) {
			return window.iikiti.consent.bannerLoaded;
		}
		// Prefer an already-registered mount API (consent-banner.js loaded on
		// the page); otherwise lazy-load it via the JS loader.
		if (window.iikiti?.consentBanner?.mount) {
			window.iikiti.consent.bannerLoaded = Promise.resolve();
			return window.iikiti.consent.bannerLoaded;
		}
		const bannerUrl = config.bannerUrl;
		const promise = window.iikiti.loader
			.loadScript(bannerUrl ? bannerUrl : 'consent-banner', { strategy: 'complete' })
			.then(() => {
				window.iikiti.consent.bannerLoaded = true;
				return window.iikiti.consent;
			});
		window.iikiti.consent.bannerLoaded = promise;
		return promise;
	}

	function showBanner() {
		const container = document.getElementById('iikiti-consent-banner');
		if (!container) {
			return;
		}
		container.style.display = '';
		void (async () => {
			await loadBanner();
			if (window.iikiti?.consentBanner?.mount) {
				window.iikiti.consentBanner.mount(container, async ({ analytics, marketing }) => {
					await applyConsent(
						{ necessary: true, analytics, marketing },
						state?.version ?? getConfigVersion()
					);
					hideBanner();
				});
			}
		})();
	}

	function hideBanner() {
		const container = document.getElementById('iikiti-consent-banner');
		if (container) {
			container.style.display = 'none';
		}
	}

	async function initialize() {
		readConfig();
		blockExistingScripts();
		installCreateElementInterceptor();
		installMutationObserver();

		const stored = readStored();
		const signals = detectSignals();
		let initial = { necessary: true, analytics: false, marketing: false };

		if (stored && stored.categories && stored.version === getConfigVersion()) {
			initial = stored.categories;
		} else if (stored && stored.categories) {
			// stale version: force re-consent, but keep defaults as starting point
			initial = getDefaultCategories();
		}

		state = {
			hasConsented: Boolean(stored && stored.version === getConfigVersion()),
			categories: initial,
			version: stored?.version ?? 0,
			configVersion: getConfigVersion(),
			stale: Boolean(!stored || stored.version !== getConfigVersion()),
			legalFramework: stored?.legalFramework ?? 'none',
			geoCountry: stored?.geoCountry ?? null,
			signals,
			proofToken: stored?.proofToken ?? null,
			consentedAt: stored?.consentedAt ?? null,
			userId: stored?.userId ?? null,
			sessionId: stored?.sessionId ?? null,
		};

		// Auto-select deny for analytics/marketing when a GPC/DNT signal is
		// present and the user has not previously consented.
		if (!state.hasConsented) {
			const signalForced = autoSelect(state);
			state.signals = { ...state.signals, ...signalForced };
		}

		if (!state.hasConsented) {
			showBanner();
		}

		// Fetch server-side consent state (may override local for logged-in users).
		try {
			const response = await fetch('/api/privacy/consent', {
				headers: { Accept: 'application/json' },
				credentials: 'include',
			});
			if (response.ok) {
				const server = await response.json();
				if (server.hasConsented) {
					state = {
						...state,
						hasConsented: true,
						categories: server.categories ?? state.categories,
						version: server.version ?? state.version,
						configVersion: server.configVersion ?? state.configVersion,
						stale: Boolean(server.stale),
						legalFramework: server.legalFramework ?? state.legalFramework,
						geoCountry: server.geoCountry ?? state.geoCountry,
						signals: server.signals ?? state.signals,
						proofToken: server.proofToken ?? state.proofToken,
						consentedAt: server.consentedAt ?? state.consentedAt,
						userId: server.userId ?? state.userId,
						sessionId: server.sessionId ?? state.sessionId,
					};
					writeStored(state);
					if (state.stale) {
						showBanner();
					} else {
						hideBanner();
						releaseBlocked();
					}
					emitChange();
					dispatchConsentMode();
				}
			}
		} catch {
			// ignore network errors; local state stands
		}
	}

	const api = {
		getState() {
			return state ? { ...state } : null;
		},
		isAllowed(category) {
			return Boolean(state?.categories[category] ?? false);
		},
		allow(category) {
			if (!state) {
				return;
			}
			void applyConsent({ ...state.categories, [category]: true }, state.version);
		},
		deny(category) {
			if (!state) {
				return;
			}
			void applyConsent({ ...state.categories, [category]: false }, state.version);
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
		getConfig,
		getConfigVersion,
		sessionId: function () {
			return ensureSessionId();
		},
	};

	window.iikiti = window.iikiti || {};
	window.iikiti.consent = api;

	// Defer initialization until the DOM is ready so config and scripts are present.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})();
