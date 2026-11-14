/**
 * Consent banner entry (Svelte). Loaded lazily by the consent manager when the
 * banner needs to be shown. Defines `window.iikiti.consentBanner.mount()` and
 * registers itself with `window.iikiti.loader` so the consent manager can
 * load it on demand via `loadScript('consent-banner')`.
 */
import ConsentBanner from './svelte/consent/ConsentBanner.svelte';
import { mount } from 'svelte';

let mounted = null;

function registerLibrary() {
	const script = document.currentScript;
	const url = script ? script.src : '';
	if (url && window.iikiti?.loader?.registerLibrary) {
		window.iikiti.loader.registerLibrary('consent-banner', { url, type: 'js' });
	}
}

function mountBanner(container, onSave) {
	if (mounted) {
		mounted.destroy();
		mounted = null;
	}
	container.innerHTML = '';
	const instance = mount(ConsentBanner, {
		target: container,
		props: { onSave },
	});
	mounted = { destroy: () => instance.$destroy(), instance };
	return mounted;
}

registerLibrary();

if (window.iikiti) {
	window.iikiti.consentBanner = { mount: mountBanner };
}
