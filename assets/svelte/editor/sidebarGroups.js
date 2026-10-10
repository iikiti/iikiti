const groupRegistry = new Map();
let groupSeq = 0;

function compareOrdered(a, b) {
	return a.order - b.order || a.seq - b.seq;
}

function normalizedOrder(value, fallback) {
	const order = Number(value);
	return Number.isFinite(order) ? order : fallback;
}

export function registerGroup(sectionId, group) {
	if (typeof sectionId !== 'string' || !sectionId || typeof group?.id !== 'string' || !group.id) return;
	let groups = groupRegistry.get(sectionId);
	if (!groups) {
		groups = [];
		groupRegistry.set(sectionId, groups);
	}
	const existingIndex = groups.findIndex((entry) => entry.id === group.id);
	const entry = {
		id: group.id,
		label: String(group.label ?? group.id),
		order: normalizedOrder(group.order, 0),
		seq: existingIndex >= 0 ? groups[existingIndex].seq : ++groupSeq,
	};
	if (existingIndex >= 0) groups[existingIndex] = entry;
	else groups.push(entry);
	return entry;
}

export function getGroups(sectionId) {
	return [...(groupRegistry.get(sectionId) ?? [])]
		.sort(compareOrdered)
		.map(({ id, label, order }) => ({ id, label, order }));
}

export function groupSectionNodes(sectionId, nodes) {
	const definitions = new Map();
	for (const [index, group] of getGroups(sectionId).entries()) {
		definitions.set(group.id, { ...group, seq: index, nodes: [] });
	}
	let seq = 0;
	const fallback = () => {
		let group = definitions.get('general');
		if (!group) {
			group = { id: 'general', label: 'General', order: Number.MAX_SAFE_INTEGER, seq: definitions.size, nodes: [] };
			definitions.set('general', group);
		}
		return group;
	};

	for (const node of nodes ?? []) {
		const nodeSequence = seq++;
		if (node?.kind === 'group') {
			const id = String(node.group ?? node.id ?? 'general');
			let group = definitions.get(id);
			if (!group) {
				group = {
					id,
					label: String(node.header ?? node.label ?? id),
					order: normalizedOrder(node.order, nodeSequence),
					seq: nodeSequence,
					nodes: [],
				};
				definitions.set(id, group);
			}
			for (const child of Array.isArray(node.nodes) ? node.nodes : []) {
				const childSequence = seq++;
				const order = normalizedOrder(child?.field?.order ?? child?.order, childSequence);
				group.nodes.push({ ...child, order, _sidebarSequence: childSequence });
			}
			continue;
		}

		const field = node?.field;
		const requestedGroup = String(field?.group ?? node?.group ?? 'general');
		const group = definitions.get(requestedGroup) ?? fallback();
		const order = normalizedOrder(field?.order ?? node?.order, nodeSequence);
		group.nodes.push({ ...node, order, _sidebarSequence: nodeSequence });
	}

	return [...definitions.values()]
		.filter((group) => group.nodes.length > 0)
		.sort(compareOrdered)
		.map((group) => ({
			kind: 'accordion',
			id: group.id,
			label: group.label,
			order: group.order,
			nodes: group.nodes
				.sort((a, b) => normalizedOrder(a.order, 0) - normalizedOrder(b.order, 0) || a._sidebarSequence - b._sidebarSequence)
				.map(({ _sidebarSequence, ...node }) => node),
		}));
}

export function resetGroupsForTests() {
	groupRegistry.clear();
	groupSeq = 0;
}
