/**
 * Block action registry for the editor's right-click menus (Layers menu and
 * block canvas). Plugins and core share one API: core Copy / Paste / Delete are
 * registered here like any plugin action. Exposed to plugins as
 * `window.iikiti.editor.blockActions`.
 *
 * Model
 * -----
 * - An **action** is `{ id, label, icon?, order?, blockTypes?, applies?, run }`.
 *   - `blockTypes`: array of block types the action targets. Omit it for a
 *     general action shown on every block.
 *   - `applies(ctx)`: optional extra visibility check.
 *   - `run(ctx)`: invoked when the item is chosen.
 * - `ctx` is `{ node, parentId, regionId, index }` for a right-clicked block,
 *   or `{ node: null, parentId: null, regionId, index: null }` for the empty
 *   area (only general actions that do not need a node apply there).
 * - A **patch** is `(items, ctx) => items` and can add, remove or reorder the
 *   resolved menu items (mirrors `patchSection` in extensions.js).
 *
 * Plugin callbacks run inside try/catch so a broken plugin cannot take down the
 * menu.
 */

import { markReady } from '../../js/iikiti/ready.js';

/** @type {Array<{ action: BlockAction, seq: number }>} */
const actions = [];
let actionSeq = 0;

/** @type {Array<{ transform: (items: any[], ctx: any) => any[], priority: number, seq: number }>} */
const patches = [];
let patchSeq = 0;

/**
 * @typedef {object} BlockActionContext
 * @property {any | null} node the right-clicked block node, or null for empty area
 * @property {string | null} parentId parent container id, or null at region root
 * @property {string} regionId region the block belongs to
 * @property {number | null} index index within the parent's children
 *
 * @typedef {object} BlockAction
 * @property {string} id
 * @property {string} label
 * @property {string} [icon]
 * @property {number} [order]
 * @property {'default' | 'destructive'} [tone]
 * @property {string[]} [blockTypes]
 * @property {(ctx: BlockActionContext) => boolean} [applies]
 * @property {(ctx: BlockActionContext) => void | Promise<void>} run
 */

/**
 * Register (or replace) a block action by id.
 *
 * @param {BlockAction} action
 * @returns {void}
 */
export function registerBlockAction(action) {
	if (!action?.id || typeof action.run !== 'function') return;
	const idx = actions.findIndex((entry) => entry.action.id === action.id);
	const entry = { action, seq: ++actionSeq };
	if (idx >= 0) actions[idx] = entry;
	else actions.push(entry);
}

/**
 * Remove a previously registered block action.
 *
 * @param {string} id
 */
export function unregisterBlockAction(id) {
	const idx = actions.findIndex((entry) => entry.action.id === id);
	if (idx >= 0) actions.splice(idx, 1);
}

/**
 * Add, remove or reorder the resolved items for a menu.
 *
 * @param {(items: any[], ctx: BlockActionContext) => any[]} transform
 * @param {{ priority?: number }} [opts] lower priority runs first
 */
export function patchBlockActions(transform, opts = {}) {
	patches.push({ transform, priority: Number(opts.priority ?? 0), seq: ++patchSeq });
}

/**
 * Whether an action applies to the given context.
 *
 * @param {BlockAction} action
 * @param {BlockActionContext} ctx
 * @returns {boolean}
 */
function appliesTo(action, ctx) {
	if (ctx.node) {
		if (action.blockTypes && !action.blockTypes.includes(ctx.node.type)) return false;
	} else if (action.blockTypes) {
		// Type-scoped actions need a block; they never show on the empty area.
		return false;
	}
	if (!action.applies) return true;
	try {
		return Boolean(action.applies(ctx));
	} catch {
		return false;
	}
}

/**
 * Resolve the menu items for a context: applicable actions, sorted by order
 * then registration, then passed through every patch. Returns plain items ready
 * for the ContextMenu component.
 *
 * @param {BlockActionContext} ctx
 * @returns {Array<{ id: string, label: string, icon?: string, tone?: string, onSelect: () => void }>}
 */
export function resolveBlockActions(ctx) {
	let items = actions
		.filter((entry) => appliesTo(entry.action, ctx))
		.sort((a, b) => (a.action.order ?? 0) - (b.action.order ?? 0) || a.seq - b.seq)
		.map((entry) => ({
			id: entry.action.id,
			label: entry.action.label,
			icon: entry.action.icon,
			tone: entry.action.tone,
			onSelect: () => runSafely(entry.action, ctx),
		}));

	for (const patch of patches.slice().sort((a, b) => a.priority - b.priority || a.seq - b.seq)) {
		try {
			items = patch.transform(items, ctx) ?? items;
		} catch {
			/* a broken plugin patch must not take down the menu */
		}
	}
	return items;
}

/**
 * @param {BlockAction} action
 * @param {BlockActionContext} ctx
 */
function runSafely(action, ctx) {
	try {
		const result = action.run(ctx);
		if (result && typeof (/** @type {any} */ (result)).catch === 'function') {
			(/** @type {Promise<void>} */ (result)).catch(() => undefined);
		}
	} catch {
		/* a broken plugin action must not take down the editor */
	}
}

/**
 * Expose the registry on `window.iikiti.editor.blockActions` for plugin UI bundles.
 * Marks `editor.blockActions` ready so plugins can `whenReady` it.
 */
export function installBlockActionsApi() {
	if (typeof window === 'undefined') return;
	const w = /** @type {any} */ (window);
	w.iikiti = w.iikiti ?? {};
	w.iikiti.editor = w.iikiti.editor ?? {};
	w.iikiti.editor.blockActions = {
		registerBlockAction,
		unregisterBlockAction,
		patchBlockActions,
		resolveBlockActions,
	};
	markReady('editor.blockActions');
}
