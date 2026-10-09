/**
 * Front-end editor sidebar extension API.
 *
 * The base build registers its tabs (Content / Element / Style) and every
 * field control through this module, and plugin editor UI bundles use the exact
 * same functions. Exposed to plugins as `window.iikiti.editor.sidebar`.
 *
 * Model
 * -----
 * - A **section** is an ordered tab. Its `build(ctx)` returns a list of nodes.
 * - A **node** is one of:
 *     { kind: 'field',    field, path }               single schema field
 *     { kind: 'group',    id, label, header?, nodes } collapsible group
 *     { kind: 'repeater', id, label, fields, items, onChange, ... }
 * - A **field control** maps a schema field `type` to a Svelte component that
 *   receives `{ field, value, onChange }`.
 * - **Patch transformers** mutate a section's node list (add / remove / reorder
 *   settings) after the section builds it.
 */
import { writable, get } from 'svelte/store';
import { markReady } from '../../js/iikiti/ready.js';

/** @type {Array<{type:string, component:any, priority:number, seq:number}>} */
const fieldControls = [];
let fieldSeq = 0;

/**
 * Register (or override) the control component for a schema field type.
 *
 * @param {string} type schema field `type` (e.g. 'text', 'select', 'my-widget')
 * @param {any} component Svelte component receiving `{ field, value, onChange }`
 * @param {{ priority?: number }} [opts] higher priority wins; last wins on ties
 */
export function registerFieldControl(type, component, opts = {}) {
	fieldControls.push({
		type,
		component,
		priority: Number(opts.priority ?? 0),
		seq: ++fieldSeq,
	});
	bump();
}

/**
 * @param {string} type
 * @returns {any | null} highest-priority control component for `type`
 */
export function resolveFieldControl(type) {
	let best = null;
	for (const fc of fieldControls) {
		if (fc.type !== type) continue;
		if (!best || fc.priority > best.priority || (fc.priority === best.priority && fc.seq > best.seq)) {
			best = fc;
		}
	}
	return best?.component ?? null;
}

/** @type {Array<{id:string,label:string,icon?:string,order?:number,build?:(ctx:any)=>any[]}>} */
const sections = [];

/**
 * Register (or replace) a sidebar section (tab).
 *
 * @param {{ id:string, label:string, icon?:string, order?:number, build?:(ctx:any)=>any[] }} section
 */
export function registerSection(section) {
	if (!section?.id) return;
	const idx = sections.findIndex((s) => s.id === section.id);
	if (idx >= 0) sections[idx] = section;
	else sections.push(section);
	bump();
}

/** @returns {Array<{id:string,label:string,order:number}>} ordered sections */
export function getSections() {
	return [...sections].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
}

/** @type {Array<{sectionId:string, transformer:(nodes:any[],ctx:any)=>any[], priority:number, seq:number}>} */
const patches = [];
let patchSeq = 0;

/**
 * Add/remove/reorder a section's nodes after it builds them.
 *
 * @param {string} sectionId
 * @param {(nodes:any[], ctx:any) => any[]} transformer
 * @param {{ priority?: number }} [opts]
 */
export function patchSection(sectionId, transformer, opts = {}) {
	patches.push({ sectionId, transformer, priority: Number(opts.priority ?? 0), seq: ++patchSeq });
	bump();
}

/**
 * Build a section's final node list (section.build → patches applied).
 *
 * @param {string} sectionId
 * @param {any} ctx
 * @returns {any[]}
 */
export function buildSectionNodes(sectionId, ctx) {
	const section = sections.find((s) => s.id === sectionId);
	if (!section) return [];
	let nodes = section.build ? section.build(ctx) ?? [] : [];
	const applicable = patches
		.filter((p) => p.sectionId === sectionId)
		.sort((a, b) => a.priority - b.priority || a.seq - b.seq);
	for (const p of applicable) {
		try {
			nodes = p.transformer(nodes, ctx) ?? nodes;
		} catch {
			/* a broken plugin transformer must not take down the sidebar */
		}
	}
	return nodes;
}

/**
 * Field decorators: small affordances rendered in the corner of any sidebar
 * field control (any `{kind:'field'}` node). Core uses them for query field
 * bindings; plugins can register their own.
 *
 * A decorator is `{ id, applies(ctx, fieldNode) → boolean, component }`. The
 * component renders inside the field wrapper and receives
 * `{ node, fieldNode, fieldKey, ctx }`.
 */
/** @type {Array<{decorator:{id:string,applies:(any,any)=>boolean,component:any},priority:number,seq:number}>} */
const fieldDecorators = [];
let decoratorSeq = 0;

/**
 * Register (or replace) a field decorator by id.
 *
 * @param {{ id:string, applies:(ctx:any, fieldNode:any) => boolean, component:any }} decorator
 * @param {{ priority?: number }} [opts] higher priority wins; last wins on ties
 */
export function registerFieldDecorator(decorator, opts = {}) {
	if (!decorator?.id) return;
	const entry = { decorator, priority: Number(opts.priority ?? 0), seq: ++decoratorSeq };
	const idx = fieldDecorators.findIndex((d) => d.decorator.id === decorator.id);
	if (idx >= 0) fieldDecorators[idx] = entry;
	else fieldDecorators.push(entry);
	bump();
}

/**
 * Active decorators for a field node (applies() true), ordered by priority.
 *
 * @param {any} fieldNode
 * @param {any} ctx
 * @returns {Array<{id:string, applies:(any,any)=>boolean, component:any}>}
 */
export function resolveFieldDecorators(fieldNode, ctx) {
	return fieldDecorators
		.filter((d) => {
			try {
				return Boolean(d.decorator.applies(ctx, fieldNode));
			} catch {
				/* a broken plugin decorator must not take down the sidebar */
				return false;
			}
		})
		.sort((a, b) => a.priority - b.priority || a.seq - b.seq)
		.map((d) => d.decorator);
}

/** Reactive version counter — the sidebar re-renders when registrations change. */
const versionStore = writable(0);
function bump() {
	versionStore.update((v) => v + 1);
}
/** @type {import('svelte/store').Readable<number>} */
export const sidebarVersion = { subscribe: versionStore.subscribe };

/** Currently active sidebar tab id (programmatically settable, e.g. by a tour). */
export const activeSectionId = writable('content');

/** @param {string} id */
export function setActiveSection(id) {
	activeSectionId.set(id);
}

/**
 * Expose the API on `window.iikiti.editor.sidebar` so plugin UI bundles can
 * register sections, field controls and patches.
 */
export function installSidebarApi() {
	if (typeof window === 'undefined') return;
	const w = /** @type {any} */ (window);
	w.iikiti = w.iikiti ?? {};
	w.iikiti.editor = w.iikiti.editor ?? {};
	w.iikiti.editor.sidebar = {
		registerFieldControl,
		resolveFieldControl,
		registerFieldDecorator,
		resolveFieldDecorators,
		registerSection,
		getSections,
		patchSection,
		buildSectionNodes,
		setActiveSection,
		activeSectionId,
		version: sidebarVersion,
	};
	// Convenience alias documented for plugin authors.
	w.iikiti.editor.registerSidebar = w.iikiti.editor.sidebar;
	markReady('editor.sidebar');
}

export { get };
