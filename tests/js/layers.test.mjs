import { test, expect, beforeEach, afterEach } from 'bun:test';
import { layerValue, LAYER_TOKENS } from '../../assets/js/iikiti/chrome/layers.js';

let originalWindow;
beforeEach(() => {
	originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
	delete globalThis.window;
});
afterEach(() => {
	if (originalWindow) Object.defineProperty(globalThis, 'window', originalWindow);
	else delete globalThis.window;
});

test('layerValue returns fallback when window/document unavailable', () => {
	// In bun test environment, window is undefined, so fallbacks are used.
	expect(layerValue('content')).toBe(0);
	expect(layerValue('modal')).toBe(3500);
	expect(layerValue('tour')).toBe(3900);
});

test('LAYER_TOKENS maps layer names to CSS custom property names', () => {
	expect(LAYER_TOKENS.content).toBe('--iikiti-z-content');
	expect(LAYER_TOKENS.modal).toBe('--iikiti-z-modal');
	expect(LAYER_TOKENS.toast).toBe('--iikiti-z-toast');
	expect(layerValue('toast')).toBe(4500);
	expect(layerValue('editorPanel')).toBe(300);
});
