import { test, expect } from 'bun:test';
import { resolveDropZone, computeDropPlan, isSelfOrDescendant, canPlaceBlock } from '../../assets/svelte/editor/layerDrop.js';

const blockTypes = {
	container: { acceptsChildren: true, allowedChildTypes: null },
	heading: { acceptsChildren: false },
	text: { acceptsChildren: false },
	// Only accepts headings as children.
	list: { acceptsChildren: true, allowedChildTypes: ['heading'] },
};

/** Root: [A container, B heading, C container{ d1 heading, d2 text }] */
function sampleTree() {
	return {
		main: [
			{ id: 'A', type: 'container', children: [] },
			{ id: 'B', type: 'heading' },
			{
				id: 'C',
				type: 'container',
				children: [
					{ id: 'd1', type: 'heading' },
					{ id: 'd2', type: 'text' },
				],
			},
		],
	};
}

test('resolveDropZone splits non-container rows at the midline', () => {
	expect(resolveDropZone(10, 40, false)).toBe('above');
	expect(resolveDropZone(30, 40, false)).toBe('below');
});

test('resolveDropZone exposes an inside band for containers', () => {
	expect(resolveDropZone(5, 40, true)).toBe('above');
	expect(resolveDropZone(20, 40, true)).toBe('inside');
	expect(resolveDropZone(35, 40, true)).toBe('below');
});

test('isSelfOrDescendant detects cycles and self', () => {
	const tree = sampleTree();
	expect(isSelfOrDescendant(tree, 'C', 'C')).toBe(true);
	expect(isSelfOrDescendant(tree, 'C', 'd1')).toBe(true);
	expect(isSelfOrDescendant(tree, 'A', 'd1')).toBe(false);
});

test('reorder down within the same list shifts the index by one', () => {
	// Move A (index 0) below B (index 1): expected final order B, A, C.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'A', targetId: 'B', zone: 'below' });
	expect(plan).toEqual({ toParent: null, position: 1 });
});

test('reorder up within the same list uses the target index directly', () => {
	// Move C (container, index 2) above A (index 0): expected order C, A, B.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'C', targetId: 'A', zone: 'above' });
	expect(plan).toEqual({ toParent: null, position: 0 });
});

test('dropping a block into its own current slot is a no-op', () => {
	// B is at index 1; dropping it "above C" keeps it in place.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'B', targetId: 'C', zone: 'above' });
	expect(plan).toBeNull();
});

test('dropping a block below its immediate previous sibling is a no-op', () => {
	// d2 already sits directly after d1 inside C, so placing it "below" d1 is a no-op.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'd2', targetId: 'd1', zone: 'below' });
	expect(plan).toBeNull();
});

test('dropping onto itself is a no-op', () => {
	expect(computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'B', targetId: 'B', zone: 'above' })).toBeNull();
});

test('nesting into a container appends as the last child', () => {
	// Move B (heading) into A (container, no children yet).
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'B', targetId: 'A', zone: 'inside' });
	expect(plan).toEqual({ toParent: 'A', position: 0 });
});

test('nesting into a container that already holds the block adjusts for removal', () => {
	// d1 is already in C; "inside C" must land it last, i.e. index 1 post-removal.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'd1', targetId: 'C', zone: 'inside' });
	expect(plan).toEqual({ toParent: 'C', position: 1 });
});

test('moving a container into its own descendant is rejected', () => {
	expect(computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'C', targetId: 'd1', zone: 'above' })).toBeNull();
	expect(computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'C', targetId: 'C', zone: 'inside' })).toBeNull();
});

test('nesting is rejected when the parent does not allow the child type', () => {
	// Heading into a container whose allowedChildTypes excludes text.
	const tree = { main: [{ id: 'L', type: 'list', children: [] }, { id: 'T', type: 'text' }] };
	expect(computeDropPlan({ tree, blockTypes, draggedId: 'T', targetId: 'L', zone: 'inside' })).toBeNull();
});

test('a non-container cannot be placed at the region root', () => {
	// Heading dropped "above" a root container: root must be a container.
	const tree = { main: [{ id: 'A', type: 'container', children: [] }, { id: 'H', type: 'heading' }] };
	expect(computeDropPlan({ tree, blockTypes, draggedId: 'H', targetId: 'A', zone: 'above' })).toBeNull();
});

test('a container can be reordered at the region root', () => {
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'C', targetId: 'A', zone: 'above' });
	expect(plan).toEqual({ toParent: null, position: 0 });
});

test('dragging a block onto a nested child row resolves to that child, not its parent', () => {
	// d1 lives inside C; dropping onto d1 "below" must plan a sibling move in C.
	const plan = computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'A', targetId: 'd1', zone: 'below' });
	expect(plan).toEqual({ toParent: 'C', position: 1 });
});

test('an unknown dragged or target id yields no plan', () => {
	expect(computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'nope', targetId: 'A', zone: 'above' })).toBeNull();
	expect(computeDropPlan({ tree: sampleTree(), blockTypes, draggedId: 'A', targetId: 'nope', zone: 'above' })).toBeNull();
});

test('an empty allowedChildTypes list on a container means any child type (live schema shape)', () => {
	// CoreBlockTypeProvider ships containers with allowedChildTypes: [] ("any").
	const types = { container: { acceptsChildren: true, allowedChildTypes: [] }, icon: { acceptsChildren: false } };
	const tree = { main: [{ id: 'C', type: 'container', children: [{ id: 'I', type: 'icon' }] }] };
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'I', targetId: 'C', zone: 'inside' })).toEqual({ toParent: 'C', position: 0 });
});

test('a child can be reordered among siblings inside a container', () => {
	const types = { container: { acceptsChildren: true, allowedChildTypes: [] }, icon: { acceptsChildren: false }, text: { acceptsChildren: false } };
	const tree = { main: [{ id: 'C', type: 'container', children: [{ id: 'a', type: 'icon' }, { id: 'b', type: 'text' }, { id: 'c', type: 'icon' }] }] };
	// Move 'c' above 'a' (both inside C): expected position 0 within C.
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'c', targetId: 'a', zone: 'above' })).toEqual({ toParent: 'C', position: 0 });
	// Move 'a' below 'c' (same list, later index): shifts by one for the removal.
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'a', targetId: 'c', zone: 'below' })).toEqual({ toParent: 'C', position: 2 });
});

test('a child can be dragged out of its container to a root sibling only when it is a container', () => {
	const types = { container: { acceptsChildren: true, allowedChildTypes: [] }, icon: { acceptsChildren: false } };
	const tree = { main: [{ id: 'C', type: 'container', children: [{ id: 'I', type: 'icon' }] }, { id: 'D', type: 'container', children: [] }] };
	// Icon onto a root container's upper edge: root accepts only containers, so rejected.
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'I', targetId: 'D', zone: 'above' })).toBeNull();
});

test('a drop on the centre of a leaf row resolves above, so upward reorder is reachable', () => {
	// Users aim at the label (vertical centre). The centre must give "above".
	expect(resolveDropZone(18, 36, false)).toBe('above');
	expect(resolveDropZone(22, 36, false)).toBe("below");
});

test('moving a sibling above the one it sits under is a valid reorder inside a container', () => {
	const types = { container: { acceptsChildren: true, allowedChildTypes: [] }, icon: { acceptsChildren: false }, text: { acceptsChildren: false } };
	const tree = { main: [{ id: 'C', type: 'container', children: [{ id: 'I', type: 'icon' }, { id: 'T', type: 'text' }] }] };
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'T', targetId: 'I', zone: 'above' })).toEqual({ toParent: 'C', position: 0 });
});

test('fieldset accepts one Legend only at the first child position', () => {
	const types = {
		container: { acceptsChildren: true, allowedChildTypes: [] },
		form: { acceptsChildren: true, allowedChildTypes: ['input', 'fieldset'] },
		fieldset: { acceptsChildren: true, allowedChildTypes: ['legend', 'input'] },
		legend: { acceptsChildren: false },
		input: { acceptsChildren: false },
	};
	const emptyFieldset = { main: [{ id: 'root', type: 'container', children: [{ id: 'form', type: 'form', children: [{ id: 'set', type: 'fieldset', children: [] }] }] }] };
	const fieldsetWithLegend = { main: [{ id: 'root', type: 'container', children: [{ id: 'form', type: 'form', children: [{ id: 'set', type: 'fieldset', children: [{ id: 'legend', type: 'legend' }, { id: 'input', type: 'input' }] }] }] }] };
	const fieldsetWithoutLegend = { main: [{ id: 'root', type: 'container', children: [{ id: 'form', type: 'form', children: [{ id: 'set', type: 'fieldset', children: [{ id: 'input', type: 'input' }] }] }] }] };

	expect(canPlaceBlock({ tree: emptyFieldset, blockTypes: types, type: 'legend', parentId: 'set', position: 0 })).toBe(true);
	expect(canPlaceBlock({ tree: fieldsetWithoutLegend, blockTypes: types, type: 'legend', parentId: 'set', position: 1 })).toBe(false);
	expect(canPlaceBlock({ tree: fieldsetWithLegend, blockTypes: types, type: 'legend', parentId: 'set', position: 1 })).toBe(false);
	expect(canPlaceBlock({ tree: fieldsetWithLegend, blockTypes: types, type: 'input', parentId: 'set', position: 0 })).toBe(false);
	expect(canPlaceBlock({ tree: emptyFieldset, blockTypes: types, type: 'legend', parentId: 'root', position: 0 })).toBe(false);
});

test('moving Legend before a fieldset control is allowed but moving it after is rejected', () => {
	const types = {
		container: { acceptsChildren: true, allowedChildTypes: [] },
		form: { acceptsChildren: true, allowedChildTypes: ['input', 'fieldset'] },
		fieldset: { acceptsChildren: true, allowedChildTypes: ['legend', 'input'] },
		legend: { acceptsChildren: false },
		input: { acceptsChildren: false },
	};
	const tree = { main: [{ id: 'root', type: 'container', children: [{ id: 'form', type: 'form', children: [{ id: 'set', type: 'fieldset', children: [{ id: 'input', type: 'input' }, { id: 'legend', type: 'legend' }] }] }] }] };

	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'legend', targetId: 'input', zone: 'above' })).toEqual({ toParent: 'set', position: 0 });
	expect(computeDropPlan({ tree, blockTypes: types, draggedId: 'legend', targetId: 'input', zone: 'below' })).toBeNull();
});

test('forms cannot be inserted under any ancestor Form', () => {
	const types = {
		container: { acceptsChildren: true, allowedChildTypes: [] },
		form: { acceptsChildren: true, allowedChildTypes: ['input', 'fieldset'] },
		input: { acceptsChildren: false },
	};
	const tree = { main: [{ id: 'root', type: 'container', children: [{ id: 'outer', type: 'form', children: [{ id: 'nested-container', type: 'container', children: [] }] }] }] };

	expect(canPlaceBlock({ tree, blockTypes: types, type: 'form', parentId: 'nested-container', position: 0 })).toBe(false);
});

test('Button can be inserted in generic containers, Forms, and Fieldsets', () => {
	const types = {
		container: { acceptsChildren: true, allowedChildTypes: [] },
		form: { acceptsChildren: true, allowedChildTypes: ['button', 'fieldset'] },
		fieldset: { acceptsChildren: true, allowedChildTypes: ['legend', 'button'] },
		button: { acceptsChildren: false },
	};
	const tree = { main: [{ id: 'root', type: 'container', children: [{ id: 'form', type: 'form', children: [{ id: 'fieldset', type: 'fieldset', children: [] }] }] }] };

	expect(canPlaceBlock({ tree, blockTypes: types, type: 'button', parentId: 'root', position: 1 })).toBe(true);
	expect(canPlaceBlock({ tree, blockTypes: types, type: 'button', parentId: 'form', position: 0 })).toBe(true);
	expect(canPlaceBlock({ tree, blockTypes: types, type: 'button', parentId: 'fieldset', position: 0 })).toBe(true);
});
