import { test, expect } from 'bun:test';
import { resolveCondition } from '../../assets/js/iikiti/loader.js';

test('boolean conditions resolve directly', async () => {
	expect(await resolveCondition(true)).toBe(true);
	expect(await resolveCondition(false)).toBe(false);
});

test('function conditions may be sync or async', async () => {
	expect(await resolveCondition(() => true)).toBe(true);
	expect(await resolveCondition(async () => false)).toBe(false);
});

test('string expressions are evaluated and coerced to boolean', async () => {
	globalThis.__probe = 2;
	expect(await resolveCondition('__probe > 1')).toBe(true);
	expect(await resolveCondition('__probe > 5')).toBe(false);
	expect(await resolveCondition('typeof Temporal === "undefined"')).toBe(typeof Temporal === 'undefined');
});

test('unsupported condition types are rejected', async () => {
	await expect(resolveCondition(42)).rejects.toThrow(TypeError);
});
