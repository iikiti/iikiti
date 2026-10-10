/**
 * Core block actions (Copy / Paste / Delete). Registered through the same
 * public block-action API plugins use, so plugins can reorder or remove them.
 */
import { get } from 'svelte/store';
import { deleteBlock, copyBlock, pasteBlock, clipboard, select, selected, addBlockDialog, layoutDialogOpen } from './state.js';
import { registerBlockAction, installBlockActionsApi } from './blockActions.js';

let registered = false;

/**
 * Register the core actions once. Safe to call repeatedly.
 */
export function registerCoreBlockActions() {
	if (registered) return;
	registered = true;
	installBlockActionsApi();

	registerBlockAction({
		id: 'core.copy',
		label: 'Copy',
		order: 10,
		applies: (ctx) => Boolean(ctx.node),
		run: (ctx) => {
			copyBlock(ctx.node.id);
		},
	});

	registerBlockAction({
		id: 'core.paste',
		label: 'Paste',
		order: 20,
		applies: () => get(clipboard) !== null,
		run: (ctx) => {
			pasteBlock(ctx.node?.id ?? null, ctx.regionId);
		},
	});

	registerBlockAction({
		id: 'core.delete',
		label: 'Delete',
		order: 30,
		tone: 'destructive',
		applies: (ctx) => Boolean(ctx.node),
		run: (ctx) => {
			deleteSelectedOrNode(ctx.node.id);
		},
	});
}

/**
 * Delete a block and clear the selection when it was the selected one, so the
 * canvas and Layers never point at a removed block.
 *
 * @param {string} id
 */
export function deleteSelectedOrNode(id) {
	deleteBlock(id);
	if (get(selected) === id) select(null);
}

/**
 * Keyboard delete: removes the selected block. Ignores events from editable
 * fields so typing in the sidebar never deletes a block.
 *
 * @param {KeyboardEvent} event
 * @returns {boolean} true when a block was deleted
 */
export function handleDeleteKey(event) {
	if (event.key !== 'Delete') return false;
	if (isEditableTarget(event.target)) return false;
	// A modal dialog owns the keyboard while it is open.
	if (get(addBlockDialog) || get(layoutDialogOpen)) return false;
	const id = get(selected);
	if (!id) return false;
	event.preventDefault();
	deleteSelectedOrNode(id);
	return true;
}

/**
 * @param {EventTarget | null} target
 * @returns {boolean}
 */
export function isEditableTarget(target) {
	// Duck-typed so it works outside a browser (unit tests) as well as in one.
	const element = /** @type {any} */ (target);
	if (!element || typeof element !== 'object') return false;
	if (element.isContentEditable) return true;
	return ['INPUT', 'TEXTAREA', 'SELECT'].includes(String(element.tagName ?? '').toUpperCase());
}
