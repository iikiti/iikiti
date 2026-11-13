const mountedPlaceholders = new WeakMap();
const MAX_ACTIVE_REQUESTS = 32;
let activeRequests = 0;
const requestWaiters = [];

/**
 * @typedef {{ id: string | null, css: string }} FragmentStyle
 * @typedef {{ html: string, styles: FragmentStyle[], [key: string]: unknown }} FragmentResult
 * @typedef {{ params?: Record<string, unknown>, target?: Element | string, signal?: AbortSignal }} FragmentLoadOptions
 * @typedef {{ provider: string, params?: Record<string, unknown>, id?: string, dependsOn?: string[] }} FragmentDescriptor
 */

/** Fetches a same-origin fragment, validates it, then applies its styles and HTML. */
export async function load(provider, options = {}) {
	if (typeof provider !== 'string' || provider.trim() === '') {
		throw new TypeError('Fragment provider must be a non-empty string.');
	}
	if (options.signal?.aborted) throw abortError();

	const url = new URL(`/_iikiti/fragments/${encodeURIComponent(provider)}`, window.location.href);
	url.searchParams.set('params', JSON.stringify(options.params ?? {}));
	const result = await withRequestSlot(async () => {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			signal: options.signal,
		});
		if (!response.ok) throw new Error(`Fragment request failed with status ${response.status}.`);

		const body = await response.json();
		validateResult(body);

		return body;
	}, options.signal);
	if (options.signal?.aborted) throw abortError();

	const target = resolveTarget(options.target);
	const template = target ? document.createElement('template') : null;
	if (template) template.innerHTML = result.html;
	installStyles(provider, result.styles);
	if (target) {
		if (options.signal?.aborted) throw abortError();
		target.replaceChildren(template.content);
	}

	return result;
}

async function withRequestSlot(operation, signal) {
	if (signal?.aborted) throw abortError();
	if (activeRequests < MAX_ACTIVE_REQUESTS) {
		activeRequests++;
	} else {
		await new Promise((resolve, reject) => {
			const waiter = { resolve, reject, signal, onAbort: null };
			if (signal) {
				waiter.onAbort = () => {
					const index = requestWaiters.indexOf(waiter);
					if (index !== -1) requestWaiters.splice(index, 1);
					reject(abortError());
				};
				signal.addEventListener('abort', waiter.onAbort, { once: true });
			}
			requestWaiters.push(waiter);
		});
	}

	try {
		return await operation();
	} finally {
		let handedOff = false;
		while (requestWaiters.length > 0) {
			const waiter = requestWaiters.shift();
			if (waiter.signal?.aborted) {
				waiter.signal.removeEventListener('abort', waiter.onAbort);
				waiter.reject(abortError());
				continue;
			}
			waiter.signal?.removeEventListener('abort', waiter.onAbort);
			waiter.resolve();
			handedOff = true;
			break;
		}
		if (!handedOff) activeRequests--;
	}
}

/** Mounts explicit JSON descriptors, respecting their declared dependencies. */
export async function mount(root = document) {
	const placeholders = [];
	if (root?.matches?.('[data-iikiti-fragment-descriptor]')) placeholders.push(root);
	placeholders.push(...(root?.querySelectorAll?.('[data-iikiti-fragment-descriptor]') ?? []));

	const entries = [];
	for (const element of placeholders) {
		try {
			const descriptor = parseDescriptor(element.getAttribute('data-iikiti-fragment-descriptor'));
			entries.push({ element, descriptor });
		} catch (error) {
			console.warn('Invalid iikiti fragment placeholder.', error);
		}
	}

	const entriesById = new Map();
	const duplicateIds = new Set();
	for (const entry of entries) {
		const { id } = entry.descriptor;
		if (!id) continue;
		if (entriesById.has(id)) duplicateIds.add(id);
		else entriesById.set(id, entry);
	}
	for (const id of duplicateIds) entriesById.delete(id);

	const tasks = new Map();
	function mountEntry(entry, ancestry = []) {
		if (entry.descriptor.id && ancestry.includes(entry.descriptor.id)) {
			return Promise.reject(new Error(`Circular fragment dependency: ${ancestry.concat(entry.descriptor.id).join(' -> ')}`));
		}
		const existing = mountedPlaceholders.get(entry.element);
		if (existing) return existing;

		const nextAncestry = entry.descriptor.id ? ancestry.concat(entry.descriptor.id) : ancestry;
		const task = Promise.all(entry.descriptor.dependsOn.map((id) => {
			if (duplicateIds.has(id)) return Promise.reject(new Error(`Fragment dependency "${id}" is ambiguous.`));
			const dependency = entriesById.get(id);
			if (!dependency) return Promise.reject(new Error(`Fragment dependency "${id}" was not found.`));
			return mountEntry(dependency, nextAncestry);
		})).then(() => load(entry.descriptor.provider, {
			params: entry.descriptor.params,
			target: entry.element,
		}));

		mountedPlaceholders.set(entry.element, task);
		task.catch(() => mountedPlaceholders.delete(entry.element));
		return task;
	}

	for (const entry of entries) tasks.set(entry.element, mountEntry(entry));
	return Promise.allSettled(entries.map(({ element }) => tasks.get(element)));
}

function validateResult(result) {
	if (!result || typeof result !== 'object' || typeof result.html !== 'string' || !Array.isArray(result.styles)) {
		throw new TypeError('Fragment response must include an html string and styles array.');
	}
	for (const style of result.styles) {
		if (!style || typeof style !== 'object'
			|| !(style.id === null || (typeof style.id === 'string' && style.id !== ''))
			|| typeof style.css !== 'string') {
			throw new TypeError('Fragment styles must contain a nullable id and css string.');
		}
	}
}

function installStyles(provider, styles) {
	const existing = [...document.querySelectorAll('style[data-iikiti-fragment-provider]')];
	const installed = new Set();
	for (const style of styles) {
		const identity = style.id === null ? `css:${style.css}` : `id:${style.id}`;
		if (installed.has(identity)) continue;
		installed.add(identity);

		const element = existing.find((candidate) =>
			candidate.dataset.iikitiFragmentProvider === provider
			&& candidate.dataset.iikitiFragmentStyleId === (style.id ?? '')
			&& (style.id !== null || candidate.textContent === style.css));
		if (element) {
			if (style.id !== null) element.textContent = style.css;
			continue;
		}

		const newElement = document.createElement('style');
		newElement.dataset.iikitiFragmentProvider = provider;
		newElement.dataset.iikitiFragmentStyleId = style.id ?? '';
		newElement.textContent = style.css;
		document.head.appendChild(newElement);
	}
}

function resolveTarget(target) {
	if (typeof target === 'string') {
		const element = document.querySelector(target);
		if (!element) throw new Error(`Fragment target "${target}" was not found.`);
		return element;
	}
	if (target == null) return null;
	if (typeof target.replaceChildren !== 'function') throw new TypeError('Fragment target must be an Element or selector.');
	return target;
}

function parseDescriptor(raw) {
	if (typeof raw !== 'string') throw new TypeError('Missing fragment descriptor.');
	const descriptor = JSON.parse(raw);
	if (!descriptor || typeof descriptor !== 'object' || Array.isArray(descriptor)
		|| typeof descriptor.provider !== 'string' || descriptor.provider.trim() === '') {
		throw new TypeError('Fragment descriptor requires a provider.');
	}
	if (descriptor.params !== undefined && (!descriptor.params || typeof descriptor.params !== 'object' || Array.isArray(descriptor.params))) {
		throw new TypeError('Fragment descriptor params must be an object.');
	}
	if (descriptor.id !== undefined && (typeof descriptor.id !== 'string' || descriptor.id === '')) {
		throw new TypeError('Fragment descriptor id must be a non-empty string.');
	}
	if (descriptor.dependsOn !== undefined && (!Array.isArray(descriptor.dependsOn) || !descriptor.dependsOn.every((id) => typeof id === 'string' && id !== ''))) {
		throw new TypeError('Fragment descriptor dependsOn must be an array of IDs.');
	}
	return {
		provider: descriptor.provider,
		params: descriptor.params ?? {},
		id: descriptor.id,
		dependsOn: descriptor.dependsOn ?? [],
	};
}

function abortError() {
	return new DOMException('The operation was aborted.', 'AbortError');
}
