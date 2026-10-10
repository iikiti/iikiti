import { test, expect } from 'bun:test';
import {
	hasChildSlot,
	collectContainerIds,
	toggleNode,
	toggleSubtree,
	setAll,
	isAllExpanded,
	parseStoredExpanded,
	pruneExpanded,
} from '../../assets/svelte/editor/layerTree.js';

const blockTypes = {
	container: { acceptsChildren: true },
	heading: { acceptsChildren: false },
	list: { acceptsChildren: true },
};

/** main: [A container{ a1 heading, B container{ b1 container{} } }, H heading] */
function sampleTree() {
	return {
		main: [
			{
				id: 'A',
				type: 'container',
				children: [
					{ id: 'a1', type: 'heading' },
					{
						id: 'B',
						type: 'container',
						children: [{ id: 'b1', type: 'container', children: [] }],
					},
				],
			},
			{ id: 'H', type: 'heading' },
		],
	};
}

test('hasChildSlot is true only for acceptsChildren types, even when empty', () => {
	expect(hasChildSlot('container', blockTypes)).toBe(true);
	expect(hasChildSlot('heading', blockTypes)).toBe(false);
	expect(hasChildSlot('unknown', blockTypes)).toBe(false);
});

test('collectContainerIds lists every container in the tree, nested included', () => {
	const ids = collectContainerIds(sampleTree(), blockTypes).sort();
	expect(ids).toEqual(['A', 'B', 'b1']);
});

test('toggleNode adds then removes a single id without mutating the input', () => {
	const start = new Set(['A']);
	const opened = toggleNode(start, 'B');
	expect([...opened].sort()).toEqual(['A', 'B']);
	expect([...start]).toEqual(['A']);
	expect([...toggleNode(opened, 'A')]).toEqual(['B']);
});

test('toggleSubtree opens the node and every descendant container', () => {
	const node = sampleTree().main[0]; // A
	const opened = toggleSubtree(new Set(), node, true, blockTypes);
	expect([...opened].sort()).toEqual(['A', 'B', 'b1']);
});

test('toggleSubtree closes the node and every descendant container', () => {
	const node = sampleTree().main[0];
	const start = new Set(['A', 'B', 'b1', 'other']);
	const closed = toggleSubtree(start, node, false, blockTypes);
	expect([...closed]).toEqual(['other']);
});

test('setAll with true selects every container; false clears', () => {
	expect([...setAll(sampleTree(), blockTypes, true)].sort()).toEqual(['A', 'B', 'b1']);
	expect(setAll(sampleTree(), blockTypes, false).size).toBe(0);
});

test('isAllExpanded reflects whether every container is open', () => {
	expect(isAllExpanded(new Set(['A', 'B', 'b1']), sampleTree(), blockTypes)).toBe(true);
	expect(isAllExpanded(new Set(['A']), sampleTree(), blockTypes)).toBe(false);
});

test('isAllExpanded is false for a tree with no containers', () => {
	const tree = { main: [{ id: 'H', type: 'heading' }] };
	expect(isAllExpanded(new Set(), tree, blockTypes)).toBe(false);
});

test('parseStoredExpanded tolerates missing, corrupt and non-array input', () => {
	expect(parseStoredExpanded(null)).toEqual(new Set());
	expect(parseStoredExpanded('not json{')).toEqual(new Set());
	expect(parseStoredExpanded('{"a":1}')).toEqual(new Set());
	expect(parseStoredExpanded('["A", 3, "B"]')).toEqual(new Set(['A', 'B']));
});

test('pruneExpanded drops ids no longer present in the tree', () => {
	const pruned = pruneExpanded(new Set(['A', 'gone']), sampleTree(), blockTypes);
	expect([...pruned]).toEqual(['A']);
});
