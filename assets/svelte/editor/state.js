import { derived, get, writable } from 'svelte/store';
import { notifications } from '../../js/iikiti/notifications.js';

/**
 * @typedef {object} BlockNode
 * @property {string} id
 * @property {string} type
 * @property {Record<string, unknown>} [content]
 * @property {Record<string, unknown>} [style]
 * @property {Record<string, unknown>} [element]
 * @property {Record<string, string>} [bindings] Per-content-field dynamic
 *                                               bindings (e.g. to a `query`
 *                                               block's result fields)
 * @property {BlockNode[]} [children]
 */

/**
 * @typedef {object} RegionInfo
 * @property {string} id
 * @property {string} role
 * @property {string} name
 * @property {string[]} allowed
 */

/**
 * @typedef {object} EditorState
 * @property {Record<string, unknown>} config
 * @property {Record<string, Record<string, unknown>>} blockTypes
 * @property {RegionInfo[]} regions
 * @property {Record<string, BlockNode[]>} tree
 * @property {string|null} selected
 * @property {boolean} dirty
 * @property {number} version
 * @property {Array<Record<string, BlockNode[]>>} history
 * @property {number} historyPos
 */

const DEFAULT_STATE = {
	config: {},
	blockTypes: {},
	regions: [],
	tree: {},
	selected: null,
	dirty: false,
	version: 0,
	history: [],
	historyPos: -1,
};

const state = writable({ ...DEFAULT_STATE });

export const tree = derived(state, ($) => $.tree);
export const selected = derived(state, ($) => $.selected);
export const blockTypes = derived(state, ($) => $.blockTypes);
export const regions = derived(state, ($) => $.regions);
export const canPublish = derived(state, ($) => $.config['canPublish']);
export const apiBase = derived(state, ($) => ($.config['apiBase'] || '/api'));
export const apiToken = derived(state, ($) => $.config['apiToken']);
export const dirty = derived(state, ($) => $.dirty);
export const canUndo = derived(state, ($) => $.historyPos > 0);
export const canRedo = derived(state, ($) => $.history.length > 0 && $.historyPos < $.history.length - 1);

/**
 * Whether the floating "Layers" navigator is open (toggled from the editor
 * toolbar; rendered by Editor.svelte).
 *
 * @type {import('svelte/store').Writable<boolean>}
 */
export const layersOpen = writable(false);

/**
 * The region currently being edited (defaults to the `main` region — the page
 * "content"). Non-active regions render as locked chrome; clicking one makes it
 * active. See Editor.svelte.
 *
 * @type {import('svelte/store').Writable<string|null>}
 */
export const activeRegion = writable(null);

/**
 * Insertion context for the shared "Add block" dialog. `null` = closed.
 *
 * - `regionId`  region to insert into (required)
 * - `parentId`  container block id, or `null` to insert at region root
 * - `position`  index within the parent's children (gap insertion); omit to append
 * - `allowedTypes` explicit list of insertable block types; empty = any
 *                (inline-category types are only offered through explicit lists)
 *
 * @typedef {object} AddBlockContext
 * @property {string} regionId
 * @property {string|null} parentId
 * @property {number} [position]
 * @property {string[]} allowedTypes
 *
 * @type {import('svelte/store').Writable<AddBlockContext|null>}
 */
export const addBlockDialog = writable(null);

/**
 * Open the shared Add block dialog at the given insertion point.
 *
 * @param {AddBlockContext} context
 */
export function openAddBlockDialog(context) {
	addBlockDialog.set(context);
}

export function closeAddBlockDialog() {
	addBlockDialog.set(null);
}

/**
 * Whether the Layout (shells) dialog is open. Separate from the add-block slot
 * so both dialogs can be opened without overwriting each other.
 *
 * @type {import('svelte/store').Writable<boolean>}
 */
export const layoutDialogOpen = writable(false);

export function openLayoutDialog() {
	layoutDialogOpen.set(true);
}

export function closeLayoutDialog() {
	layoutDialogOpen.set(false);
}

const blockElements = new Map();
export function registerBlock(id, el) {
	if (el) blockElements.set(id, el);
	else blockElements.delete(id);
}
export function getBlockElement(id) {
	return blockElements.get(id);
}

/**
 * @param {Record<string, unknown>} config
 * @param {Array<Record<string, unknown>>} blockTypesList
 */
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

	const mainRegion = regionList.find((r) => r.role === 'main');
	activeRegion.set(mainRegion?.id ?? regionList[0]?.id ?? null);

	/** @type {Window & { __iikitiSearch?: (id: string) => BlockNode | null }} */
	const w = window;
	w.__iikitiSearch = searchNode;
}

/**
 * @param {string} type
 * @param {Record<string, Record<string, unknown>>} bt
 * @returns {Record<string, unknown>}
 */
export function defaultContentFor(type, bt) {
	const schema = bt[type];
	if (!schema) return {};
	const defaults = {};
	for (const f of (schema.contentFields ?? []) || []) {
		if ('default' in f) defaults[f.key] = f.default;
	}
	return defaults;
}

/**
 * @param {string} type
 * @param {Record<string, Record<string, unknown>>} bt
 * @returns {Record<string, unknown>}
 */
export function defaultElementFor(type, bt) {
	const schema = bt[type];
	if (!schema) return {};
	const defaults = {};
	for (const f of (schema.elementFields ?? []) || []) {
		if ('default' in f) defaults[f.key] = f.default;
	}
	return defaults;
}

/**
 * @param {string} parentType
 * @param {Record<string, Record<string, unknown>>} bt
 * @returns {string[]}
 */
export function allowedChildTypes(parentType, bt) {
	const schema = bt[parentType];
	if (!schema) return [];
	if (schema.allowedChildTypes == null) {
		return schema.acceptsChildren ? Object.keys(bt) : [];
	}
	return schema.allowedChildTypes ?? [];
}

/** Only this block type may sit at the root of a region (see RootContainerRule). */
const ROOT_CONTAINER_TYPE = 'container';

/**
 * Insert a new block of `type` and return its generated node id (so callers can
 * select / scroll to it).
 *
 * @param {string} regionId
 * @param {string|null} parentId
 * @param {string} type
 * @param {number} [position]
 * @returns {string} the new block id
 */
export function addBlock(regionId, parentId, type, position) {
	const id = 'blk_' + crypto.randomUUID().slice(0, 12);
	// Root-level blocks must be containers (mirrors RootContainerRule on the server).
	if (!parentId && type !== ROOT_CONTAINER_TYPE) return null;
	state.update((s) => {
		/** @type {BlockNode} */
		const node = {
			id,
			type,
			content: defaultContentFor(type, s.blockTypes),
			element: defaultElementFor(type, s.blockTypes),
			children: s.blockTypes[type]?.acceptsChildren ? [] : undefined,
		};
		const next = insertNode(s.tree, regionId, parentId, node, position);
		return pushHistory(s, next);
	});
	return id;
}

/**
 * @param {string} id
 */
export function deleteBlock(id) {
	state.update((s) => {
		const next = removeNode(s.tree, id);
		return pushHistory(s, next);
	});
}

/**
 * @param {string} id
 * @param {string|null} toParent
 * @param {number} position
 */
export function moveBlock(id, toParent, position) {
	state.update((s) => {
		const { node, tree: afterRemove, regionId: srcRegion } = extractNode(s.tree, id);
		if (!node) return s;
		// Moving a non-container to the root would break the container-only rule.
		if (!toParent && node.type !== ROOT_CONTAINER_TYPE) return s;
		const targetRegion = toParent ? findRegionFor(toParent, s) : srcRegion;
		const next = insertNode(afterRemove, targetRegion, toParent, node, position);
		return pushHistory(s, next);
	});
}

/**
 * @param {EditorState} s
 * @param {Record<string, BlockNode[]>} next
 * @returns {EditorState}
 */
function pushHistory(s, next) {
	return { ...s, tree: next, dirty: true, history: [...s.history.slice(0, s.historyPos + 1), next], historyPos: s.history.length };
}

/**
 * @param {string|null} parentId
 * @param {EditorState} s
 * @returns {string}
 */
function findRegionFor(parentId, s) {
	if (!parentId) return s.regions[0]?.id ?? '';
	for (const [regionId, nodes] of Object.entries(s.tree)) {
		if (findNode(nodes, parentId)) return regionId;
	}
	return s.regions[0]?.id ?? '';
}

/**
 * @param {Record<string, BlockNode[]>} tree
 * @param {string} regionId
 * @param {string|null} parentId
 * @param {BlockNode} node
 * @param {number} [position]
 * @returns {Record<string, BlockNode[]>}
 */
function insertNode(tree, regionId, parentId, node, position) {
	if (!tree[regionId]) tree[regionId] = [];

	if (!parentId) {
		const list = [...(tree[regionId] || [])];
		if (typeof position === 'number') list.splice(position, 0, node);
		else list.push(node);
		return { ...tree, [regionId]: list };
	}

	return { ...tree, [regionId]: tree[regionId].map((n) => insertChild(n, parentId, node, position)) };
}

/**
 * @param {BlockNode} node
 * @param {string} parentId
 * @param {BlockNode} newNode
 * @param {number} [position]
 * @returns {BlockNode}
 */
function insertChild(node, parentId, newNode, position) {
	if (node.id === parentId) {
		const children = [...(node.children || [])];
		if (typeof position === 'number') children.splice(position, 0, newNode);
		else children.push(newNode);
		return { ...node, children };
	}
	if (node.children) {
		return { ...node, children: node.children.map((c) => insertChild(c, parentId, newNode, position)) };
	}
	return node;
}

/**
 * @param {Record<string, BlockNode[]>} tree
 * @param {string} id
 * @returns {Record<string, BlockNode[]>}
 */
function removeNode(tree, id) {
	const out = {};
	for (const [regionId, nodes] of Object.entries(tree)) {
		out[regionId] = (nodes || []).filter((n) => n.id !== id).map((n) => removeChild(n, id));
	}
	return out;
}

/**
 * @param {BlockNode} node
 * @param {string} id
 * @returns {BlockNode}
 */
function removeChild(node, id) {
	if (!node.children) return node;
	return {
		...node,
		children: node.children.filter((c) => c.id !== id).map((c) => removeChild(c, id)),
	};
}

/**
 * @param {Record<string, BlockNode[]>} tree
 * @param {string} id
 * @returns {{ node: BlockNode | null, tree: Record<string, BlockNode[]>, regionId: string }}
 */
function extractNode(tree, id) {
	for (const regionId of Object.keys(tree)) {
		const nodes = tree[regionId] || [];
		const found = extractFromList(nodes, id);
		if (found.node) {
			return { node: found.node, tree: { ...tree, [regionId]: found.list }, regionId };
		}
	}
	return { node: null, tree, regionId: '' };
}

/**
 * @param {BlockNode[]} nodes
 * @param {string} id
 * @returns {{ node: BlockNode | null, list: BlockNode[] }}
 */
function extractFromList(nodes, id) {
	for (let i = 0; i < nodes.length; i++) {
		if (nodes[i].id === id) {
			return { node: nodes[i], list: nodes.slice(0, i).concat(nodes.slice(i + 1)) };
		}
	}
	for (let i = 0; i < nodes.length; i++) {
		if (nodes[i].children) {
			const found = extractFromList(nodes[i].children, id);
			if (found.node) {
				return { node: found.node, list: nodes.map((n) => n === nodes[i] ? { ...n, children: found.list } : n) };
			}
		}
	}
	return { node: null, list: nodes };
}

/**
 * @param {BlockNode[] | undefined} nodes
 * @param {string} id
 * @returns {BlockNode | null}
 */
export function findNode(nodes, id) {
	if (!nodes) return null;
	for (const n of nodes) {
		if (n.id === id) return n;
		if (n.children) {
			const found = findNode(n.children, id);
			if (found) return found;
		}
	}
	return null;
}

/**
 * @param {string} id
 * @returns {BlockNode | null}
 */
export function searchNode(id) {
	const t = get(tree);
	for (const regionId of Object.keys(t)) {
		const found = findNode(t[regionId], id);
		if (found) return found;
	}
	return null;
}

/**
 * Ancestor path to a node (region root → … → node), or null when not found.
 *
 * @param {string} id
 * @returns {{ regionId: string, path: BlockNode[] } | null}
 */
export function pathToNode(id) {
	const t = get(tree);
	// Single-entry memo: sidebar decorators ask for the same node's path once per
	// field on every render, while the tree object only changes on edits.
	if (pathMemo && pathMemo.tree === t && pathMemo.id === id) return pathMemo.result;
	let result = null;
	for (const regionId of Object.keys(t)) {
		const path = [];
		if (walkPath(t[regionId], id, path)) {
			result = { regionId, path };
			break;
		}
	}
	pathMemo = { tree: t, id, result };
	return result;
}

/** @type {{ tree: unknown, id: string, result: ReturnType<typeof pathToNode> } | null} */
let pathMemo = null;

/**
 * Nearest enclosing `query` block of a node (never the node itself), or null.
 *
 * @param {BlockNode | null | undefined} node
 * @returns {BlockNode | null}
 */
export function queryAncestor(node) {
	if (!node?.id) return null;
	const found = pathToNode(node.id);
	if (!found) return null;
	for (let i = found.path.length - 1; i >= 0; i--) {
		const ancestor = found.path[i];
		if (ancestor.type === 'query' && ancestor.id !== node.id) return ancestor;
	}
	return null;
}

/**
 * Explicit insertable types for a region: its `allowed_types`, empty = any.
 *
 * @param {string} regionId
 * @param {RegionInfo[]} regs
 * @returns {string[]}
 */
export function regionAllowedTypes(regionId, regs) {
	return regs.find((r) => r.id === regionId)?.allowed ?? [];
}

/**
 * @param {BlockNode[] | undefined} nodes
 * @param {string} id
 * @param {BlockNode[]} path
 * @returns {boolean}
 */
function walkPath(nodes, id, path) {
	if (!nodes) return false;
	for (const n of nodes) {
		path.push(n);
		if (n.id === id) return true;
		if (walkPath(n.children, id, path)) return true;
		path.pop();
	}
	return false;
}

/**
 * @param {RegionInfo[]} regs
 * @returns {Record<string, BlockNode[]>}
 */
function parseRegions(regs) {
	const out = {};
	for (const r of regs) {
		const root = document.querySelector(`[data-component="BlockEditorComponent"][data-region-id="${CSS.escape(r.id)}"]`);
		if (!root) continue;
		out[r.id] = parseNodes(root);
	}
	return out;
}

/**
 * @param {Element} container
 * @returns {BlockNode[]}
 */
function parseNodes(container) {
	const nodes = [];
	for (const el of container.querySelectorAll(':scope > [data-block-type]')) {
		nodes.push(parseNode(el));
	}
	return nodes;
}

/**
 * @param {Element} el
 * @returns {BlockNode}
 */
function parseNode(el) {
	const type = el.getAttribute('data-block-type') || 'unknown';
	const id = el.getAttribute('data-block-id') || '';
	const content = safeJson(el.getAttribute('data-block-content') || null);
	const style = safeJson(el.getAttribute('data-block-style') || null);
	const element = safeJson(el.getAttribute('data-block-element') || null);
	const bindings = safeJson(el.getAttribute('data-block-bindings') || null);
	// `query` blocks render their children per result item, so their child
	// template is hydrated from the dedicated first-item marker — a plain
	// `[data-block-children]` deep scan would absorb a nested container's
	// children wrapper (and flatten one level).
	const marker = type === 'query' ? '[data-block-item-children]' : '[data-block-children]';
	const childrenWrap = el.querySelector(marker);
	const children = childrenWrap ? parseNodes(childrenWrap) : undefined;
	return { id, type, content, style, element, bindings, children };
}

/**
 * @param {string | null} raw
 * @returns {Record<string, unknown>}
 */
function safeJson(raw) {
	if (!raw) return {};
	try {
		const v = JSON.parse(raw);
		return v && typeof v === 'object' ? v : {};
	} catch {
		return {};
	}
}

/**
 * @param {Record<string, BlockNode[]>} next
 */
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

/**
 * @param {string | null} id
 */
export function select(id) {
	state.update((s) => ({ ...s, selected: id }));
}

/**
 * @param {string} id
 * @param {Partial<BlockNode>} patch
 */
export function updateNode(id, patch) {
	state.update((s) => ({ ...s, tree: updateRecursive(s.tree, id, patch), dirty: true }));
}

/**
 * @param {Record<string, BlockNode[]>} tree
 * @param {string} id
 * @param {Partial<BlockNode>} patch
 * @returns {Record<string, BlockNode[]>}
 */
function updateRecursive(tree, id, patch) {
	const out = {};
	for (const region of Object.keys(tree)) {
		out[region] = (tree[region] || []).map((n) => applyPatch(n, id, patch));
	}
	return out;
}

/**
 * @param {BlockNode} node
 * @param {string} id
 * @param {Partial<BlockNode>} patch
 * @returns {BlockNode}
 */
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

/**
 * @param {string | undefined} token
 * @returns {Record<string, string>}
 */
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
