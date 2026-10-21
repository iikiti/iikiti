import { beforeEach, describe, expect, test } from 'bun:test';
import { get } from 'svelte/store';
import { select, selected } from './state.js';
import { setSidebarMode, sidebarMode } from './extensions.js';

beforeEach(() => {
	select(null);
	setSidebarMode('settings');
});

describe('sidebar mode on selection', () => {
	test('returns to block settings when a block is selected', () => {
		select('block-1');

		expect(get(selected)).toBe('block-1');
		expect(get(sidebarMode)).toBe('blocks');
	});

	test('keeps the settings panel open when the selection is cleared', () => {
		select(null);

		expect(get(selected)).toBeNull();
		expect(get(sidebarMode)).toBe('settings');
	});
});
