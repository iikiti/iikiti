import { beforeEach, describe, expect, test } from 'bun:test';
import { groupSectionNodes, registerGroup, resetGroupsForTests } from './sidebarGroups.js';
import { cleanExpiredSidebarStates, readAccordionStates, sidebarStateExpiryMs, writeAccordionStates } from './sidebarPreferences.js';
import { responsiveStyleText, styleDeclarations } from './styleValues.js';

class MemoryStorage {
	values = new Map();

	get length() {
		return this.values.size;
	}

	getItem(key) {
		return this.values.get(key) ?? null;
	}

	setItem(key, value) {
		this.values.set(key, String(value));
	}

	removeItem(key) {
		this.values.delete(key);
	}

	key(index) {
		return [...this.values.keys()][index] ?? null;
	}
}

beforeEach(() => {
	resetGroupsForTests();
	globalThis.localStorage = new MemoryStorage();
});

describe('sidebar groups', () => {
	test('sorts registered groups and settings by order with stable ties', () => {
		registerGroup('style', { id: 'size', label: 'Size', order: 20 });
		registerGroup('style', { id: 'layout', label: 'Layout', order: 10 });
		const groups = groupSectionNodes('style', [
			{ kind: 'field', id: 'height', field: { key: 'height', label: 'Height', group: 'size', order: 20 } },
			{ kind: 'field', id: 'width', field: { key: 'width', label: 'Width', group: 'size', order: 10 } },
			{ kind: 'field', id: 'display', field: { key: 'display', label: 'Display', group: 'layout', order: 10 } },
			{ kind: 'field', id: 'direction', field: { key: 'direction', label: 'Direction', group: 'layout', order: 10 } },
		]);

		expect(groups.map((group) => group.id)).toEqual(['layout', 'size']);
		expect(groups[0].nodes.map((node) => node.id)).toEqual(['display', 'direction']);
		expect(groups[1].nodes.map((node) => node.id)).toEqual(['width', 'height']);
	});

	test('routes unregistered fields to General and omits empty groups', () => {
		registerGroup('style', { id: 'background', label: 'Background', order: 50 });
		const groups = groupSectionNodes('style', [{ kind: 'field', id: 'custom', field: { key: 'custom', label: 'Custom', group: 'missing' } }]);

		expect(groups).toHaveLength(1);
		expect(groups[0].id).toBe('general');
		expect(groups[0].label).toBe('General');
	});

	test('places group-node children in the registered group', () => {
		registerGroup('element', { id: 'attributes', label: 'Attributes', order: 20 });
		const groups = groupSectionNodes('element', [{
			kind: 'group',
			id: 'attributes',
			nodes: [{ kind: 'repeater', id: 'attributes', order: 10 }],
		}]);

		expect(groups[0]).toMatchObject({ id: 'attributes', label: 'Attributes' });
		expect(groups[0].nodes.map((node) => node.id)).toEqual(['attributes']);
	});
});

describe('sidebar accordion preferences', () => {
	test('stores states by context, block, and section and refreshes access time', () => {
		const context = { type: 'template', id: 42 };
		writeAccordionStates(context, 'block-a', 'style', { layout: true });

		expect(readAccordionStates(context, 'block-a', 'style')).toEqual({ layout: true });
		expect(readAccordionStates(context, 'block-b', 'style')).toEqual({});
		expect(readAccordionStates(context, 'block-a', 'element')).toEqual({});
	});

	test('removes context records untouched for more than one week', () => {
		const context = { type: 'template', id: 12 };
		writeAccordionStates(context, 'block-a', 'style', { layout: false });
		const key = localStorage.key(0);
		const record = JSON.parse(localStorage.getItem(key));
		record.lastAccessedAt = Date.now() - sidebarStateExpiryMs() - 1;
		localStorage.setItem(key, JSON.stringify(record));

		cleanExpiredSidebarStates();

		expect(localStorage.getItem(key)).toBeNull();
	});
});

describe('style value serialization', () => {
	test('normalizes common values and rejects declaration injection', () => {
		const declarations = styleDeclarations({
			width: 240,
			fontSize: '24',
			color: '#123456',
			boxShadow: '0 1px 2px rgba(0, 0, 0, 0.12)',
			backgroundColor: 'red;position:fixed',
		}, 'text', [
			{ key: 'width' }, { key: 'fontSize' }, { key: 'color' }, { key: 'boxShadow' }, { key: 'backgroundColor' },
		]);

		expect(declarations).toEqual([
			['width', '240px'],
			['font-size', '24px'],
			['color', '#123456'],
			['box-shadow', '0 1px 2px rgba(0, 0, 0, 0.12)'],
		]);
	});

	test('builds responsive rules with safe block selectors', () => {
		const css = responsiveStyleText(
			{ id: 'block"<one', type: 'heading', style: { base: { width: 320 }, md: { width: '80%' } } },
			{ md: 768 },
			[{ key: 'width' }],
		);

		expect(css).toContain('width:320px');
		expect(css).toContain('@media (min-width:768px)');
		expect(css).toContain('width:80%');
		expect(css).not.toContain('<one');
	});
});
