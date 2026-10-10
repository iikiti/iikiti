/**
 * Pure helpers for copy / paste and block ID handling in the editor. Kept free
 * of Svelte and DOM so the rules can be unit-tested directly.
 *
 * ID model: editor-generated IDs carry the `GENERATED_ID_PREFIX`. Any other ID
 * was set by the user and is "user-defined". Pasting keeps user-defined IDs
 * (duplicates are flagged in the Layers menu instead), but a generated ID is
 * replaced with a fresh one so generated IDs stay unique.
 */

/** Prefix used for every editor-generated block id (see state.js addBlock). */
export const GENERATED_ID_PREFIX = 'blk_';

/** Only this block type may sit at the root of a region (mirrors state.js). */
const ROOT_CONTAINER_TYPE = 'container';

/**
 * @param {string} id
 * @returns {boolean} true when the id was produced by the editor, not the user
 */
export function isGeneratedId(id) {
	return typeof id === 'string' && id.startsWith(GENERATED_ID_PREFIX);
}

/**
 * Deep-copy a node for pasting. User-defined ids are kept as-is; generated ids
 * are replaced via `makeId` so the copy never shares a generated id.
 *
 * @template T
 * @param {T & { id: string, children?: any[] }} node
 * @param {() => string} makeId generator for fresh generated ids
 * @returns {T & { id: string, children?: any[] }}
 */
export function cloneForPaste(node, makeId) {
	const copy = structuredClone(node);
	const refresh = (/** @type {any} */ n) => {
		if (isGeneratedId(n.id)) n.id = makeId();
		if (Array.isArray(n.children)) n.children.forEach(refresh);
	};
	refresh(copy);
	return copy;
}

/**
 * Collect every user-defined id that occurs more than once across all regions.
 * Generated ids are excluded: they are unique by construction and never warn.
 *
 * @param {Record<string, Array<{ id: string, children?: any[] }>>} tree
 * @returns {Set<string>}
 */
export function duplicateUserIds(tree) {
	/** @type {Map<string, number>} */
	const counts = new Map();
	const visit = (/** @type {any[]} */ nodes) => {
		for (const n of nodes ?? []) {
			if (n.id && !isGeneratedId(n.id)) counts.set(n.id, (counts.get(n.id) ?? 0) + 1);
			visit(n.children ?? []);
		}
	};
	for (const regionId of Object.keys(tree)) visit(tree[regionId] ?? []);
	return new Set([...counts].filter(([, count]) => count > 1).map(([id]) => id));
}

/**
 * Decide where a paste lands.
 *
 * - Selected container: last child of the selection.
 * - Selected non-container: next sibling after the selection.
 * - Nothing selected: last top-level child of the active region root.
 *
 * Returns `null` when no valid target exists (e.g. nothing selected and the
 * region has no root container to host a pasted block, or a root-level paste
 * of a non-container).
 *
 * @param {object} args
 * @param {string | null} args.selectedId
 * @param {Record<string, Array<{ id: string, type: string, children?: any[] }>>} args.tree
 * @param {string} args.regionId active region
 * @param {Record<string, { acceptsChildren?: boolean }>} args.blockTypes
 * @param {string} args.pastedType type of the block being pasted
 * @returns {{ regionId: string, parentId: string | null, position: number } | null}
 */
export function pasteTarget({ selectedId, tree, regionId, blockTypes, pastedType }) {
	if (selectedId) {
		const found = locate(tree, selectedId);
		if (found) {
			const selectedNode = found.siblings[found.index];
			if (blockTypes[selectedNode.type]?.acceptsChildren) {
				// Container: paste as its last child, if the type can live inside it.
				return { regionId: found.regionId, parentId: selectedNode.id, position: (selectedNode.children ?? []).length };
			}
			// Non-container: sibling right after the selection.
			const siblingParent = found.parentId;
			if (siblingParent === null && pastedType !== ROOT_CONTAINER_TYPE) return null;
			return { regionId: found.regionId, parentId: siblingParent, position: found.index + 1 };
		}
	}

	// Nothing selected: append to the active region's root. Root holds containers only.
	if (pastedType !== ROOT_CONTAINER_TYPE) return null;
	return { regionId, parentId: null, position: (tree[regionId] ?? []).length };
}

/**
 * @param {Record<string, any[]>} tree
 * @param {string} id
 * @returns {{ regionId: string, parentId: string | null, index: number, siblings: any[] } | null}
 */
function locate(tree, id) {
	for (const regionId of Object.keys(tree)) {
		const hit = locateIn(tree[regionId] ?? [], id, regionId, null);
		if (hit) return hit;
	}
	return null;
}

/**
 * @param {any[]} nodes
 * @param {string} id
 * @param {string} regionId
 * @param {string | null} parentId
 */
function locateIn(nodes, id, regionId, parentId) {
	for (let index = 0; index < nodes.length; index++) {
		const node = nodes[index];
		if (node.id === id) return { regionId, parentId, index, siblings: nodes };
		const nested = locateIn(node.children ?? [], id, regionId, node.id);
		if (nested) return nested;
	}
	return null;
}

/**
 * Where the header "Add block" button should insert, given the current selection.
 *
 * - Selected container: offer that container's allowed child types, appended as its last child.
 * - Selected non-container: insert as the next sibling, offering the same palette the
 *   canvas "+" after it would (the parent's allowed child types).
 * - Nothing selected (or the selection is missing): the active region root, containers only.
 *
 * @param {object} args
 * @param {string | null} args.selectedId
 * @param {Record<string, Array<{ id: string, type: string, children?: any[] }>>} args.tree
 * @param {string} args.regionId active region
 * @param {Record<string, { acceptsChildren?: boolean }>} args.blockTypes
 * @returns {{ regionId: string, parentId: string | null, position: number, parentType: string | null } | null}
 */
export function addBlockTarget({ selectedId, tree, regionId, blockTypes }) {
	const root = { regionId, parentId: null, position: (tree[regionId] ?? []).length, parentType: null };
	if (!selectedId) return root;

	const found = locate(tree, selectedId);
	if (!found) return root;
	const selectedNode = found.siblings[found.index];

	if (blockTypes[selectedNode.type]?.acceptsChildren) {
		return {
			regionId: found.regionId,
			parentId: selectedNode.id,
			position: (selectedNode.children ?? []).length,
			parentType: selectedNode.type,
		};
	}

	// Non-container: sibling after the selection, inside the selection's own parent.
	if (found.parentId === null) return root;
	const parent = findById(tree, found.parentId);
	return {
		regionId: found.regionId,
		parentId: found.parentId,
		position: found.index + 1,
		parentType: parent?.type ?? null,
	};
}

/**
 * @param {Record<string, any[]>} tree
 * @param {string} id
 * @returns {any | null}
 */
function findById(tree, id) {
	const found = locate(tree, id);
	return found ? found.siblings[found.index] : null;
}
