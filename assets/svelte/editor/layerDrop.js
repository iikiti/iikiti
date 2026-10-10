/**
 * Pure drop-planning for the Layers menu drag-and-drop. Kept free of Svelte and
 * DOM so the index math and validation can be unit-tested directly.
 *
 * A "plan" is the `{ toParent, position }` pair that `moveBlock` expects, where
 * `position` is the index in the destination list AFTER the dragged node has
 * been removed (see `moveBlock` in state.js).
 */

/** Only this block type may sit at the root of a region (mirrors state.js). */
const ROOT_CONTAINER_TYPE = 'container';

/**
 * Map a pointer position on a row to a drop zone.
 *
 * Container rows expose a middle "inside" band; other rows split at the midline.
 *
 * @param {number} offsetY pointer Y relative to the row top
 * @param {number} height row height
 * @param {boolean} acceptsChildren whether the target row is a container
 * @returns {'above' | 'below' | 'inside'}
 */
export function resolveDropZone(offsetY, height, acceptsChildren) {
	if (acceptsChildren) {
		if (offsetY < height * 0.25) return 'above';
		if (offsetY > height * 0.75) return 'below';
		return 'inside';
	}
	// Leaf rows split just below the midline: users aim at the label, which sits at
	// the centre, so the centre must resolve to "above" for reordering upwards to work.
	return offsetY < height * 0.6 ? 'above' : 'below';
}

/**
 * Locate a node's parent id (null = region root) and its index within that list.
 *
 * @param {Record<string, Array<{ id: string, children?: unknown[] }>>} tree
 * @param {string} id
 * @returns {{ regionId: string, parentId: string | null, index: number, siblings: Array<{ id: string, type: string }> } | null}
 */
export function locateNode(tree, id) {
	for (const regionId of Object.keys(tree)) {
		const found = locateInList(tree[regionId] ?? [], id, regionId, null);
		if (found) return found;
	}
	return null;
}

/**
 * @param {Array<{ id: string, children?: any[] }>} nodes
 * @param {string} id
 * @param {string} regionId
 * @param {string | null} parentId
 */
function locateInList(nodes, id, regionId, parentId) {
	for (let index = 0; index < nodes.length; index++) {
		const node = nodes[index];
		if (node.id === id) return { regionId, parentId, index, siblings: nodes };
		const nested = locateInList(node.children ?? [], id, regionId, node.id);
		if (nested) return nested;
	}
	return null;
}

/**
 * Whether `ancestorId` contains `nodeId` as a descendant (or equals it).
 * Used to reject drops that would move a block into its own subtree.
 *
 * @param {Record<string, Array<{ id: string, children?: any[] }>>} tree
 * @param {string} ancestorId
 * @param {string} nodeId
 * @returns {boolean}
 */
export function isSelfOrDescendant(tree, ancestorId, nodeId) {
	const ancestor = findNodeById(tree, ancestorId);
	if (!ancestor) return false;
	return containsId(ancestor.children ?? [], nodeId) || ancestor.id === nodeId;
}

/**
 * @param {Record<string, any[]>} tree
 * @param {string} id
 * @returns {{ id: string, children?: any[] } | null}
 */
function findNodeById(tree, id) {
	for (const regionId of Object.keys(tree)) {
		const hit = findInList(tree[regionId] ?? [], id);
		if (hit) return hit;
	}
	return null;
}

/**
 * @param {any[]} nodes
 * @param {string} id
 */
function findInList(nodes, id) {
	for (const node of nodes) {
		if (node.id === id) return node;
		const hit = findInList(node.children ?? [], id);
		if (hit) return hit;
	}
	return null;
}

/**
 * @param {any[]} nodes
 * @param {string} id
 * @returns {boolean}
 */
function containsId(nodes, id) {
	return nodes.some((node) => node.id === id || containsId(node.children ?? [], id));
}

/**
 * Whether a block of `type` may be placed as a child of `parentType`.
 *
 * @param {string} type
 * @param {string} parentType
 * @param {Record<string, { acceptsChildren?: boolean, allowedChildTypes?: string[] | null }>} blockTypes
 * @returns {boolean}
 */
export function parentAllowsType(type, parentType, blockTypes) {
	const schema = blockTypes[parentType];
	if (!schema || !schema.acceptsChildren) return false;
	// Mirrors state.js allowedChildTypes() and CoreBlockTypeProvider: a null or
	// empty list means "any block type" (containers ship with []), otherwise the
	// list is an explicit allowlist.
	const allowed = schema.allowedChildTypes;
	if (allowed == null || allowed.length === 0) return true;
	return allowed.includes(type);
}

/**
 * Compute the `moveBlock` arguments for dropping `draggedId` relative to
 * `targetId` in `zone`, or `null` when the drop is invalid or a no-op.
 *
 * Position math: `moveBlock` removes the dragged node first, so when the
 * destination list is the same as the source list and the source index is
 * before the destination, the destination index shifts left by one.
 *
 * @param {object} args
 * @param {Record<string, any[]>} args.tree
 * @param {Record<string, { acceptsChildren?: boolean, allowedChildTypes?: string[] | null }>} args.blockTypes
 * @param {string} args.draggedId
 * @param {string} args.targetId
 * @param {'above' | 'below' | 'inside'} args.zone
 * @returns {{ toParent: string | null, position: number } | null}
 */
export function computeDropPlan({ tree, blockTypes, draggedId, targetId, zone }) {
	if (draggedId === targetId) return null;
	if (isSelfOrDescendant(tree, draggedId, targetId)) return null;

	const source = locateNode(tree, draggedId);
	const target = locateNode(tree, targetId);
	if (!source || !target) return null;

	const dragged = source.siblings[source.index];
	const draggedType = dragged.type;

	if (zone === 'inside') {
		const targetNode = findNodeById(tree, targetId);
		const targetType = targetNode?.type ?? '';
		if (!parentAllowsType(draggedType, targetType, blockTypes)) return null;
		// Append as the last child. If the dragged node already sits among the
		// target's children, it is removed first, so the post-removal count is
		// one smaller than the current child count.
		const childCount = (targetNode?.children ?? []).length;
		const alreadyChild = source.parentId === targetId;
		return { toParent: targetId, position: alreadyChild ? childCount - 1 : childCount };
	}

	// above / below: place as a sibling of the target.
	const toParent = target.parentId;
	if (toParent === null) {
		if (draggedType !== ROOT_CONTAINER_TYPE) return null;
	} else {
		const parentNode = findNodeById(tree, toParent);
		if (!parentAllowsType(draggedType, parentNode?.type ?? '', blockTypes)) return null;
	}

	let position = target.index + (zone === 'below' ? 1 : 0);

	const samelist = source.regionId === target.regionId && source.parentId === target.parentId;
	if (samelist && source.index < position) position -= 1;
	// Dropping back into the exact same slot is a no-op.
	if (samelist && position === source.index) return null;

	return { toParent, position };
}
