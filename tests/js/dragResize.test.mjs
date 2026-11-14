import { test, expect, beforeEach, afterEach } from 'bun:test';
import {
	readPersistedSize,
	writePersistedSize,
	contentEdgeForSide,
} from '../../assets/js/iikiti/chrome/dragResize.js';

let originalLocalStorage;

beforeEach(() => {
	originalLocalStorage = globalThis.localStorage;
	const store = {};
	globalThis.localStorage = {
		getItem: (k) => (k in store ? String(store[k]) : null),
		setItem: (k, v) => {
			store[k] = String(v);
		},
		removeItem: (k) => {
			delete store[k];
		},
		clear: () => {
			Object.keys(store).forEach((k) => delete store[k]);
		},
	};
});

afterEach(() => {
	globalThis.localStorage = originalLocalStorage;
});

test('readPersistedSize returns null for missing key', () => {
	expect(readPersistedSize('nonexistent')).toBeNull();
});

test('readPersistedSize returns null for invalid JSON', () => {
	globalThis.localStorage.setItem('iikiti.foo.size', 'not-json');
	expect(readPersistedSize('foo.size')).toBeNull();
});

test('readPersistedSize returns null for non-object value', () => {
	globalThis.localStorage.setItem('iikiti.foo.size', '42');
	expect(readPersistedSize('foo.size')).toBeNull();
});

test('writePersistedSize then readPersistedSize round-trips', () => {
	writePersistedSize('bar.p1.size', { w: 200, h: 100 });
	const read = readPersistedSize('bar.p1.size');
	expect(read).toEqual({ w: 200, h: 100 });
});

test('readPersistedSize clamps non-finite values', () => {
	globalThis.localStorage.setItem('iikiti.foo.size', JSON.stringify({ w: 'Infinity', h: 'not-a-number' }));
	expect(readPersistedSize('foo.size')).toEqual({ w: 0, h: 0 });
});

test('contentEdgeForSide top returns bottom', () => {
	expect(contentEdgeForSide('top')).toBe('bottom');
});

test('contentEdgeForSide bottom returns top', () => {
	expect(contentEdgeForSide('bottom')).toBe('top');
});

test('contentEdgeForSide left returns right', () => {
	expect(contentEdgeForSide('left')).toBe('right');
});

test('contentEdgeForSide right defaults to left', () => {
	expect(contentEdgeForSide('right')).toBe('left');
});
