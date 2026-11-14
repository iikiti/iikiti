import { test, expect } from 'bun:test';
import { createFragmentsFacade } from '../../assets/js/iikiti/fragmentFacade.js';

test('createFragmentsFacade returns load and mount functions', () => {
	const facade = createFragmentsFacade();
	expect(typeof facade.load).toBe('function');
	expect(typeof facade.mount).toBe('function');
});

test('facade load delegates to the implementation import', async () => {
	let imported = false;
	const facade = createFragmentsFacade(async () => {
		imported = true;
		return {
			load: async (provider, options) => ({ provider, options, loaded: true }),
			mount: async (root) => ({ root, mounted: true }),
		};
	});

	const result = await facade.load('test-provider', { params: { a: 1 } });
	expect(imported).toBe(true);
	expect(result).toEqual({ provider: 'test-provider', options: { params: { a: 1 } }, loaded: true });
});

test('facade mount delegates to the implementation import', async () => {
	const facade = createFragmentsFacade(async () => ({
		load: async () => ({ loaded: true }),
		mount: async (root) => ({ root, mounted: true }),
	}));

	const root = { fake: true };
	const result = await facade.mount(root);
	expect(result).toEqual({ root, mounted: true });
});

test('facade load propagates implementation errors', async () => {
	const facade = createFragmentsFacade(async () => {
		throw new Error('implementation failed');
	});
	await expect(facade.load('x')).rejects.toThrow('implementation failed');
});
