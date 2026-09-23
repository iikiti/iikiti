/// <reference types="node" />
/**
 * Dependency+version-aware script/style loader with cached promises.
 *
 * @typedef {object} LibrarySpec
 * @property {string} [version]
 * @property {'js'|'css'} [type]
 * @property {string[]} [deps]
 * @property {string} url
 *
 * @typedef {object} LoadOptions
 * @property {'async'|'defer'|'complete'|'interaction'} [strategy]
 * @property {string[]} [deps]
 * @property {string} [version]
 */

/** @type {Record<string, LibrarySpec>} */
const libraries = {};
const pending = new Map();
const loaded = new Set();

function onceEl(url, tag, attrs) {
	const existing = pending.get(url);
	if (existing) return existing;

	const p = new Promise((resolve, reject) => {
		const el = document.createElement(tag);
		if (tag === 'script') {
			el.src = attrs.src;
			el.async = attrs.async === 'true';
			el.defer = attrs.defer === 'true';
			el.onload = () => resolve();
			el.onerror = () => reject(new Error(`Failed to load script: ${attrs.src}`));
		} else {
			el.rel = 'stylesheet';
			el.href = attrs.href;
			el.onload = () => resolve();
			el.onerror = () => reject(new Error(`Failed to load style: ${attrs.href}`));
		}
		el.dataset.iikitiLib = '1';
		document.head.appendChild(el);
	});

	pending.set(url, p);
	p.then(() => loaded.add(url)).catch(() => pending.delete(url));

	return p;
}

export const loader = {
	registerLibrary(name, spec) {
		libraries[name] = spec;
	},
	async loadScript(nameOrUrl, opts = {}) {
		const spec = libraries[nameOrUrl] ? libraries[nameOrUrl] : { url: nameOrUrl };
		const url = spec.url;

		if (loaded.has(url) || pending.has(url)) {
			await Promise.all([loader.loadDeps(spec), pending.get(url)]);
			return;
		}

		await loader.loadDeps(spec);
		await onceEl(url, 'script', {
			src: url,
			async: opts.strategy === 'async' ? 'true' : 'false',
			defer: opts.strategy === 'defer' ? 'true' : 'false',
		});
	},
	async loadStyle(url) {
		if (loaded.has(url)) return;
		await onceEl(url, 'link', { href: url });
	},
	async loadDeps(spec) {
		if (!spec.deps?.length) return;
		for (const dep of spec.deps) {
			await loader.loadScript(dep);
		}
	},
};

export function onInteraction() {
	return new Promise((resolve) => {
		const handler = () => {
			window.removeEventListener('pointerdown', handler);
			window.removeEventListener('keydown', handler);
			resolve();
		};
		window.addEventListener('pointerdown', handler);
		window.addEventListener('keydown', handler);
	});
}

export async function loadWithStrategy(nameOrUrl, opts = {}) {
	const strategy = opts.strategy ?? 'complete';
	if (strategy === 'interaction') {
		await onInteraction();
	}
	await loader.loadScript(nameOrUrl, opts);
}
