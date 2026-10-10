import { test, expect } from 'bun:test';
import {
	cloneForPaste,
	duplicateUserIds,
	isGeneratedId,
	pasteTarget,
} from '../../assets/svelte/editor/blockClipboard.js';

const blockTypes = {
	container: { acceptsChildren: true },
	heading: { acceptsChildren: false },
};

function sampleTree() {
	return {
		main: [
			{ id: 'blk_root', type: 'container', children: [{ id: 'blk_h1', type: 'heading' }] },
			{ id: 'hero', type: 'container', children: [] },
		],
		side: [{ id: 'blk_side', type: 'container', children: [] }],
	};
}

let counter = 0;
const makeId = () => `blk_new${++counter}`;

test('isGeneratedId recognises editor-generated ids only', () => {
	expect(isGeneratedId('blk_abc123')).toBe(true);
	expect(isGeneratedId('hero')).toBe(false);
});

test('cloneForPaste keeps user-defined ids and refreshes generated ids', () => {
	const source = { id: 'blk_gen', type: 'container', children: [{ id: 'hero-title', type: 'heading' }, { id: 'blk_inner', type: 'heading' }] };
	const copy = cloneForPaste(source, makeId);
	expect(copy.id).not.toBe('blk_gen');
	expect(copy.children[0].id).toBe('hero-title');
	expect(copy.children[1].id).not.toBe('blk_inner');
	// The source must be untouched.
	expect(source.id).toBe('blk_gen');
});

test('cloneForPaste returns a deep copy, not shared references', () => {
	const source = { id: 'hero', type: 'container', children: [{ id: 'x', type: 'heading' }] };
	const copy = cloneForPaste(source, makeId);
	copy.children.push({ id: 'y', type: 'heading' });
	expect(source.children.length).toBe(1);
});

test('duplicateUserIds flags user ids that occur more than once across regions', () => {
	const tree = { main: [{ id: 'dup', type: 'container', children: [{ id: 'dup', type: 'heading' }] }], side: [{ id: 'dup', type: 'container' }] };
	expect([...duplicateUserIds(tree)]).toEqual(['dup']);
});

test('duplicateUserIds never warns about generated ids', () => {
	const tree = { main: [{ id: 'blk_same', type: 'container' }, { id: 'blk_same', type: 'container' }] };
	expect(duplicateUserIds(tree).size).toBe(0);
});

test('pasting with a selected container appends as its last child', () => {
	const target = pasteTarget({ selectedId: 'hero', tree: sampleTree(), regionId: 'main', blockTypes, pastedType: 'heading' });
	expect(target).toEqual({ regionId: 'main', parentId: 'hero', position: 0 });
});

test('pasting with a selected non-container places a sibling after it', () => {
	const target = pasteTarget({ selectedId: 'blk_h1', tree: sampleTree(), regionId: 'main', blockTypes, pastedType: 'heading' });
	expect(target).toEqual({ regionId: 'main', parentId: 'blk_root', position: 1 });
});

test('pasting a non-container after a root-level selection is rejected', () => {
	// The selected root container is a container, so it nests; use a root non-container.
	const tree = { main: [{ id: 'blk_root', type: 'container', children: [] }, { id: 'loose', type: 'heading' }] };
	expect(pasteTarget({ selectedId: 'loose', tree, regionId: 'main', blockTypes, pastedType: 'heading' })).toBeNull();
});

test('pasting with nothing selected appends to the active region root', () => {
	const target = pasteTarget({ selectedId: null, tree: sampleTree(), regionId: 'main', blockTypes, pastedType: 'container' });
	expect(target).toEqual({ regionId: 'main', parentId: null, position: 2 });
});

test('pasting a non-container with nothing selected is rejected', () => {
	expect(pasteTarget({ selectedId: null, tree: sampleTree(), regionId: 'main', blockTypes, pastedType: 'heading' })).toBeNull();
});

test('an unknown selection falls back to the active region root', () => {
	const target = pasteTarget({ selectedId: 'missing', tree: sampleTree(), regionId: 'side', blockTypes, pastedType: 'container' });
	expect(target).toEqual({ regionId: 'side', parentId: null, position: 1 });
});

import { addBlockTarget } from '../../assets/svelte/editor/blockClipboard.js';

test('header Add block with a selected container targets its children', () => {
	const target = addBlockTarget({ selectedId: 'hero', tree: sampleTree(), regionId: 'main', blockTypes });
	expect(target).toEqual({ regionId: 'main', parentId: 'hero', position: 0, parentType: 'container' });
});

test('header Add block with a selected non-container inserts after it in the same parent', () => {
	const target = addBlockTarget({ selectedId: 'blk_h1', tree: sampleTree(), regionId: 'main', blockTypes });
	expect(target).toEqual({ regionId: 'main', parentId: 'blk_root', position: 1, parentType: 'container' });
});

test('header Add block with a selected root-level non-container falls back to the region root', () => {
	const tree = { main: [{ id: 'blk_root', type: 'container', children: [] }, { id: 'loose', type: 'heading' }] };
	const target = addBlockTarget({ selectedId: 'loose', tree, regionId: 'main', blockTypes });
	expect(target).toEqual({ regionId: 'main', parentId: null, position: 2, parentType: null });
});

test('header Add block with nothing selected targets the region root', () => {
	const target = addBlockTarget({ selectedId: null, tree: sampleTree(), regionId: 'main', blockTypes });
	expect(target).toEqual({ regionId: 'main', parentId: null, position: 2, parentType: null });
});
