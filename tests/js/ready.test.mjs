import { test, expect, beforeEach, afterEach } from 'bun:test';
import {
	markReady,
	whenReady,
	__resetReadyForTests,
	IIKITI_READY,
	BASE_READY_SET,
} from '../../assets/js/iikiti/ready.js';

beforeEach(() => {
	__resetReadyForTests();
});

test('whenReady resolves immediately if already ready', async () => {
	markReady('foo');
	await expect(whenReady('foo')).resolves.toBeUndefined();
});

test('whenReady resolves after markReady is called later', async () => {
	const promise = whenReady('bar');
	setTimeout(() => markReady('bar'), 10);
	await expect(promise).resolves.toBeUndefined();
});

test('whenReady stays pending until the name becomes ready', async () => {
	let resolved = false;
	const promise = whenReady('not-yet-ready').then(() => { resolved = true; });
	await Promise.resolve();
	expect(resolved).toBe(false);
	markReady('not-yet-ready');
	await promise;
	expect(resolved).toBe(true);
});

test('markReady is idempotent', () => {
	markReady('baz');
	markReady('baz'); // should not throw
	markReady('baz');
});

test('IIKITI_READY resolves when all base set names are ready', async () => {
	const ready = whenReady(IIKITI_READY);
	for (const name of BASE_READY_SET) markReady(name);
	await expect(ready).resolves.toBeUndefined();
});

test('__resetReadyForTests clears all state', async () => {
	markReady('foo');
	__resetReadyForTests();
	let resolved = false;
	const promise = whenReady('foo').then(() => { resolved = true; });
	await Promise.resolve();
	expect(resolved).toBe(false);
	markReady('foo');
	await promise;
	expect(resolved).toBe(true);
});
