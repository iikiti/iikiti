/**
 * Consent banner entry (Svelte). Loaded lazily by the consent manager when the
 * banner needs to be shown. Defines `window.iikiti.consentBanner.mount()` and
 * registers itself with `window.iikiti.loader` so the consent manager can
 * load it on demand via `loadScript('consent-banner')`.
 */
import ConsentBanner from './svelte/consent/ConsentBanner.svelte';
import { mount, unmount } from 'svelte';

function getConfiguredCategories(documentRef = typeof document !== 'undefined' ? document : null) {
	const defaults = { necessary: true, analytics: false, marketing: false };
	const config = documentRef?.getElementById?.('iikiti-consent-config');
	if (!config) return defaults;
	try {
		const parsed = JSON.parse(config.textContent || '{}');
		const categories = parsed.default_categories;
		if (!categories || typeof categories !== 'object') return defaults;
		return {
			necessary: true,
			analytics: Boolean(categories.analytics),
			marketing: Boolean(categories.marketing),
		};
	} catch {
		return defaults;
	}
}

export function getInitialBannerCategories(
	windowRef = typeof window !== 'undefined' ? window : null,
	documentRef = typeof document !== 'undefined' ? document : null,
) {
	const state = windowRef?.iikiti?.consent?.getState?.();
	if (state && state.hasConsented === true && state.stale !== true && state.categories && typeof state.categories === 'object') {
		return {
			necessary: true,
			analytics: Boolean(state.categories.analytics),
			marketing: Boolean(state.categories.marketing),
		};
	}

	return getConfiguredCategories(documentRef);
}

export function createBannerMounter({
	mountComponent = mount,
	unmountComponent = unmount,
	component = ConsentBanner,
	windowRef = typeof window !== 'undefined' ? window : null,
	documentRef = typeof document !== 'undefined' ? document : null,
} = {}) {
	let mounted = null;

	return function mountBanner(container, onSave) {
		if (mounted) {
			mounted.destroy();
			mounted = null;
		}
		container.replaceChildren();
		const instance = mountComponent(component, {
			target: container,
			props: { onSave, categories: getInitialBannerCategories(windowRef, documentRef) },
		});
		mounted = { destroy: () => unmountComponent(instance), instance };
		return mounted;
	};
}

function registerLibrary() {
	if (typeof document === 'undefined' || typeof window === 'undefined') return;
	const script = document.currentScript;
	const url = script ? script.src : '';
	if (url && window.iikiti?.loader?.registerLibrary) {
		window.iikiti.loader.registerLibrary('consent-banner', { url, type: 'js' });
	}
}

registerLibrary();

if (typeof window !== 'undefined') {
	window.iikiti = window.iikiti || {};
	window.iikiti.consentBanner = { mount: createBannerMounter() };
}
