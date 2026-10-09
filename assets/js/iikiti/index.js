import { loader, loadWithStrategy, loadIf, onInteraction } from './loader.js';
import { setSavedTimeZone } from './time/temporal.js';
import { domReady, onLoad } from './domready.js';
import { notifications, settings as notificationSettings } from './notifications.js';
import { pluginRegistry, startPlugins } from './plugins.js';
import { components } from './components/registry.js';
import { applyStoredTheme, toggleTheme, resolveTheme, STORAGE_THEME_KEY } from './components/base.js';
import { bars } from './chrome/bars.js';
import { installTour } from './tour.js';
import { markReady, whenReady } from './ready.js';

const configEl = document.getElementById('iikiti-config');
const config = configEl ? JSON.parse((configEl.textContent || '{}') || '{}') : {};

// Apply the signed-in user's saved time zone before any date is formatted.
setSavedTimeZone(config['timezone'] ?? null);

const notif = config['notifications'] || null;
if (notif) {
	notificationSettings.autoDismiss = notif['autoDismiss'] !== false;
	notificationSettings.durationSeconds = Number(notif['durationSeconds'] ?? notificationSettings.durationSeconds);
	notificationSettings.position = String(notif['position'] ?? notificationSettings.position);
}

if (!window.iikiti) {
  window.iikiti = {
    config,
    loader: {
      loadScript: (name, opts) => loadWithStrategy(name, opts),
      loadIf: (probe, polyfill) => loadIf(probe, polyfill),
      loadStyle: (url, opts) => loader.loadStyle(url, opts),
      registerLibrary: (name, spec) => loader.registerLibrary(name, spec),
    },
    domReady,
    onLoad,
    plugins: {
      register: (def) => pluginRegistry.register(def),
      list: () => pluginRegistry.list(),
    },
    notifications,
    editor: undefined,
    components,
    bars,
    theme: {
      apply: applyStoredTheme,
      toggle: toggleTheme,
      get: resolveTheme,
      STORAGE_THEME_KEY,
    },
    whenReady,
  };
}

// Tour/spotlight framework (registers built-in actions + window.iikiti.tour).
installTour();

// Readiness: these base components are usable as soon as their module has
// evaluated. Components that depend on DOM readiness are marked in domReady below.
markReady('components');
markReady('notifications');
markReady('theme');
markReady('tour');

const isTouchDevice = () => {
	if (typeof window === 'undefined') return false;
	return window.matchMedia('(hover: none), (pointer: coarse)').matches ||
		('ontouchstart' in window);
};

if (isTouchDevice()) {
	document.body.classList.add('iikiti-touch');
} else {
	document.body.classList.add('iikiti-hover');
}

domReady.then(() => {
	document.documentElement.classList.add('js');
	// Viewport bars standard: wire declarative bars (theme/plugin chrome)
	// before any late registrants (editor toolbar, plugin site_ui bundles).
	bars.start();
	bars.autoWire();
	markReady('bars');
	startPlugins()
		.then(() => markReady('plugins'))
		.catch(() => {
			// A failing plugin must not block readiness; the failure is already logged by its loader.
			markReady('plugins');
		});

	if (config['canEdit'] === true) {
		wireEditIcons();
		if (new URLSearchParams(window.location.search).has('edit')) {
			void launchEditor();
		}
	}

	wireWorkflows();
});

async function wireWorkflows() {
	const host = document.querySelector('[data-flow]');
	if (!host) return;
	try {
		const mod = await import('../../svelte/editor/workflows/mount.js');
		mod.launchWorkflow(host);
	} catch (e) {
		console.error('iikti workflow engine failed to load', e);
	}
}

function wireEditIcons() {
	const regions = document.querySelectorAll('[data-component="BlockEditorComponent"][data-region-id]');
	regions.forEach((region) => {
		if (region.querySelector('.iikiti-edit-trigger')) return;
		const id = region.dataset.regionId || '';
		const btn = document.createElement('button');
		btn.className = 'iikiti-edit-trigger';
		btn.setAttribute('aria-label', `Edit ${id || 'content'}`);
		btn.title = `Edit ${id || 'content'}`;
		btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L19 5a2.85 2.85 0 0 1 4 4L7.5 20.5"></path></svg>';
		btn.onclick = (e) => {
			e.preventDefault();
			e.stopPropagation();
			goToEditMode();
		};
		region.style.position = region.style.position || 'relative';
		region.prepend(btn);
	});
}

function goToEditMode() {
	const url = new URL(window.location);
	url.searchParams.set('edit', '');
	window.location = url;
}

let editorLaunched = false;
async function launchEditor() {
	if (editorLaunched) return;
	editorLaunched = true;

	// editor.css is already injected server-side for every page where the
	// editor can run (encore_entry_link_tags('editor') in layout.twig —
	// hashed in production builds), so no dynamic stylesheet load here.
	const mod = await import('../../svelte/editor/mount.js');
	await mod.launchEditor(document.body, config);
}

window.iikiti.editor = { launch: () => void launchEditor() };

export { startPlugins };
