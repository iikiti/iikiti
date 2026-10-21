import { writable } from 'svelte/store';

/** @type {Array<{id:string,label:string,icon?:string,order:number,targetTypes:string[],build?:(ctx:any)=>any[],seq:number}>} */
const sections = [];
/** @type {Array<{sectionId:string,transformer:(nodes:any[],ctx:any)=>any[],priority:number,targetTypes:string[]|null,seq:number}>} */
const patches = [];
let sequence = 0;

const versionStore = writable(0);
export const settingsSectionVersion = { subscribe: versionStore.subscribe };
export const activeSettingsSectionId = writable('general');

function bump() {
	versionStore.update((value) => value + 1);
}

/**
 * Register or replace a settings tab. Tabs apply to both target types unless
 * `targetTypes` narrows them to `page` and/or `template`.
 *
 * @param {{id:string,label:string,icon?:string,order?:number,targetTypes?:string[],build?:(ctx:any)=>any[]}} section
 */
export function registerSettingsSection(section) {
	if (!section?.id || !section?.label) return;
	const targetTypes = [...new Set(section.targetTypes ?? ['page', 'template'])];
	if (targetTypes.length === 0 || targetTypes.some((type) => !['page', 'template'].includes(type))) return;
	for (let index = sections.length - 1; index >= 0; index--) {
		const existing = sections[index];
		if (existing.id !== section.id) continue;
		const remainingTypes = existing.targetTypes.filter((type) => !targetTypes.includes(type));
		if (remainingTypes.length === 0) sections.splice(index, 1);
		else existing.targetTypes = remainingTypes;
	}
	sections.push({ ...section, order: Number(section.order ?? 0), targetTypes, seq: ++sequence });
	bump();
}

/** @param {'page'|'template'} targetType */
export function getSettingsSections(targetType) {
	return sections
		.filter((section) => section.targetTypes.includes(targetType))
		.sort((a, b) => a.order - b.order || a.seq - b.seq)
		.map(({ id, label, icon, order }) => ({ id, label, icon, order }));
}

/**
 * Modify a settings tab's built nodes after its builder runs.
 *
 * @param {string} sectionId
 * @param {(nodes:any[],ctx:any)=>any[]} transformer
 * @param {{priority?:number,targetTypes?:string[]}} [options]
 */
export function patchSettingsSection(sectionId, transformer, options = {}) {
	if (!sectionId || typeof transformer !== 'function') return;
	const targetTypes = options.targetTypes ? [...new Set(options.targetTypes)] : null;
	if (targetTypes?.some((type) => !['page', 'template'].includes(type))) return;
	patches.push({ sectionId, transformer, priority: Number(options.priority ?? 0), targetTypes, seq: ++sequence });
	bump();
}

/** @param {'page'|'template'} targetType @param {string} sectionId @param {any} context */
export function buildSettingsSectionNodes(targetType, sectionId, context) {
	const section = sections.find((entry) => entry.id === sectionId && entry.targetTypes.includes(targetType));
	if (!section) return [];
	let nodes = [];
	try {
		const built = section.build?.(context);
		nodes = Array.isArray(built) ? built : [];
	} catch {
		return [];
	}
	const applicable = patches
		.filter((patch) => patch.sectionId === sectionId && (!patch.targetTypes || patch.targetTypes.includes(targetType)))
		.sort((a, b) => a.priority - b.priority || a.seq - b.seq);
	for (const patch of applicable) {
		try {
			const patched = patch.transformer(nodes, context);
			if (Array.isArray(patched)) nodes = patched;
		} catch {
			/* A broken plugin patch must not take down settings or other tabs. */
		}
	}
	return nodes;
}

/** @param {string} id */
export function setActiveSettingsSection(id) {
	activeSettingsSectionId.set(id);
}

export function resetSettingsSectionsForTests() {
	sections.length = 0;
	patches.length = 0;
	sequence = 0;
	activeSettingsSectionId.set('general');
	bump();
}
