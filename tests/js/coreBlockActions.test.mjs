import { test, expect, beforeEach } from 'bun:test';
import { get } from 'svelte/store';
import { handleDeleteKey, isEditableTarget } from '../../assets/svelte/editor/coreBlockActions.js';
import { init, select, selected, tree, addBlock } from '../../assets/svelte/editor/state.js';

// Minimal DOM-free editor state: one region with a root container and a child.
const globalWindow = globalThis.window;
beforeEach(() => {
	globalThis.window = globalThis.window ?? {};
	globalThis.document = { querySelectorAll: () => [] };
	init({}, [{ type: 'container', acceptsChildren: true, allowedChildTypes: null }, { type: 'heading', acceptsChildren: false }]);
});

test('isEditableTarget flags inputs, textareas, selects and contenteditable', () => {
	expect(isEditableTarget({ tagName: 'INPUT' })).toBe(true);
	expect(isEditableTarget({ tagName: 'textarea' })).toBe(true);
	expect(isEditableTarget({ tagName: 'SELECT' })).toBe(true);
	expect(isEditableTarget({ tagName: 'DIV', isContentEditable: true })).toBe(true);
	expect(isEditableTarget({ tagName: 'DIV' })).toBe(false);
	expect(isEditableTarget(null)).toBe(false);
});

test('handleDeleteKey ignores keys other than Delete (Backspace is not bound)', () => {
	const id = addBlock('main', null, 'container');
	select(id);
	const event = { key: 'Backspace', target: { tagName: 'DIV' }, preventDefault() {} };
	expect(handleDeleteKey(event)).toBe(false);
	expect(get(tree).main.some((n) => n.id === id)).toBe(true);
});

test('handleDeleteKey removes the selected block and clears the selection', () => {
	const id = addBlock('main', null, 'container');
	select(id);
	const event = { key: 'Delete', target: { tagName: 'DIV' }, preventDefault() {} };
	expect(handleDeleteKey(event)).toBe(true);
	expect(get(tree).main.some((n) => n.id === id)).toBe(false);
	expect(get(selected)).toBe(null);
});

test('handleDeleteKey does nothing while typing in an editable field', () => {
	const id = addBlock('main', null, 'container');
	select(id);
	const event = { key: 'Delete', target: { tagName: 'INPUT' }, preventDefault() {} };
	expect(handleDeleteKey(event)).toBe(false);
	expect(get(tree).main.some((n) => n.id === id)).toBe(true);
});

test('handleDeleteKey does nothing when no block is selected', () => {
	select(null);
	const event = { key: 'Delete', target: { tagName: 'DIV' }, preventDefault() {} };
	expect(handleDeleteKey(event)).toBe(false);
});
