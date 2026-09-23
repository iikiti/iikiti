import { derived, get, writable } from 'svelte/store';
import { notifications } from '../../js/iikiti/notifications.js';

const state = writable({
	config: {},
	blockTypes: {},
	regions: [],
	tree: {},
	selected: null,
	dirty: false,
	version: 0,
	history: [],
	historyPos: -1,
});

export const tree = derived(state, ($) => $.tree);
export const selected = derived(state, ($) => $.selected);
export const blockTypes = derived(state, ($) => $.blockTypes);
export const regions = derived(state, ($) => $.regions);
export const canPublish = derived(state, ($) => $.config['canPublish']);
export const apiBase = derived(state, ($) => ($.config['apiBase'] || '/api'));
export const apiToken = derived(state, ($) => $.config['apiToken']);

const blockElements = new Map();
export function registerBlock(id, el) {
	if (el) blockElements.set(id, el);
	else blockElements.delete(id);
}
export function getBlockElement(id) {
	return blockElements.get(id);
}

export function init(config, blockTypesList) {
	const bt = {};
	for (const t of blockTypesList) bt[t.type] = t;
	const regionList = [];
	document
		.querySelectorAll('[data-component="BlockEditorComponent"][data-region-id]')
		.forEach((el) => {
			regionList.push({
				id: el.dataset.regionId || '',
				role: el.dataset.regionRole || '',
				name: el.dataset.regionName || '',
				allowed: (el.dataset.allowedTypes || '').split(',').map((s) => s.trim()).filter(Boolean),
			});
		});

	const parsed = parseRegions(regionList);
	state.set({
		config,
		blockTypes: bt,
		regions: regionList,
		tree: parsed,
		selected: null,
		dirty: false,
		version: Number(config['version'] ?? 0) || 0,
		history: [parsed],
		historyPos: 0,
	});
}

function parseRegions(regs) {
	const out = {};
	for (const r of regs) {
		const root = document.querySelector(`[data-component="BlockEditorComponent"][data-region-id="${CSS.escape(r.id)}"]`);
		if (!root) continue;
		out[r.id] = parseNodes(root);
	}
	return out;
}

function parseNodes(container) {
	const nodes = [];
	for (const el of container.querySelectorAll(':scope > [data-block-type]')) {
		nodes.push(parseNode(el));
	}
	return nodes;
}

function parseNode(el) {
	const type = el.getAttribute('data-block-type') || 'unknown';
	const id = el.getAttribute('data-block-id') || '';
	const content = safeJson(el.getAttribute('data-block-content') || null);
	const style = safeJson(el.getAttribute('data-block-style') || null);
	const childrenWrap = el.querySelector('[data-block-children]');
	const children = childrenWrap ? parseNodes(childrenWrap) : undefined;
	return { id, type, content, style, children };
}

function safeJson(raw) {
	if (!raw) return {};
	try {
		const v = JSON.parse(raw);
		return v && typeof v === 'object' ? v : {};
	} catch {
		return {};
	}
}

export function setTree(next) {
	state.update((s) => {
		s.tree = next;
		s.dirty = true;
		s.history = s.history.slice(0, s.historyPos + 1);
		s.history.push(next);
		s.historyPos = s.history.length - 1;
		return s;
	});
}

export function select(id) {
	state.update((s) => ({ ...s, selected: id }));
}

export function updateNode(id, patch) {
	state.update((s) => ({ ...s, tree: updateRecursive(s.tree, id, patch), dirty: true }));
}

function updateRecursive(tree, id, patch) {
	const out = {};
	for (const region of Object.keys(tree)) {
		out[region] = (tree[region] || []).map((n) => applyPatch(n, id, patch));
	}
	return out;
}

function applyPatch(node, id, patch) {
	if (node.id === id) return { ...node, ...patch };
	if (node.children) return { ...node, children: node.children.map((c) => applyPatch(c, id, patch)) };
	return node;
}

export function undo() {
	state.update((s) => {
		if (s.historyPos <= 0) return s;
		const pos = s.historyPos - 1;
		return { ...s, tree: s.history[pos], historyPos: pos, dirty: true };
	});
}

export function redo() {
	state.update((s) => {
		if (s.historyPos >= s.history.length - 1) return s;
		const pos = s.historyPos + 1;
		return { ...s, tree: s.history[pos], historyPos: pos, dirty: true };
	});
}

function authHeader(token) {
	return token ? { 'X-AUTH-TOKEN': token } : {};
}

export async function saveDraft() {
	const s = get(state);
	const res = await fetch(`${get(apiBase)}/editor/save`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'If-Match': String(s.version), ...authHeader(get(apiToken)) },
		body: JSON.stringify({ contextType: s.config['contextType'], contextId: s.config['contextId'], tree: s.tree }),
	});
	const data = await res.json().catch(() => ({ ok: false }));
	if (!res.ok || data.conflict) {
		notifications.notify({ message: data.conflict ? 'Conflict – reload and retry.' : 'Save failed', type: 'error' });
	} else {
		state.update((st) => ({ ...st, dirty: false, version: Number(data.version || st.version) }));
	}
	return data;
}

export async function publish() {
	const s = get(state);
	const res = await fetch(`${get(apiBase)}/editor/publish`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', ...authHeader(get(apiToken)) },
		body: JSON.stringify({ contextType: s.config['contextType'], contextId: s.config['contextId'] }),
	});
	const ok = res.ok;
	if (!ok) notifications.notify({ message: 'Publish failed', type: 'error' });
	return ok;
}
