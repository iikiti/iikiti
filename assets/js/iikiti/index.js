import { loader, loadWithStrategy, onInteraction } from './loader.js';
import { domReady, onLoad } from './domready.js';
import { notifications, settings as notificationSettings } from './notifications.js';
import { pluginRegistry, startPlugins } from './plugins.js';
import { components } from './components/registry.js';
import { applyStoredTheme, toggleTheme, resolveTheme, STORAGE_THEME_KEY } from './components/base.js';

const configEl = document.getElementById('iikiti-config');
const config = configEl ? JSON.parse((configEl.textContent || '{}') || '{}') : {};

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
      loadStyle: (url) => loader.loadStyle(url),
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
    theme: {
      apply: applyStoredTheme,
      toggle: toggleTheme,
      get: resolveTheme,
      STORAGE_THEME_KEY,
    },
  };
}

domReady.then(() => {
	document.documentElement.classList.add('js');
	startPlugins().catch(() => undefined);

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
		const id = region.dataset.regionId || '';
		if (region.querySelector('.iikiti-edit-trigger')) return;
		const btn = document.createElement('button');
		btn.className = 'iikiti-edit-trigger';
		btn.setAttribute('aria-label', `Edit ${id || 'content'}`);
		btn.title = `Edit ${id || 'content'}`;
		btn.innerHTML = '✏';
		btn.onclick = (e) => {
			e.stopPropagation();
			void launchEditor();
		};
		region.style.position = region.style.position || 'relative';
		region.prepend(btn);
	});
}

	let editorLaunched = false;
	function launchEditor() {
		if (editorLaunched) return Promise.resolve();
		editorLaunched = true;

		return Promise.resolve().then(async () => {
			const mod = await import('../../svelte/editor/mount.js');
			await mod.launchEditor(document.body, config);
		});
	}

window.iikiti.editor = { launch: launchEditor };

export { startPlugins };
