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
		if (document.querySelector(`link[href="${CSS.escape(url)}"]`)) return;
		await onceEl(url, 'link', { href: url });
	},
	async loadDeps(spec) {
		if (!spec.deps?.length) return;
		for (const dep of spec.deps) {
			await loader.loadScript(dep);
		}
	},
};

/**
 * Resolves a load condition to a boolean.
 *
 * Accepts a boolean, a function returning a boolean (or a promise of one), or a
 * string expression. String expressions are compiled with `new Function`, so
 * they run as code: only pass developer-authored strings, never user input.
 *
 * @param {boolean|string|(() => boolean|Promise<boolean>)} condition
 * @returns {Promise<boolean>}
 */
export async function resolveCondition(condition) {
	if (typeof condition === 'boolean') return condition;
	if (typeof condition === 'function') return Boolean(await condition());
	if (typeof condition === 'string') {
		const evaluate = new Function(`return (${condition});`);
		return Boolean(await evaluate());
	}
	throw new TypeError('loadIf condition must be a boolean, function or expression string');
}

/**
 * Loads a library only when the condition is true. A false condition resolves
 * without touching the DOM, so the library is never fetched.
 *
 * @param {string} nameOrUrl
 * @param {boolean|string|(() => boolean|Promise<boolean>)} condition
 * @param {object} [opts]
 * @returns {Promise<boolean>} true if the library was loaded
 */
export async function loadIf(nameOrUrl, condition, opts = {}) {
	if (!(await resolveCondition(condition))) return false;
	await loadWithStrategy(nameOrUrl, opts);
	return true;
}

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
