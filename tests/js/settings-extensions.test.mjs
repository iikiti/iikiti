import { afterEach, test, expect } from 'bun:test';
import {
	buildSettingsSectionNodes,
	getSettingsSections,
	patchSettingsSection,
	registerSettingsSection,
	resetSettingsSectionsForTests,
} from '../../assets/svelte/editor/settingsExtensions.js';

afterEach(() => resetSettingsSectionsForTests());

test('registers settings sections for site_home without exposing them to shared templates', () => {
	registerSettingsSection({
		id: 'seo',
		label: 'SEO',
		targetTypes: ['page', 'site_home'],
		build: () => [{ id: 'seo-field' }],
	});

	expect(getSettingsSections('page').map((section) => section.id)).toEqual(['seo']);
	expect(getSettingsSections('site_home').map((section) => section.id)).toEqual(['seo']);
	expect(getSettingsSections('template')).toEqual([]);

	registerSettingsSection({ id: 'invalid', label: 'Invalid', targetTypes: ['site'], build: () => [] });
	expect(getSettingsSections('site_home').map((section) => section.id)).toEqual(['seo']);
});

test('applies site_home section patches only to that target', () => {
	registerSettingsSection({ id: 'seo', label: 'SEO', targetTypes: ['site_home'], build: () => [{ id: 'base' }] });
	patchSettingsSection('seo', (nodes) => [...nodes, { id: 'homepage' }], { targetTypes: ['site_home'] });

	expect(buildSettingsSectionNodes('site_home', 'seo', {})).toEqual([{ id: 'base' }, { id: 'homepage' }]);
	expect(buildSettingsSectionNodes('page', 'seo', {})).toEqual([]);
});
