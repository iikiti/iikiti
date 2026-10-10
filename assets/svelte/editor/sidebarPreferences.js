const STORAGE_PREFIX = 'iikiti.editor.sidebar.groups.';
const EXPIRY_MS = 7 * 24 * 60 * 60 * 1000;

function storageKey(context) {
	return STORAGE_PREFIX + encodeURIComponent(`${context?.type ?? 'unknown'}:${context?.id ?? 'unknown'}`);
}

function readRecord(key) {
	try {
		const raw = localStorage.getItem(key);
		if (!raw) return { blocks: {} };
		const record = JSON.parse(raw);
		if (!record || typeof record !== 'object' || !record.blocks || typeof record.blocks !== 'object') {
			return { blocks: {} };
		}
		return record;
	} catch {
		return { blocks: {} };
	}
}

function writeRecord(key, record) {
	try {
		localStorage.setItem(key, JSON.stringify({ ...record, lastAccessedAt: Date.now() }));
		return true;
	} catch {
		return false;
	}
}

export function cleanExpiredSidebarStates(now = Date.now()) {
	try {
		for (let index = localStorage.length - 1; index >= 0; index--) {
			const key = localStorage.key(index);
			if (!key?.startsWith(STORAGE_PREFIX)) continue;
			const record = readRecord(key);
			if (!Number.isFinite(record.lastAccessedAt) || now - record.lastAccessedAt > EXPIRY_MS) {
				localStorage.removeItem(key);
			}
		}
	} catch {
		return;
	}
}

export function readAccordionStates(context, blockId, sectionId) {
	const key = storageKey(context);
	const record = readRecord(key);
	const block = record.blocks?.[blockId];
	const states = block?.[sectionId];
	writeRecord(key, record);
	return states && typeof states === 'object' ? { ...states } : {};
}

export function writeAccordionStates(context, blockId, sectionId, states) {
	const key = storageKey(context);
	const record = readRecord(key);
	const blocks = record.blocks && typeof record.blocks === 'object' ? record.blocks : {};
	const block = blocks[blockId] && typeof blocks[blockId] === 'object' ? blocks[blockId] : {};
	const next = {
		...record,
		blocks: {
			...blocks,
			[blockId]: { ...block, [sectionId]: { ...states } },
		},
	};
	writeRecord(key, next);
}

export function sidebarStateExpiryMs() {
	return EXPIRY_MS;
}
