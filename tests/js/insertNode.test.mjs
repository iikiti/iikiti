import { test, expect } from 'bun:test';
import { insertNode } from '../../assets/svelte/editor/state.js';

function sampleTree() {
	return {
		main: [
			{ id: 'A', type: 'container', children: [{ id: 'a1', type: 'heading' }] },
			{ id: 'B', type: 'heading' },
		],
	};
}

test('insertNode does not mutate the input tree at the root', () => {
	const tree = sampleTree();
	const snapshot = JSON.parse(JSON.stringify(tree));
	insertNode(tree, 'main', null, { id: 'N', type: 'container', children: [] }, 0);
	expect(tree).toEqual(snapshot);
});

test('insertNode does not mutate the input tree when nesting', () => {
	const tree = sampleTree();
	const snapshot = JSON.parse(JSON.stringify(tree));
	insertNode(tree, 'main', 'A', { id: 'N', type: 'heading' }, 1);
	expect(tree).toEqual(snapshot);
});

test('insertNode creates a missing region without adding it to the input tree', () => {
	const tree = sampleTree();
	const next = insertNode(tree, 'sidebar', null, { id: 'N', type: 'container', children: [] });
	expect(tree.sidebar).toBeUndefined();
	expect(next.sidebar.map((n) => n.id)).toEqual(['N']);
});

test('insertNode places the node at the requested child position', () => {
	const next = insertNode(sampleTree(), 'main', 'A', { id: 'N', type: 'heading' }, 0);
	expect(next.main[0].children.map((n) => n.id)).toEqual(['N', 'a1']);
});
