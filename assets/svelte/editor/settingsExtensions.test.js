import { beforeEach, describe, expect, test } from 'bun:test';
import {
	buildSettingsSectionNodes,
	getSettingsSections,
	patchSettingsSection,
	registerSettingsSection,
	resetSettingsSectionsForTests,
} from './settingsExtensions.js';

beforeEach(() => resetSettingsSectionsForTests());

describe('settings section extensions', () => {
	test('registers General and Advanced separately by target context', () => {
		registerSettingsSection({ id: 'general', label: 'General', order: 10, build: () => [] });
		registerSettingsSection({ id: 'advanced', label: 'Advanced', order: 20, targetTypes: ['template'], build: () => [] });

		expect(getSettingsSections('page').map((section) => section.id)).toEqual(['general']);
		expect(getSettingsSections('template').map((section) => section.id)).toEqual(['general', 'advanced']);
	});

	test('replaces only the matching target when a plugin overrides a core tab', () => {
		registerSettingsSection({ id: 'general', label: 'General', order: 10, build: () => [] });
		registerSettingsSection({ id: 'general', label: 'Page General', order: 5, targetTypes: ['page'], build: () => [] });

		expect(getSettingsSections('page')).toEqual([{ id: 'general', label: 'Page General', icon: undefined, order: 5 }]);
		expect(getSettingsSections('template')).toEqual([{ id: 'general', label: 'General', icon: undefined, order: 10 }]);
	});

	test('builds plugin nodes and applies ordered patches only to matching target types', () => {
		registerSettingsSection({
			id: 'general',
			label: 'General',
			build: ({ targetType }) => [{ kind: 'field', id: targetType, field: { key: 'title', type: 'text' } }],
		});
		patchSettingsSection('general', (nodes) => [...nodes, { kind: 'field', id: 'page-plugin' }], { targetTypes: ['page'] });
		patchSettingsSection('general', (nodes) => nodes.map((node, index) => index === 0 ? { ...node, id: 'patched' } : node), { priority: 10 });

		const context = { targetType: 'page', targetId: 9, values: {} };
		expect(buildSettingsSectionNodes('page', 'general', context).map((node) => node.id)).toEqual(['patched', 'page-plugin']);
		expect(buildSettingsSectionNodes('template', 'general', { targetType: 'template' }).map((node) => node.id)).toEqual(['patched']);
	});

	test('ignores invalid target registrations and contains plugin builder errors', () => {
		registerSettingsSection({ id: 'broken', label: 'Broken', targetTypes: ['site'], build: () => [] });
		registerSettingsSection({ id: 'general', label: 'General', build: () => { throw new Error('plugin failure'); } });
		registerSettingsSection({ id: 'advanced', label: 'Advanced', build: () => ({ invalid: true }) });

		expect(getSettingsSections('page').map((section) => section.id)).toEqual(['general', 'advanced']);
		expect(buildSettingsSectionNodes('page', 'general', {})).toEqual([]);
		expect(buildSettingsSectionNodes('page', 'advanced', {})).toEqual([]);
	});
});
