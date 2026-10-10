/**
 * Pure expansion-state helpers for the Layers tree. No Svelte or DOM, so the
 * toggle, subtree and persistence rules can be unit-tested directly.
 *
 * Expansion is a Set of block ids. A row "supports children" when its block
 * type accepts children, even if it is currently empty.
 */

/**
 * @param {string} type
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @returns {boolean}
 */
export function hasChildSlot(type, blockTypes) {
	return Boolean(blockTypes[type]?.acceptsChildren);
}

/**
 * Ids of every container node in the tree (all regions, all depths).
 *
 * @param {Record<string, any[]>} tree
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @returns {string[]}
 */
export function collectContainerIds(tree, blockTypes) {
	const ids = [];
	const walk = (nodes) => {
		for (const node of nodes) {
			if (hasChildSlot(node.type, blockTypes)) ids.push(node.id);
			walk(node.children ?? []);
		}
	};
	for (const regionId of Object.keys(tree)) walk(tree[regionId] ?? []);
	return ids;
}

/**
 * Single-row toggle. Returns a new Set; the input is not mutated.
 *
 * @param {Set<string>} expanded
 * @param {string} id
 * @returns {Set<string>}
 */
export function toggleNode(expanded, id) {
	const next = new Set(expanded);
	if (next.has(id)) next.delete(id);
	else next.add(id);
	return next;
}

/**
 * Open or close a node and every container beneath it.
 *
 * @param {Set<string>} expanded
 * @param {{ id: string, type: string, children?: any[] }} node
 * @param {boolean} open
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @returns {Set<string>}
 */
export function toggleSubtree(expanded, node, open, blockTypes) {
	const next = new Set(expanded);
	const apply = (current) => {
		if (hasChildSlot(current.type, blockTypes)) {
			if (open) next.add(current.id);
			else next.delete(current.id);
		}
		for (const child of current.children ?? []) apply(child);
	};
	apply(node);
	return next;
}

/**
 * Expand (every container) or collapse (none) the whole tree.
 *
 * @param {Record<string, any[]>} tree
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @param {boolean} open
 * @returns {Set<string>}
 */
export function setAll(tree, blockTypes, open) {
	return open ? new Set(collectContainerIds(tree, blockTypes)) : new Set();
}

/**
 * True when there is at least one container and every container is open.
 * Drives the expand-all icon state (click acts as collapse when true).
 *
 * @param {Set<string>} expanded
 * @param {Record<string, any[]>} tree
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @returns {boolean}
 */
export function isAllExpanded(expanded, tree, blockTypes) {
	const ids = collectContainerIds(tree, blockTypes);
	return ids.length > 0 && ids.every((id) => expanded.has(id));
}

/**
 * Parse persisted expansion JSON. Anything that is not an array of strings is
 * ignored, so corrupt storage degrades to all-collapsed.
 *
 * @param {string | null} raw
 * @returns {Set<string>}
 */
export function parseStoredExpanded(raw) {
	if (!raw) return new Set();
	try {
		const value = JSON.parse(raw);
		if (!Array.isArray(value)) return new Set();
		return new Set(value.filter((entry) => typeof entry === 'string'));
	} catch {
		return new Set();
	}
}

/**
 * Keep only ids that still exist as containers in the tree, bounding storage
 * growth as blocks are deleted.
 *
 * @param {Set<string>} expanded
 * @param {Record<string, any[]>} tree
 * @param {Record<string, { acceptsChildren?: boolean }>} blockTypes
 * @returns {Set<string>}
 */
export function pruneExpanded(expanded, tree, blockTypes) {
	const live = new Set(collectContainerIds(tree, blockTypes));
	return new Set([...expanded].filter((id) => live.has(id)));
}
